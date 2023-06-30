<?php

namespace App\Controller;

use App\Entity\Account;
use Carbon\CarbonImmutable;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;

#[Route('/api/v2/account', name: 'api_v2_account_')]
class AccountController extends AbstractFOSRestController
{
    /**
     * @Rest\View(serializerGroups={"account:collection:read"})
     */
    #[Route('', name: 'collection_read', methods:['get'] )]
    public function list(ManagerRegistry $doctrine): View
    {
        return $this->view(
            $doctrine->getRepository(Account::class)->findAll()
        );
    }
}
