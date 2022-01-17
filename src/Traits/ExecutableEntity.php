<?php

namespace App\Traits;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Carbon\CarbonInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

trait ExecutableEntity
{
    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected ?DateTimeInterface $executedAt;

    public function getExecutedAt(): CarbonInterface|DateTimeInterface
    {
        if($this->executedAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->executedAt->getTimestamp(), $this->executedAt->getTimezone());
        }

        return $this->executedAt;
    }

    public function setExecutedAt(DateTimeInterface $executedAt): self
    {
        $this->executedAt = $executedAt;

        return $this;
    }
}
