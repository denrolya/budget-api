<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\CashAccountRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: CashAccountRepository::class)]
#[ApiResource(
    operations: [
        new Post(uriTemplate: '/accounts/cash', normalizationContext: ['groups' => 'account:write']),
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
