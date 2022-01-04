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
        'create' => [
            'method' => 'POST',
            'path' => '/accounts/cash',
            "denormalization_context" => [
                "groups" => "account:create",
            ],
            "normalization_context" => [
                "groups" => "account:create",
            ],
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
class CashAccount extends Account
{
    public function getType(): string
    {
        return self::ACCOUNT_TYPE_CASH;
    }
}
