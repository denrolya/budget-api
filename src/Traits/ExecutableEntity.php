<?php

namespace App\Traits;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait ExecutableEntity
{
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?DateTimeInterface $executedAt;

    public function getExecutedAt(): ?CarbonImmutable
    {
        return ($this->executedAt instanceof DateTimeInterface) ? CarbonImmutable::instance($this->executedAt) : null;
    }

    public function setExecutedAt(DateTimeInterface $executedAt): self
    {
        $this->executedAt = $executedAt;

        return $this;
    }
}
