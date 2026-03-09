<?php

namespace App\Bank;

use App\Bank\DTO\DraftTransactionData;

/**
 * Implement this on providers that deliver transactions via webhooks in real time.
 * Examples: Monobank
 */
interface WebhookCapableInterface
{
    /**
     * Parse the raw webhook payload and return a DraftTransactionData,
     * or null if the payload is not a transaction event (e.g. ping, unknown type).
     *
     * @param array $payload  Decoded JSON body of the webhook request
     */
    public function parseWebhookPayload(array $payload): ?DraftTransactionData;

    /**
     * Registers (or updates) the webhook URL with the bank's API.
     * Should be called once after creating the integration.
     *
     * @param array  $credentials  Integration credentials (same format as fetchAccounts)
     * @param string $webhookUrl   Publicly reachable URL the bank will POST to
     * @throws \RuntimeException on API error
     */
    public function registerWebhook(array $credentials, string $webhookUrl): void;
}
