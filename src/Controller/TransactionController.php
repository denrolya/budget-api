<?php

declare(strict_types=1);

namespace App\Controller;

use App\Pagination\Paginator;
use App\Service\AssetsManager;
use App\Service\CSVExporter;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/transaction', name: 'api_v2_transaction_')]
final class TransactionController extends AbstractFOSRestController
{
    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: [
        'format' => 'Y-m-d',
        'default' => 'first day of this month',
    ])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: [
        'format' => 'Y-m-d',
        'default' => 'last day of this month',
    ])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'accounts', description: 'Filter by accounts', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', description: 'Filter by categories', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'excludedCategories', description: 'Exclude categories', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'currencies', description: 'Filter by currencies', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'debts', description: 'Filter by debts', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(
        name: 'amount[gte]',
        description: 'Amount >= value (numeric)',
        nullable: true,
        allowBlank: true
    )]
    #[Rest\QueryParam(
        name: 'amount[lte]',
        description: 'Amount <= value (numeric)',
        nullable: true,
        allowBlank: true
    )]
    #[Rest\QueryParam(
        name: 'withNestedCategories',
        requirements: '^(0|1|true|false)$',
        default: true,
        description: 'Include nested categories',
        nullable: true,
        allowBlank: false
    )]
    #[Rest\QueryParam(
        name: 'isDraft',
        requirements: '^(0|1|true|false)$',
        default: null,
        description: 'true=only draft, false=only non-draft, null=all',
        nullable: true,
        allowBlank: false
    )]
    #[Rest\QueryParam(name: 'note', description: 'Search substring in note', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'perPage', requirements: '^[1-9][0-9]*$', default: Paginator::PER_PAGE)]
    #[Rest\QueryParam(name: 'page', requirements: '^[1-9][0-9]*$', default: 1)]
    #[Rest\View(serializerGroups: ['transaction:collection:read'])]
    #[Route('', name: 'collection_read', methods: ['GET'])]
    public function list(
        Request $request,
        AssetsManager $assetsManager,
        CarbonImmutable $after,
        CarbonImmutable $before,
        ?string $type = null,
        ?array $accounts = null,
        ?array $categories = null,
        ?array $excludedCategories = null,
        ?array $currencies = null,
        ?array $debts = null,
        bool $withNestedCategories = true,
        ?bool $isDraft = null,
        ?string $note = null,
        int $perPage = Paginator::PER_PAGE,
        int $page = 1
    ): View {

        // ----- amount parsing (cannot be done via QueryParam) -----
        $amount = $request->query->all('amount');
        $amountGte = isset($amount['gte']) && is_numeric($amount['gte']) ? (float) $amount['gte'] : null;
        $amountLte = isset($amount['lte']) && is_numeric($amount['lte']) ? (float) $amount['lte'] : null;

        if ($amountGte !== null && $amountLte !== null && $amountGte > $amountLte) {
            throw new \InvalidArgumentException('amount[gte] cannot be greater than amount[lte]');
        }

        $note = (is_string($note) && trim($note) !== '') ? trim($note) : null;

        return $this->view(
            $assetsManager->generateTransactionPaginationData(
                after: $after,
                before: $before,
                type: $type,
                categories: $categories,
                accounts: $accounts,
                excludedCategories: $excludedCategories,
                withChildCategories: $withNestedCategories,
                isDraft: $isDraft,
                note: $note,
                amountGte: $amountGte,
                amountLte: $amountLte,
                debts: $debts,
                currencies: $currencies,
                perPage: $perPage,
                page: $page,
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: [
        'format' => 'Y-m-d',
        'default' => 'first day of this month',
    ])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: [
        'format' => 'Y-m-d',
        'default' => 'last day of this month',
    ])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true)]
    #[Rest\QueryParam(name: 'accounts', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'excludedCategories', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'currencies', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'debts', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'amount[gte]', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'amount[lte]', nullable: true, allowBlank: true)]

    #[Rest\QueryParam(
        name: 'withNestedCategories',
        requirements: '^(0|1|true|false)$',
        default: true,
        nullable: true
    )]
    #[Rest\QueryParam(
        name: 'isDraft',
        requirements: '^(0|1|true|false)$',
        default: null,
        nullable: true
    )]
    #[Rest\QueryParam(name: 'note', nullable: true, allowBlank: true)]
    #[Route('/export.csv', name: 'collection_export_csv', methods: ['GET'])]
    public function exportCsv(
        Request $request,
        CSVExporter $exporter,
        CarbonImmutable $after,
        CarbonImmutable $before,
        ?string $type = null,
        ?array $accounts = null,
        ?array $categories = null,
        ?array $excludedCategories = null,
        ?array $currencies = null,
        ?array $debts = null,
        bool $withNestedCategories = true,
        ?bool $isDraft = null,
        ?string $note = null,
    ): Response {

        $amount = $request->query->all('amount');
        $amountGte = isset($amount['gte']) && is_numeric($amount['gte']) ? (float) $amount['gte'] : null;
        $amountLte = isset($amount['lte']) && is_numeric($amount['lte']) ? (float) $amount['lte'] : null;

        if ($amountGte !== null && $amountLte !== null && $amountGte > $amountLte) {
            throw new \InvalidArgumentException('amount[gte] cannot be greater than amount[lte]');
        }

        $note = (is_string($note) && trim($note) !== '') ? trim($note) : null;

        $response = $exporter->stream(
            after: $after,
            before: $before,
            type: $type,
            categoryFilter: $categories,
            accountFilter: $accounts,
            excludedCategories: $excludedCategories,
            withNestedCategories: $withNestedCategories,
            isDraft: $isDraft,
            affectingProfitOnly: true,
            currencies: $currencies,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
            debts: $debts,
        );

        $filename = sprintf('transactions_%s_%s.csv', $after->format('Ymd'), $before->format('Ymd'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }
}
