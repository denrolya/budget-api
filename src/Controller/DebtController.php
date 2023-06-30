<?php

namespace App\Controller;

use App\Entity\Debt;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;

#[Route('/api/v2/debt', name: 'api_v2_debt_')]
class DebtController extends AbstractFOSRestController
{
    #[Rest\View(serializerGroups: ['debt:collection:read'])]
    #[Route('', name: 'collection_read', methods:['get'] )]
    public function list(ManagerRegistry $doctrine): View
    {
        return $this->view(
            $doctrine->getRepository(Debt::class)->findAll()
        );
    }
}
