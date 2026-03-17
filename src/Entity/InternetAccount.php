<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\InternetAccountRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: InternetAccountRepository::class)]
#[ApiResource(
    operations: [
        new Post(uriTemplate: '/accounts/internet', normalizationContext: ['groups' => 'account:write']),
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
