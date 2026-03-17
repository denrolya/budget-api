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
use OpenApi\Annotations\OpenApi as OASpec;
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

        /** @var OASpec|null $controllerSpec */
        $controllerSpec = @Generator::scan(self::SCAN_PATHS);

        if (!$controllerSpec instanceof OASpec || !is_array($controllerSpec->paths)) {
            return $openApi;
        }

        $paths = $openApi->getPaths();

        foreach ($controllerSpec->paths as $controllerPath) {
            if (!isset($controllerPath->path) || $controllerPath->path === Generator::UNDEFINED) {
                continue;
            }

            $paths->addPath($controllerPath->path, new PathItem(
                ref: null,
                summary: null,
                description: null,
                get: $this->convertOperation($controllerPath->get ?? null),
                put: $this->convertOperation($controllerPath->put ?? null),
                post: $this->convertOperation($controllerPath->post ?? null),
                delete: $this->convertOperation($controllerPath->delete ?? null),
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
            static fn(mixed $tag): string => is_array($tag) ? (string) ($tag['name'] ?? '') : (string) $tag,
            $existingTags,
        );

        $newTags = $existingTags;
        foreach (self::CONTROLLER_TAGS as $tagDefinition) {
            if (in_array($tagDefinition['name'], $existingTagNames, strict: true)) {
                continue;
            }
            $newTags[] = $tagDefinition;
        }

        return $openApi->withTags($newTags);
    }

    private function convertOperation(mixed $operation): ?Operation
    {
        if ($operation === null || $operation === Generator::UNDEFINED) {
            return null;
        }

        $parameters = [];
        foreach ((array) ($operation->parameters ?? []) as $param) {
            if ($param === Generator::UNDEFINED || !isset($param->name)) {
                continue;
            }

            $schema = $this->schemaToArray($param->schema ?? null);

            $parameters[] = new Parameter(
                name: $param->name,
                in: $param->in ?? 'query',
                description: (isset($param->description) && $param->description !== Generator::UNDEFINED) ? (string) $param->description : '',
                required: (isset($param->required) && $param->required !== Generator::UNDEFINED) ? (bool) $param->required : false,
                deprecated: false,
                allowEmptyValue: false,
                schema: $schema,
            );
        }

        $responses = new ArrayObject();
        foreach ((array) ($operation->responses ?? []) as $response) {
            if ($response === Generator::UNDEFINED || !isset($response->response)) {
                continue;
            }

            $content = null;
            foreach ((array) ($response->content ?? []) as $mediaType) {
                if ($mediaType === Generator::UNDEFINED) {
                    continue;
                }
                $content = new ArrayObject([
                    'application/json' => new MediaType(
                        schema: new ArrayObject($this->schemaToArray($mediaType->schema ?? null) ?? [])
                    ),
                ]);
            }

            $responses[(string) $response->response] = new Response(
                description: (isset($response->description) && $response->description !== Generator::UNDEFINED) ? (string) $response->description : '',
                content: $content,
            );
        }

        $security = (isset($operation->security) && $operation->security !== Generator::UNDEFINED && is_array($operation->security))
            ? $operation->security
            : [];

        $tags = (isset($operation->tags) && $operation->tags !== Generator::UNDEFINED && is_array($operation->tags))
            ? $operation->tags
            : [];

        return new Operation(
            operationId: null,
            tags: $tags,
            responses: iterator_to_array($responses),
            summary: (isset($operation->summary) && $operation->summary !== Generator::UNDEFINED) ? (string) $operation->summary : '',
            description: (isset($operation->description) && $operation->description !== Generator::UNDEFINED) ? (string) $operation->description : '',
            externalDocs: null,
            parameters: $parameters,
            requestBody: $this->convertRequestBody($operation->requestBody ?? null),
            callbacks: null,
            deprecated: false,
            security: $security,
            servers: null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function schemaToArray(mixed $schema): ?array
    {
        if ($schema === null || $schema === Generator::UNDEFINED) {
            return null;
        }

        $result = [];

        foreach (['type', 'format', 'default', 'enum', 'minimum', 'example'] as $field) {
            if (isset($schema->{$field}) && $schema->{$field} !== Generator::UNDEFINED) {
                $result[$field] = $schema->{$field};
            }
        }

        if (isset($schema->items) && $schema->items !== Generator::UNDEFINED) {
            $result['items'] = $this->schemaToArray($schema->items);
        }

        if (isset($schema->properties) && $schema->properties !== Generator::UNDEFINED && is_array($schema->properties)) {
            $props = [];
            foreach ($schema->properties as $prop) {
                if ($prop === Generator::UNDEFINED || !isset($prop->property)) {
                    continue;
                }
                $props[$prop->property] = $this->schemaToArray($prop) ?? [];
            }
            $result['properties'] = $props;
        }

        if (isset($schema->additionalProperties) && $schema->additionalProperties !== Generator::UNDEFINED) {
            $result['additionalProperties'] = $this->schemaToArray($schema->additionalProperties);
        }

        return $result;
    }

    private function convertRequestBody(mixed $requestBody): ?RequestBody
    {
        if ($requestBody === null || $requestBody === Generator::UNDEFINED) {
            return null;
        }

        $content = new ArrayObject();
        foreach ((array) ($requestBody->content ?? []) as $mediaType) {
            if ($mediaType === Generator::UNDEFINED) {
                continue;
            }
            $content['application/json'] = new MediaType(
                schema: new ArrayObject($this->schemaToArray($mediaType->schema ?? null) ?? [])
            );
        }

        return new RequestBody(
            description: (isset($requestBody->description) && $requestBody->description !== Generator::UNDEFINED) ? (string) $requestBody->description : '',
            content: $content,
            required: (isset($requestBody->required) && $requestBody->required !== Generator::UNDEFINED) ? (bool) $requestBody->required : false,
        );
    }
}
