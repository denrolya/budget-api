<?php

namespace App\EventListener;

trait ToggleEnabledTrait
{
    private bool $enabled = true;

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
