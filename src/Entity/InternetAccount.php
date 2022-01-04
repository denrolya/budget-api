<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\InternetAccountRepository")
 */
#[ApiResource(
    collectionOperations: [
        'create' => [
            'method' => 'POST',
            'path' => '/accounts/internet',
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
            'method' => 'GET',
            'controller' => SomeRandomController::class,
        ],
    ],
)]
class InternetAccount extends Account
{
    public function getType(): string
    {
        return self::ACCOUNT_TYPE_INTERNET;
    }
}
