<?php

namespace App\DataTransformer;

use ApiPlatform\Core\DataTransformer\DataTransformerInterface;
use App\DTO\TagOutput;
use App\Entity\CategoryTag;
use JetBrains\PhpStorm\Pure;

final class TagOutputDataTransformer implements DataTransformerInterface
{
    /**
     * {@inheritdoc}
     */
    #[Pure]
    public function transform($data, string $to, array $context = []): TagOutput
    {
        return new TagOutput($data->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTransformation($data, string $to, array $context = []): bool
    {
        return TagOutput::class === $to && $data instanceof CategoryTag;
    }
}
