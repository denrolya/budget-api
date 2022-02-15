<?php

namespace App\Traits;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

trait TimestampableEntity
{
    /**
     * @Gedmo\Timestampable(on="create")
     *
     * @ORM\Column(type="datetime", nullable=false)
     */
    protected ?DateTimeInterface $createdAt;

    /**
     * @Gedmo\Timestampable(on="update")
     *
     * @ORM\Column(type="datetime", nullable=false)
     */
    protected ?DateTimeInterface $updatedAt;

    public function getUpdatedAt()
    {
        if($this->updatedAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->updatedAt->getTimestamp(), $this->updatedAt->getTimezone());
        }

        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function updateTimestamps(): void
    {
        $this->setUpdatedAt(CarbonImmutable::now());

        if($this->getCreatedAt() === null) {
            $this->setCreatedAt(CarbonImmutable::now());
        }
    }

    public function getCreatedAt(): ?CarbonInterface
    {
        if($this->createdAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->createdAt->getTimestamp(), $this->createdAt->getTimezone());
        }

        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
