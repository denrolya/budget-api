<?php

namespace App\Serializer;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\ResourceAccessCheckerInterface;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ApiPlatform\Serializer\ItemNormalizer;
use ApiPlatform\Serializer\TagCollectorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * Extends AP's ItemNormalizer to accept plain integer IDs for relation fields.
 * e.g. {"account": 5} instead of {"account": "/api/accounts/5"}
 */
class PlainIdItemNormalizer extends ItemNormalizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory,
        PropertyMetadataFactoryInterface $propertyMetadataFactory,
        IriConverterInterface $iriConverter,
        ResourceClassResolverInterface $resourceClassResolver,
        ?PropertyAccessorInterface $propertyAccessor = null,
        ?NameConverterInterface $nameConverter = null,
        ?ClassMetadataFactoryInterface $classMetadataFactory = null,
        ?LoggerInterface $logger = null,
        ?ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory = null,
        ?ResourceAccessCheckerInterface $resourceAccessChecker = null,
        array $defaultContext = [],
        ?TagCollectorInterface $tagCollector = null,
    ) {
        parent::__construct(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $iriConverter,
            $resourceClassResolver,
            $propertyAccessor,
            $nameConverter,
            $classMetadataFactory,
            $logger,
            $resourceMetadataFactory,
            $resourceAccessChecker,
            $defaultContext,
            $tagCollector,
        );
    }

    protected function denormalizeRelation(string $attributeName, ApiProperty $propertyMetadata, string $className, mixed $value, ?string $format, array $context): ?object
    {
        if (is_int($value)) {
            return $this->em->getReference($className, $value);
        }

        return parent::denormalizeRelation($attributeName, $propertyMetadata, $className, $value, $format, $context);
    }
}
