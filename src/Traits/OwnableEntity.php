<?php

namespace App\Traits;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use App\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;

trait OwnableEntity
{
    /**
     * @Gedmo\Blameable(on="create")
     * @Gedmo\Blameable(on="update")
     *
     * @ORM\ManyToOne(targetEntity=User::class)
     * @ORM\JoinColumn(name="owner_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected ?UserInterface $owner;

    public function getOwner(): ?UserInterface
    {
        return $this->owner;
    }

    public function setOwner(UserInterface $user): self
    {
        $this->owner = $user;

        return $this;
    }
}
