<?php

namespace App\Controller;

use App\Entity\Debt;
use App\Traits\SoftDeletableTogglerController;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;

#[Route('/api/v2/debt', name: 'api_v2_debt_')]
class DebtController extends AbstractFOSRestController
{
    use SoftDeletableTogglerController;

    #[Rest\View(serializerGroups: ['debt:collection:read'])]
    #[Rest\QueryParam(name: 'withClosed', default: false, description: 'Should fetch debts that were closed too', nullable: true, allowBlank: false)]
    #[Route('', name: 'collection_read', methods:['get'] )]
    public function list(ManagerRegistry $doctrine, bool $withClosed = false): View
    {
        if ($withClosed) {
            $this->disableSoftDeletable();
        }

        return $this->view(
            $doctrine->getRepository(Debt::class)->findAll()
        );
    }
}
