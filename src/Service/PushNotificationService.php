<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PushNotificationService
{
    public function __construct(
        private readonly PushSubscriptionRepository $repository,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'VAPID_PUBLIC_KEY')]
        private readonly string $vapidPublicKey,
        #[Autowire(env: 'VAPID_PRIVATE_KEY')]
        private readonly string $vapidPrivateKey,
        #[Autowire(env: 'VAPID_SUBJECT')]
        private readonly string $vapidSubject,
    ) {
    }

    /**
     * Send a push notification to all subscriptions of a specific user.
     *
     * @param array{title: string, body: string, url?: string, tag?: string} $data
     */
    public function sendToUser(User $user, array $data): void
    {
        $subscriptions = $this->repository->findByUser($user);
        if (empty($subscriptions)) {
            return;
        }

        $this->dispatch($subscriptions, $data);
    }

    /**
     * Broadcast a push notification to every registered subscription (all users).
     * Used for system-wide events like extreme rate movements.
     *
     * @param array{title: string, body: string, url?: string, tag?: string} $data
     */
    public function broadcast(array $data): void
    {
        $subscriptions = $this->repository->findAll();
        if (empty($subscriptions)) {
            return;
        }

        $this->dispatch($subscriptions, $data);
    }

    /**
     * @param \App\Entity\PushSubscription[] $subscriptions
     * @param array{title: string, body: string, url?: string, tag?: string} $data
     */
    private function dispatch(array $subscriptions, array $data): void
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => $this->vapidSubject,
                'publicKey'  => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ],
        ]);

        $payload = json_encode([
            'title' => $data['title'],
            'body'  => $data['body'],
            'url'   => $data['url'] ?? '/m',
            'tag'   => $data['tag'] ?? 'budget',
        ], JSON_THROW_ON_ERROR);

        foreach ($subscriptions as $sub) {
            $pushSub = Subscription::create([
                'endpoint' => $sub->getEndpoint(),
                'keys'     => [
                    'p256dh' => $sub->getP256dh(),
                    'auth'   => $sub->getAuth(),
                ],
            ]);

            $webPush->queueNotification($pushSub, $payload);
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = (string) $report->getRequest()->getUri();

            if ($report->isSuccess()) {
                $this->logger->debug('[Push] Sent: {endpoint}', ['endpoint' => $endpoint]);
                continue;
            }

            // 410 Gone or 404 = subscription expired/revoked — clean up
            $statusCode = $report->getResponse()?->getStatusCode();
            if ($report->isSubscriptionExpired() || in_array($statusCode, [404, 410], true)) {
                $this->logger->info('[Push] Expired subscription removed: {endpoint}', ['endpoint' => $endpoint]);
                $this->repository->removeByEndpoint($endpoint);
                continue;
            }

            $this->logger->warning('[Push] Delivery failed (HTTP {code}): {endpoint}', [
                'code'     => $statusCode,
                'endpoint' => $endpoint,
            ]);
        }
    }
}
