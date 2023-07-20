<?php

namespace App\EventListener;

interface ToggleEnabledInterface
{
    public function setEnabled(bool $enabled): void;
}
