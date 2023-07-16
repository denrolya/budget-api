<?php

namespace App\Controller;

use App\Request\ParamConverter\CarbonParamConverter;
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
    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'accounts', description: 'Filter by accounts', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', description: 'Filter by categories', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'isDraft', default: false, description: 'Show only draft transactions', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'perPage', requirements: '\d+', default: 30, description: 'Results per page')]
    #[Rest\QueryParam(name: 'page', requirements: '\d+', default: 1, description: 'Page number')]
    #[Rest\View(serializerGroups: ['transaction:collection:read'])]
    #[Route('', name: 'collection_read', methods:['get'] )]
    public function list(AssetsManager $assetsManager, CarbonImmutable $after, CarbonImmutable $before, ?array $accounts, ?array $categories, ?string $type = null, $isDraft = false, int $perPage = 30, int $page = 1): View
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
