<?php

namespace App\Controller;

use App\Bank\BankProvider;
use App\Bank\BankWebhookService;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Public webhook receiver.
 * Security: no authentication required (bank sends to us, not the user).
 * Route is opened in security.yaml.
 */
#[Route('/api/webhooks', name: 'api_webhooks_')]
class BankWebhookController extends AbstractFOSRestController
{
    public function __construct(
        private readonly BankWebhookService $webhookService,
    ) {
    }

    /**
     * Receives webhook payloads from a bank identified by {provider} slug.
     * Example: POST /api/webhooks/monobank
     */
    #[Route('/{provider}', name: 'receive', methods: ['POST'])]
    public function receive(Request $request, string $provider): View
    {
        try {
            $bank = BankProvider::from($provider);
        } catch (\ValueError) {
            return $this->view(['error' => "Unknown bank provider: {$provider}"], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->view(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $transaction = $this->webhookService->handle($bank, $payload);
        } catch (\Throwable $e) {
            return $this->view(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($transaction === null) {
            // Monobank expects 200 for ping/non-transaction events
            return $this->view([], Response::HTTP_OK);
        }

        return $this->view(['id' => $transaction->getId()], Response::HTTP_CREATED);
    }
}
