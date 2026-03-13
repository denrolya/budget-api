<?php

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/push-subscriptions', name: 'api_push_subscriptions_')]
class PushSubscriptionController extends AbstractFOSRestController
{
    public function __construct(
        private readonly PushSubscriptionRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Register a push subscription for the current user.
     * Body: { endpoint, keys: { p256dh, auth } }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): View
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->view(null, Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $endpoint = $data['endpoint'] ?? null;
        $p256dh   = $data['keys']['p256dh'] ?? null;
        $auth     = $data['keys']['auth'] ?? null;

        if (!$endpoint || !$p256dh || !$auth) {
            return $this->view(['error' => 'Missing endpoint or keys'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Upsert: if endpoint already exists for this user, just return 200
        $existing = $this->repository->findByEndpoint($endpoint);
        if ($existing !== null) {
            return $this->view(null, Response::HTTP_OK);
        }

        $subscription = new PushSubscription($user, $endpoint, $p256dh, $auth);
        $this->em->persist($subscription);
        $this->em->flush();

        return $this->view(null, Response::HTTP_CREATED);
    }

    /**
     * Remove a push subscription (unsubscribe).
     * Body: { endpoint }
     */
    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function delete(Request $request): View
    {
        $data     = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $endpoint = $data['endpoint'] ?? null;

        if ($endpoint) {
            $this->repository->removeByEndpoint($endpoint);
        }

        return $this->view(null, Response::HTTP_NO_CONTENT);
    }
}
