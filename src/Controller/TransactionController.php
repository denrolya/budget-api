<?php

namespace App\Controller;

use App\Service\AssetsManager;
use Carbon\CarbonImmutable;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Routing\Annotation\Route;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;

#[Route('/api/v2/transaction', name: 'api_v2_transaction_')]
class TransactionController extends AbstractFOSRestController
{
    /**
     * @Rest\QueryParam(name="after", nullable=true, description="After date")
     * @ParamConverter("after", options={"format": "Y-m-d", "default"="first day of this month"})
     * @Rest\QueryParam(name="before", nullable=true, description="Before date")
     * @ParamConverter("before", options={"format": "Y-m-d", "default"="first day of next month"})
     * * @Rest\QueryParam(name="type", requirements="(expense|income)", nullable=true, allowBlank=false, default=null,
     *     description="Type of transactions to calculate")
     * @Rest\QueryParam(name="accounts", nullable=true, description="Account filters")
     * @Rest\QueryParam(name="categories", nullable=true, description="Category filters")
     * @Rest\QueryParam(name="isDraft", default=false, nullable=false, description="Whether or not to fetch
     *     draft transactions only")
     * @Rest\QueryParam(name="perPage", requirements="\d+", default="30", description="Number of results per page")
     * @Rest\QueryParam(name="page", requirements="\d+", default="1", description="Page number")
     * @Rest\View(serializerGroups={"transaction:collection:read"})
     */
    #[Route('/', name: 'collection_read', methods:['get'] )]
    public function list(AssetsManager $assetsManager, CarbonImmutable $after, CarbonImmutable $before, ?string $type = null, ?array $accounts, ?array $categories, $isDraft = false, int $perPage = 30, int $page = 1): View
    {
        return $this->view(
            $assetsManager->generateTransactionPaginationData(
                $after,
                $before,
                $type,
                $categories,
                $accounts,
                null,
                true,
                $isDraft,
                $perPage,
                $page
            )
        );
    }
}
