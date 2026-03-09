<?php

namespace App\Bank;

/**
 * How the integration fetches transactions.
 * Only relevant when a provider supports both modes (e.g. a future provider).
 * Single-mode providers ignore this field — Monobank always uses webhook,
 * Wise always uses polling.
 */
enum SyncMethod: string
{
    case Webhook = 'webhook';
    case Polling = 'polling';
}
