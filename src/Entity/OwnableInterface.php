<?php

namespace App\Entity;

interface OwnableInterface
{
    public function getOwner(): ?UserInterface;

    public function setOwner(UserInterface $user);
}
