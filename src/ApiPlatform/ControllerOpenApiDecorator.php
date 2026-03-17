<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use ArrayObject;
use OpenApi\Annotations\MediaType as OAMediaType;
use OpenApi\Annotations\OpenApi as OASpec;
use OpenApi\Annotations\Operation as OAOperation;
use OpenApi\Annotations\Parameter as OAParameter;
use OpenApi\Annotations\RequestBody as OARequestBody;
use OpenApi\Annotations\Response as OAResponse;
use OpenApi\Annotations\Schema as OASchema;
use OpenApi\Generator;

/**
 * Merges OpenAPI paths from FOSRest controllers (annotated with zircote/swagger-php
 * attributes) into the API Platform-generated OpenAPI spec.
 *
 * Why a decorator instead of native API Platform resources: the v2 controllers are
 * intentionally kept outside the API Platform resource model (FOSRest handles routing,
 * serialization and query-param binding for them).
 */
final class ControllerOpenApiDecorator implements OpenApiFactoryInterface
{
    private const SCAN_PATHS = [
        __DIR__ . '/../Controller',
    ];

    /** Tags for v2 FOSRest controller groups, in display order. */
    private const CONTROLLER_TAGS = [
        ['name' => 'Account',        'description' => 'Account balance history and daily statistics'],
        ['name' => 'Auth',           'description' => 'Token refresh'],
        ['name' => 'Budget',         'description' => 'Budget planning analytics'],
        ['name' => 'Debt',           'description' => 'Debt tracking'],
        ['name' => 'Exchange Rates', 'description' => 'Exchange rate snapshots and provider integrations'],
        ['name' => 'Ledger',         'description' => 'Unified ledger (transactions + transfers merged)'],
        ['name' => 'Statistics',     'description' => 'Aggregated financial statistics'],
        ['name' => 'Transaction',    'description' => 'Transaction list and CSV export'],
        ['name' => 'Webhooks',       'description' => 'Incoming bank webhook receiver'],
    ];

    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $controllerSpec = @Generator::scan(self::SCAN_PATHS);

        if (!$controllerSpec instanceof OASpec || Generator::isDefault($controllerSpec->paths)) {
            return $openApi;
        }

        $paths = $openApi->getPaths();

        foreach ($controllerSpec->paths as $controllerPath) {
            if (Generator::isDefault($controllerPath->path)) {
                continue;
            }

            $paths->addPath($controllerPath->path, new PathItem(
                ref: null,
                summary: null,
                description: null,
                get: $this->convertAnnotationOperation($controllerPath->get),
                put: $this->convertAnnotationOperation($controllerPath->put),
                post: $this->convertAnnotationOperation($controllerPath->post),
                delete: $this->convertAnnotationOperation($controllerPath->delete),
                options: null,
                head: null,
                patch: null,
                trace: null,
                servers: null,
                parameters: [],
            ));
        }

        $openApi = $openApi->withPaths($paths);

