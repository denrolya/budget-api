<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\OwnableInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\Security;

/**
 * Restricts API Platform collection queries to the authenticated user's own resources.
 * Doctrine SQL filters do not apply to API Platform's QueryBuilder-based collection
 * pipeline, so this extension handles ownership scoping for GetCollection operations.
 */
final class OwnableQueryExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!is_a($resourceClass, OwnableInterface::class, true)) {
            return;
        }

        $user = $this->security->getUser();

        if (null === $user) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $parameterName = $queryNameGenerator->generateParameterName('owner');

        $queryBuilder
            ->andWhere(sprintf('%s.owner = :%s', $rootAlias, $parameterName))
            ->setParameter($parameterName, $user);
    }
}
