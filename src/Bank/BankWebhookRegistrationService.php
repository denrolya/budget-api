<?php

declare(strict_types=1);

namespace App\Bank;

use App\Entity\BankIntegration;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Registers provider webhooks for existing integrations and builds public callback URLs.
 * Used by both HTTP actions and CLI commands so behavior is identical everywhere.
 */
class BankWebhookRegistrationService
{
    public function __construct(
        private readonly BankProviderRegistry $registry,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $webhookBaseUrl = '',
    ) {
    }

    public function supports(BankIntegration $integration): bool
    {
        $provider = $this->registry->get($integration->getProvider());

        return $provider instanceof WebhookCapableInterface;
    }

    /**
     * @throws LogicException when webhook registration is not possible in current context
     * @throws RuntimeException when provider API call fails
     */
    public function register(BankIntegration $integration, ?Request $request = null): string
    {
        $provider = $this->registry->get($integration->getProvider());

        if (!$provider instanceof WebhookCapableInterface) {
            throw new LogicException(\sprintf('Provider "%s" does not support webhooks.', $integration->getProvider()->value));
        }

        $webhookUrl = $this->resolveWebhookUrl($integration->getProvider(), $request);
        $provider->registerWebhook($integration->getCredentials(), $webhookUrl);

        return $webhookUrl;
    }

    private function resolveWebhookUrl(BankProvider $provider, ?Request $request): string
    {
        if ('' !== $this->webhookBaseUrl) {
            $baseUrl = rtrim($this->webhookBaseUrl, '/');
        } elseif (null !== $request) {
            $host = $request->getHost();
            $isLocal = \in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                || str_ends_with($host, '.local');

            if ($isLocal) {
                throw new LogicException('Cannot register a webhook from localhost — the bank cannot reach your machine. Set WEBHOOK_BASE_URL in .env.local to your production URL (e.g. https://api.yourdomain.com) and retry.');
            }

            $baseUrl = $request->getSchemeAndHttpHost();
        } else {
            throw new LogicException('Cannot register webhooks from CLI without WEBHOOK_BASE_URL. Set WEBHOOK_BASE_URL to your public API origin (e.g. https://api.yourdomain.com).');
        }

        return $baseUrl . $this->urlGenerator->generate(
            'api_webhooks_receive',
            ['provider' => $provider->value],
        );
    }
}
