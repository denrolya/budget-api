<?php

declare(strict_types=1);

namespace App\Bank;

/**
 * How the integration fetches transactions.
 * Only relevant when a provider supports both modes.
 * Single-mode providers ignore this field.
 */
enum SyncMethod: string
{
    case Webhook = 'webhook';
    case Polling = 'polling';
}
