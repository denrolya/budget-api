<?php

namespace App\DataTransformer;

use ApiPlatform\Core\DataTransformer\DataTransformerInterface;
use App\Entity\CategoryTag;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;

final class TagInputDataTransformer implements DataTransformerInterface
{

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * {@inheritdoc}
     */
    #[Pure]
    public function transform($data, string $to, array $context = []): object
    {
        if(!$tag = $this->em->getRepository(CategoryTag::class)->findOneBy(['name' => $data->name])) {
            $tag = new CategoryTag($data->name);
        }

        return $tag;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTransformation($data, string $to, array $context = []): bool
    {
        dump($data, $to, $context);
        // in the case of an input, the value given here is an array (the JSON decoded).
        // if it's a book we transformed the data already
        if($data instanceof CategoryTag) {
            return false;
        }

        return CategoryTag::class === $to;
    }
}
