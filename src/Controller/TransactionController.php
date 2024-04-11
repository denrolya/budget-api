<?php

namespace App\Controller;

use App\Pagination\Paginator;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/transaction', name: 'api_v2_transaction_')]
class TransactionController extends AbstractFOSRestController
{
    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'accounts', description: 'Filter by accounts', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', description: 'Filter by categories', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'withNestedCategories', default: true, description: 'Filter by category and its children', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'isDraft', default: false, description: 'Show only draft transactions', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'perPage', requirements: '^[1-9][0-9]*$', default: Paginator::PER_PAGE, description: 'Results per page')]
    #[Rest\QueryParam(name: 'page', requirements: '^[1-9][0-9]*$', default: 1, description: 'Page number')]
    #[Rest\View(serializerGroups: ['transaction:collection:read'])]
    #[Route('', name: 'collection_read', methods:['get'] )]
    public function list(AssetsManager $assetsManager, CarbonImmutable $after, CarbonImmutable $before, ?array $accounts, ?array $categories, bool $withNestedCategories = true, ?string $type = null, $isDraft = false, int $perPage = Paginator::PER_PAGE, int $page = 1): View
    {
        return $this->view(
            $assetsManager->generateTransactionPaginationData(
                after: $after,
                before: $before,
                type: $type,
                categories: $categories,
                accounts: $accounts,
                withChildCategories: $withNestedCategories,
                onlyDrafts: $isDraft,
                perPage: $perPage,
                page: $page
            )
        );
    }
}
