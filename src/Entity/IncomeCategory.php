<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\IncomeCategoryRepository")
 */
#[ApiResource(
    collectionOperations: [
        'list' => [
            'method' => 'GET',
            'path' => '/categories/income',
            'normalization_context' => ['groups' => 'category:list'],
        ],
        'post' => [
            'path' => '/categories/income',
            'normalization_context' => ['groups' => 'category:create'],
            'denormalization_context' => ['groups' => 'category:create'],
        ],
    ],
    itemOperations: [
        'get' => [
            'controller' => NotFoundAction::class,
            'read' => false,
            'output' => false,
        ],
    ],
)]
class IncomeCategory extends Category
{

    public function getType(): string
    {
        return Category::INCOME_CATEGORY_TYPE;
    }
}