        return $this->appendControllerTags($openApi);
    }

    private function appendControllerTags(OpenApi $openApi): OpenApi
    {
        $existingTags = $openApi->getTags();
        $existingTagNames = array_map(
            static fn (mixed $tag): string => \is_array($tag) ? (string) ($tag['name'] ?? '') : (\is_string($tag) ? $tag : ''),
            $existingTags,
        );

        $newTags = $existingTags;
        foreach (self::CONTROLLER_TAGS as $tagDefinition) {
            if (\in_array($tagDefinition['name'], $existingTagNames, strict: true)) {
                continue;
            }
            $newTags[] = $tagDefinition;
        }

        return $openApi->withTags($newTags);
    }

    /**
     * Converts a zircote/swagger-php Operation annotation to an API Platform Operation model.
     * The property value may be the actual annotation object or Generator::UNDEFINED.
     */
    private function convertAnnotationOperation(OAOperation|string $operationOrUndefined): ?Operation
    {
        if (!$operationOrUndefined instanceof OAOperation) {
            return null;
        }

        $parameters = $this->extractParameters($operationOrUndefined);
        $responsesArray = $this->extractResponses($operationOrUndefined);

        $security = $this->extractArrayField($operationOrUndefined->security);
        $tags = $this->extractArrayField($operationOrUndefined->tags);

        $requestBody = Generator::isDefault($operationOrUndefined->requestBody)
            ? null
            : $this->convertAnnotationRequestBody($operationOrUndefined->requestBody);

        return new Operation(
            operationId: null,
            tags: $tags,
            responses: $responsesArray,
            summary: $this->extractStringField($operationOrUndefined->summary),
            description: $this->extractStringField($operationOrUndefined->description),
            externalDocs: null,
            parameters: $parameters,
            requestBody: $requestBody,
            callbacks: null,
            deprecated: false,
            security: $security,
            servers: null,
        );
    }

    /**
     * @return list<Parameter>
     */
    private function extractParameters(OAOperation $operation): array
    {
        if (Generator::isDefault($operation->parameters)) {
            return [];
        }

        $parameters = [];

        /** @var OAParameter $annotationParameter */
        foreach ($operation->parameters as $annotationParameter) {
            if (Generator::isDefault($annotationParameter) || Generator::isDefault($annotationParameter->name)) {
                continue;
            }

            $schema = $this->convertAnnotationSchemaToArray(
                Generator::isDefault($annotationParameter->schema) ? null : $annotationParameter->schema,
            );

            $parameters[] = new Parameter(
                name: $annotationParameter->name,
                in: Generator::isDefault($annotationParameter->in) ? 'query' : $annotationParameter->in,
                description: $this->extractStringField($annotationParameter->description),
                required: !Generator::isDefault($annotationParameter->required) && $annotationParameter->required,
                deprecated: false,
                allowEmptyValue: false,
                schema: $schema ?? [],
            );
        }

        return $parameters;
    }

    /**
     * @return array<string, Response>
     */
    private function extractResponses(OAOperation $operation): array
    {
        if (Generator::isDefault($operation->responses)) {
            return [];
        }

        $responsesArray = [];

        /** @var OAResponse $annotationResponse */
        foreach ($operation->responses as $annotationResponse) {
            if (Generator::isDefault($annotationResponse) || Generator::isDefault($annotationResponse->response)) {
                continue;
            }

            $content = $this->extractResponseContent($annotationResponse);
            $responsesArray[(string) $annotationResponse->response] = new Response(
                description: $this->extractStringField($annotationResponse->description),
                content: $content,
            );
        }

        return $responsesArray;
    }

    /**
     * @return ArrayObject<string, MediaType>|null
     */
    private function extractResponseContent(OAResponse $annotationResponse): ?ArrayObject
    {
        if (Generator::isDefault($annotationResponse->content)) {
            return null;
        }

        $lastContent = null;

        /** @var OAMediaType $mediaType */
        foreach ($annotationResponse->content as $mediaType) {
            if (Generator::isDefault($mediaType)) {
                continue;
            }
            $schemaArray = $this->resolveMediaTypeSchema($mediaType);

            /** @var ArrayObject<string, mixed> $schemaObject */
            $schemaObject = new ArrayObject($schemaArray);

            $lastContent = new ArrayObject([
                'application/json' => new MediaType(schema: $schemaObject),
            ]);
        }

        return $lastContent;
    }

    /**
     * Converts a zircote/swagger-php Schema annotation to an associative array
     * compatible with API Platform's OpenAPI model.
     *
     * @return array<string, mixed>|null
     */
    private function convertAnnotationSchemaToArray(?OASchema $schema): ?array
    {
        if (null === $schema) {
            return null;
        }

        $result = $this->extractScalarSchemaFields($schema);

        if (!Generator::isDefault($schema->items)) {
            $result['items'] = $this->convertAnnotationSchemaToArray($schema->items);
        }

        if (!Generator::isDefault($schema->properties)) {
            $result['properties'] = $this->extractSchemaProperties($schema);
        }

        if (!Generator::isDefault($schema->additionalProperties) && $schema->additionalProperties instanceof OASchema) {
            $result['additionalProperties'] = $this->convertAnnotationSchemaToArray($schema->additionalProperties);
        }

        return $result;
    }

    /**
     * Extracts scalar fields (type, format, default, enum, minimum, example) from a Schema annotation.
     *
     * @return array<string, mixed>
     */
    private function extractScalarSchemaFields(OASchema $schema): array
    {
        $result = [];

        if (!Generator::isDefault($schema->type)) {
            $result['type'] = $schema->type;
        }
        if (!Generator::isDefault($schema->format)) {
            $result['format'] = $schema->format;
        }
        if (!Generator::isDefault($schema->default)) {
            $result['default'] = $schema->default;
        }
        if (!Generator::isDefault($schema->enum)) {
            $result['enum'] = $schema->enum;
        }
        if (!Generator::isDefault($schema->minimum)) {
            $result['minimum'] = $schema->minimum;
        }
        if (!Generator::isDefault($schema->example)) {
            $result['example'] = $schema->example;
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function extractSchemaProperties(OASchema $schema): array
    {
        $properties = [];

        foreach ($schema->properties as $property) {
            if (Generator::isDefault($property) || Generator::isDefault($property->property)) {
                continue;
            }
            $properties[$property->property] = $this->convertAnnotationSchemaToArray($property) ?? [];
        }

        return $properties;
    }

    private function convertAnnotationRequestBody(OARequestBody|string $requestBodyOrUndefined): ?RequestBody
    {
        if (!$requestBodyOrUndefined instanceof OARequestBody) {
            return null;
        }

        /** @var ArrayObject<string, MediaType> $content */
        $content = new ArrayObject();

        if (!Generator::isDefault($requestBodyOrUndefined->content)) {
            /** @var OAMediaType $mediaType */
            foreach ($requestBodyOrUndefined->content as $mediaType) {
                if (Generator::isDefault($mediaType)) {
                    continue;
                }
                $schemaArray = $this->resolveMediaTypeSchema($mediaType);

                /** @var ArrayObject<string, mixed> $schemaObject */
                $schemaObject = new ArrayObject($schemaArray);

                $content['application/json'] = new MediaType(schema: $schemaObject);
            }
        }

        return new RequestBody(
            description: $this->extractStringField($requestBodyOrUndefined->description),
            content: $content,
            required: !Generator::isDefault($requestBodyOrUndefined->required) && $requestBodyOrUndefined->required,
        );
    }

    /**
     * Extracts the schema array from an OAMediaType annotation.
     *
     * @return array<string, mixed>
     */
    private function resolveMediaTypeSchema(OAMediaType $mediaType): array
    {
        if (Generator::isDefault($mediaType->schema)) {
            return [];
        }

        return $this->convertAnnotationSchemaToArray($mediaType->schema) ?? [];
    }

    /**
     * Extracts a string field from an annotation property, returning an empty
     * string when the value is Generator::UNDEFINED.
     */
    private function extractStringField(string $value): string
    {
        return Generator::isDefault($value) ? '' : $value;
    }

    /**
     * Extracts an array field from an annotation property, returning an empty
     * array when the value is Generator::UNDEFINED.
     *
     * @return array<mixed>
     */
    private function extractArrayField(mixed $value): array
    {
        if (Generator::isDefault($value)) {
            return [];
        }

        assert(\is_array($value));

        return $value;
    }
}
