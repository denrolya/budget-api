<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\InternetAccountRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: InternetAccountRepository::class)]
#[ApiResource(
    collectionOperations: [
        'post' => [
            'path' => '/accounts/internet',
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
class InternetAccount extends Account
{
    public function getType(): string
    {
        return self::ACCOUNT_TYPE_INTERNET;
    }
}
