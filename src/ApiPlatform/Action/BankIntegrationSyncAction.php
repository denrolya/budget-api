<?php

namespace App\ApiPlatform\Action;

use App\Bank\BankSyncService;
use App\Entity\BankIntegration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsController]
final class BankIntegrationSyncAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BankSyncService $syncService,
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

        try {
            $from = $request->query->get('from')
                ? new DateTimeImmutable($request->query->get('from'))
                : null;

            $to = $request->query->get('to')
                ? new DateTimeImmutable($request->query->get('to'))
                : null;
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Invalid date format for "from" or "to". Expected YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $created = $this->syncService->sync($integration, $from, $to);
        } catch (\LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['created' => $created]);
    }
}
