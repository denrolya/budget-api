<?php

namespace App\Entity;

use DateTimeInterface;
use Carbon\CarbonInterface;

interface ExecutableInterface
{
    public function getExecutedAt(): CarbonInterface|DateTimeInterface;

    public function setExecutedAt(DateTimeInterface $executedAt): static;
}
