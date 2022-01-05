<?php

namespace App\OpenApi;

use ApiPlatform\Core\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\Core\OpenApi\OpenApi;
use ApiPlatform\Core\OpenApi\Model;

final class FilterPathsDecorator implements OpenApiFactoryInterface
{
    private const PATHS_TO_FILTER = [
        '/api/cash-accounts/{id}',
        '/api/internet-accounts/{id}',
        '/api/bank-card-accounts/{id}',
        '/api/income-categories/{id}',
        '/api/expense-categories/{id}',
        '/api/tags/{name}',
    ];

    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = $this->decorated->__invoke($context);

        $paths = $openApi->getPaths()->getPaths();

        $filteredPaths = new Model\Paths();
        foreach($paths as $path => $pathItem) {
            // If a prefix is configured on API Platform's routes, it must appear here.
            if(in_array($path, self::PATHS_TO_FILTER, true)) {
                continue;
            }
            $filteredPaths->addPath($path, $pathItem);
        }

        return $openApi->withPaths($filteredPaths);
    }
}
