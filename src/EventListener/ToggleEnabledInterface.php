<?php

declare(strict_types=1);

namespace App\EventListener;

interface ToggleEnabledInterface
{
    public function setEnabled(bool $enabled): void;
}
