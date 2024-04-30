<?php

namespace App\Traits;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

trait TimestampableEntity
{
    #[Gedmo\Timestampable(on: "create")]
    #[ORM\Column(type: "datetime", nullable: false)]
    protected ?DateTimeInterface $createdAt;

    #[Gedmo\Timestampable(on: "update")]
    #[ORM\Column(type: "datetime", nullable: false)]
    protected ?DateTimeInterface $updatedAt;

    public function getUpdatedAt(): ?CarbonImmutable
    {
        return ($this->updatedAt instanceof DateTimeInterface) ? CarbonImmutable::instance($this->updatedAt) : null;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function updateTimestamps(): void
    {
        $this->setUpdatedAt(CarbonImmutable::now());

        if ($this->getCreatedAt() === null) {
            $this->setCreatedAt(CarbonImmutable::now());
        }
    }

    public function getCreatedAt(): ?CarbonImmutable
    {
        return ($this->createdAt instanceof DateTimeInterface) ? CarbonImmutable::instance($this->createdAt) : null;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
