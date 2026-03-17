<?php

namespace App\Controller;

use App\Attribute\MapCarbonDate;
use App\Bank\Provider\MonobankProvider;
use App\Bank\Provider\WiseProvider;
use App\Repository\ExchangeRateSnapshotRepository;
use App\Service\ExchangeRateSnapshotResolver;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use OpenApi\Attributes as OA;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/exchange-rates', name: 'api_v2_exchange_rates_')]
#[OA\Tag(name: 'Exchange Rates')]
class ExchangeRatesController extends AbstractFOSRestController
{
    #[Rest\QueryParam(name: 'after', description: 'Start date (Y-m-d), required', nullable: false)]
    #[Rest\QueryParam(name: 'before', description: 'End date (Y-m-d), defaults to after', nullable: true)]
    #[Route('/snapshots', name: 'snapshots', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/exchange-rates/snapshots',
        summary: 'List stored exchange rate snapshots',
        description: 'Returns all stored exchange rate snapshots within the given date range. Dates are automatically corrected if before < after.',
        security: [['bearerAuth' => []]],
        tags: ['Exchange Rates'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: true, description: 'Start date (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), defaults to after', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Snapshots in range',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'after', type: 'string', format: 'date', example: '2024-01-01'),
                    new OA\Property(property: 'before', type: 'string', format: 'date', example: '2024-01-31'),
                    new OA\Property(property: 'snapshots', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    /**
     * TODO: remove and use API Platform?
     *
     * @see \App\Tests\Controller\ExchangeRatesTest
     * @tested testSnapshots_returnsCorrectShape
     * @tested testSnapshots_swappedDates_autoCorrects
     * @tested testSnapshots_singleDate_beforeDefaultsToAfter
     * @tested testSnapshots_withoutAuth_returns401
     */
    public function snapshots(
        ExchangeRateSnapshotRepository $snapshotRepository,
        #[MapCarbonDate(format: 'Y-m-d')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d')] ?CarbonImmutable $before = null,
    ): View {
        $before = $before ?? $after;

        if ($before->lessThan($after)) {
            [$after, $before] = [$before, $after];
        }

        $snapshots = $snapshotRepository->findSnapshotsInRange($after->startOfDay(), $before->endOfDay());

        return $this->view(
            ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'snapshots' => $snapshots],
            Response::HTTP_OK
        );
    }

    #[Rest\QueryParam(name: 'date', description: 'Date (Y-m-d)', nullable: true)]
    #[Route('', name: 'historical', methods: ['get'])]
    #[Route('/fixer', name: 'fixer_rates', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/exchange-rates',
        summary: 'Get exchange rates for a date',
        description: 'Returns exchange rates for the given date. Checks the local DB snapshot first; falls back to Fixer API if no snapshot exists. Also accessible at /api/v2/exchange-rates/fixer.',
        security: [['bearerAuth' => []]],
        tags: ['Exchange Rates'],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Date (Y-m-d), default: today', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Exchange rates keyed by currency code',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'rates', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'number')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Exchange rate provider error'),
        ]
    )]
    /**
     * Returns exchange rates for a given date.
     * Now routes through ExchangeRateSnapshotResolver: checks DB first, calls Fixer only if no snapshot exists.
     *
     * @see \App\Tests\Controller\ExchangeRatesTest
     * @tested testFixer_returnsRatesShape
     * @tested testFixerBaseUrl_alsoWorks
     * @tested testFixer_withoutAuth_returns401
     */
    public function fixerRates(
        ExchangeRateSnapshotResolver $snapshotResolver,
        #[MapCarbonDate(format: 'Y-m-d', default: 'today')] CarbonImmutable $date,
    ): View {
        try {
            $rates = $snapshotResolver->getRatesForDate($date);
        } catch (\RuntimeException) {
            return $this->view(
                ['error' => 'An error occurred while fetching the exchange rates. Please try again later.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->view(compact('rates'), Response::HTTP_OK);
    }

    #[Route('/monobank', name: 'monobank_rates', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/exchange-rates/monobank',
        summary: 'Latest Monobank exchange rates',
        description: 'Fetches and returns the latest exchange rates from the Monobank public API.',
        security: [['bearerAuth' => []]],
        tags: ['Exchange Rates'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Monobank rates',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'rates', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Monobank API error'),
        ]
    )]
    /**
     * @see \App\Tests\Controller\ExchangeRatesTest
     * @tested testMonobankRates_returnsCorrectShape
     * @tested testMonobankRates_providerError_returns500
     * @tested testMonobankRates_withoutAuth_returns401
     */
    public function monobankRates(MonobankProvider $monobankProvider): View
    {
        try {
            $rates = $monobankProvider->getLatest();
        } catch (InvalidArgumentException) {
            return $this->view(
                ['error' => 'An error occurred while fetching the exchange rates. Please try again later.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->view(compact('rates'), Response::HTTP_OK);
    }

    #[Rest\QueryParam(name: 'date', description: 'Date (Y-m-d)', nullable: true)]
    #[Route('/wise', name: 'wise_rates', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/exchange-rates/wise',
        summary: 'Wise exchange rates for a date',
        description: 'Fetches exchange rates from the Wise API for the given date.',
        security: [['bearerAuth' => []]],
        tags: ['Exchange Rates'],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Date (Y-m-d), default: today', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Wise rates',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'rates', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'number')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 500, description: 'Wise API error'),
        ]
    )]
    /**
     * @see \App\Tests\Controller\ExchangeRatesTest
     * @tested testWiseRates_returnsCorrectShape
     * @tested testWiseRates_withDateParam_returnsRates
     * @tested testWiseRates_providerError_returns500
     * @tested testWiseRates_withoutAuth_returns401
     */
    public function wiseRates(
        WiseProvider $wiseProvider,
        #[MapCarbonDate(format: 'Y-m-d', default: 'today')] CarbonImmutable $date,
    ): View {
        try {
            $rates = $wiseProvider->getRates($date);
        } catch (InvalidArgumentException) {
            return $this->view(
                ['error' => 'An error occurred while fetching the exchange rates. Please try again later.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->view(compact('rates'), Response::HTTP_OK);
    }
}
