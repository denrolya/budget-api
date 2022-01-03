<?php

namespace App\Entity;

use Symfony\Component\Security\Core\User\UserInterface;

interface OwnableInterface
{
    public function getOwner(): ?UserInterface;

    public function setOwner(UserInterface $user): self;
}
