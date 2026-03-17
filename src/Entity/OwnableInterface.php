<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Security\Core\User\UserInterface;

interface OwnableInterface
{
    public function getOwner(): ?UserInterface;

    public function setOwner(UserInterface $user): self;
}
