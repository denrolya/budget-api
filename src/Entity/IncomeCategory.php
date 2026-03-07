<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\IncomeCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: IncomeCategoryRepository::class)]
#[ApiResource(
    operations: [
        new Post(uriTemplate: '/categories/income', normalizationContext: ['groups' => 'category:write']),
    ],
    denormalizationContext: ['groups' => 'category:write'],
    paginationEnabled: false,
)]
class IncomeCategory extends Category
{
    public function getType(): string
    {
        return Category::INCOME_CATEGORY_TYPE;
    }
}
