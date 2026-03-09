<?php

namespace App\ApiPlatform\Action;

use App\Bank\BankProviderRegistry;
use App\Entity\BankIntegration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsController]
final class BankIntegrationAccountsAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BankProviderRegistry $registry,
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

        try {
            $accounts = $provider->fetchAccounts($integration->getCredentials());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $data = array_map(fn($a) => [
            'externalId' => $a->externalId,
            'name' => $a->name,
            'currency' => $a->currency,
            'balance' => $a->balance,
        ], $accounts);

        return new JsonResponse($data);
    }
}
