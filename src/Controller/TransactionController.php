<?php

namespace App\Controller;

use App\Service\AssetsManager;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Transaction;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;

#[Route('/api/v2', name: 'api_v2_')]
class TransactionController extends AbstractFOSRestController
{
    /**
     * @Rest\View(serializerGroups={"transaction_list"})
     */
    #[Route('/transaction', name: 'app_transaction_list', methods:['get'] )]
    public function list(ManagerRegistry $doctrine): View
    {
        return $this->view($doctrine
            ->getRepository(Transaction::class)
            ->findBy([], null, 10));
    }

    #[Route('/transaction/{id}', name: 'app_transaction_single', methods:['get'] )]
    public function single(ManagerRegistry $doctrine, int $id): JsonResponse
    {
        if (!$transaction = $doctrine->getRepository(Transaction::class)->find($id)) {
            return $this->json('No transaction found for id ' . $id, 404);
        }

        return $this->json($transaction);
    }
}
