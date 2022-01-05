<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\CashAccountRepository")
 */
#[ApiResource(
    collectionOperations: [
        'post' => [
            'path' => '/accounts/cash',
            'normalization_context' => ['groups' => 'account:write'],
        ],
    ],
    itemOperations: [
        'get' => [
            'controller' => NotFoundAction::class,
            'read' => false,
            'output' => false,
        ],
    ],
    denormalizationContext: ['groups' => 'account:write'],
)]
class CashAccount extends Account
{
    public function getType(): string
    {
        return self::ACCOUNT_TYPE_CASH;
    }
}
