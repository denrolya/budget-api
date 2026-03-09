<?php

namespace App\ApiPlatform\Action;

use App\Bank\BankProviderRegistry;
use App\Bank\WebhookCapableInterface;
use App\Entity\BankIntegration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsController]
final class BankIntegrationRegisterWebhookAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private BankProviderRegistry $registry,
        private readonly UrlGeneratorInterface $urlGenerator,
        private string $webhookBaseUrl = '',
    ) {
    }

    public function __invoke(Request $request, int $id): Response
    {
        $integration = $this->em->getRepository(BankIntegration::class)->find($id);

        if (!$integration) {
            throw new NotFoundHttpException("BankIntegration #{$id} not found.");
        }

        if ($integration->getOwner() !== $this->getUser()) {
            throw new AccessDeniedHttpException();
        }

        $provider = $this->registry->get($integration->getProvider());

        if (!$provider instanceof WebhookCapableInterface) {
            return new JsonResponse(
                ['error' => sprintf('Provider "%s" does not support webhooks.', $integration->getProvider()->value)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Build the public webhook URL for this provider (e.g. /api/webhooks/monobank)
        if ($this->webhookBaseUrl !== '') {
            $baseUrl = rtrim($this->webhookBaseUrl, '/');
        } else {
            $host = $request->getHost();
            $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                || str_ends_with($host, '.local');

            if ($isLocal) {
                return new JsonResponse(
                    ['error' => 'Cannot register a webhook from localhost — the bank cannot reach your machine. Set WEBHOOK_BASE_URL in .env.local to your production URL (e.g. https://api.yourdomain.com) and retry.'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $baseUrl = $request->getSchemeAndHttpHost();
        }

        $webhookUrl = $baseUrl . $this->urlGenerator->generate(
            'api_webhooks_receive',
            ['provider' => $integration->getProvider()->value],
        );

        try {
            $provider->registerWebhook($integration->getCredentials(), $webhookUrl);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['webhookUrl' => $webhookUrl]);
    }
}
