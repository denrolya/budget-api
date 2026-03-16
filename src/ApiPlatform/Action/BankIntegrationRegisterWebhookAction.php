<?php

namespace App\ApiPlatform\Action;

use App\Bank\BankWebhookRegistrationService;
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
final class BankIntegrationRegisterWebhookAction extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BankWebhookRegistrationService $registrationService,
    ) {
    }

    /**
     * @see \App\Tests\ApiPlatform\Action\BankIntegrationRegisterWebhookActionTest
     * @tested testUnauthenticatedGets401
     * @tested testUnknownIntegrationReturns404
     * @tested testWrongOwnerReturns403
     * @tested testLocalhostRequestIsBlockedWith422
     * @tested testWiseWebhookRegistrationIsBlockedOnLocalhostWith422
     * @tested testRegisterWebhookSuccessReturnWebhookUrl
     * @tested testBankApiFailureReturns502
     */
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
            $webhookUrl = $this->registrationService->register($integration, $request);
        } catch (\LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['webhookUrl' => $webhookUrl]);
    }
}
