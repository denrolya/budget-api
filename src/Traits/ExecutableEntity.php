<?php

namespace App\Traits;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait ExecutableEntity
{
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?DateTimeInterface $executedAt;

    public function getExecutedAt(): CarbonInterface|DateTimeInterface
    {
        if ($this->executedAt instanceof DateTimeInterface) {
            return CarbonImmutable::createFromTimestamp(
                $this->executedAt->getTimestamp(),
                $this->executedAt->getTimezone()
            );
        }

        return $this->executedAt;
    }

    public function setExecutedAt(DateTimeInterface $executedAt): self
    {
        $this->executedAt = $executedAt;

        return $this;
    }
}
