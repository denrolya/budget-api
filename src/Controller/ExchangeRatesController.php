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
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/exchange-rates', name: 'api_v2_exchange_rates_')]
class ExchangeRatesController extends AbstractFOSRestController
{
    /**
     * TODO: remove and use API Platform?
     *
     * @see \App\Tests\Controller\ExchangeRatesTest
     * @tested testSnapshots_returnsCorrectShape
     * @tested testSnapshots_swappedDates_autoCorrects
     * @tested testSnapshots_singleDate_beforeDefaultsToAfter
     * @tested testSnapshots_withoutAuth_returns401
     */
    #[Rest\QueryParam(name: 'after', description: 'Start date (Y-m-d), required', nullable: false)]
    #[Rest\QueryParam(name: 'before', description: 'End date (Y-m-d), defaults to after', nullable: true)]
    #[Route('/snapshots', name: 'snapshots', methods: ['get'])]
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

    /**
     * Returns exchange rates for a given date.
     * Now routes through ExchangeRateSnapshotResolver: checks DB first, calls Fixer only if no snapshot exists.
     *
     * @see \App\Tests\Controller\ExchangeRatesTest
     * @tested testFixer_returnsRatesShape
     * @tested testFixerBaseUrl_alsoWorks
     * @tested testFixer_withoutAuth_returns401
     */
    #[Rest\QueryParam(name: 'date', description: 'Date (Y-m-d)', nullable: true)]
    #[Route('', name: 'historical', methods: ['get'])]
    #[Route('/fixer', name: 'fixer_rates', methods: ['get'])]
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

    /**
     * @see \App\Tests\Controller\ExchangeRatesTest
     * @tested testMonobankRates_returnsCorrectShape
     * @tested testMonobankRates_providerError_returns500
     * @tested testMonobankRates_withoutAuth_returns401
     */
    #[Route('/monobank', name: 'monobank_rates', methods: ['get'])]
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

    /**
     * @see \App\Tests\Controller\ExchangeRatesTest
     * @tested testWiseRates_returnsCorrectShape
     * @tested testWiseRates_withDateParam_returnsRates
     * @tested testWiseRates_providerError_returns500
     * @tested testWiseRates_withoutAuth_returns401
     */
    #[Rest\QueryParam(name: 'date', description: 'Date (Y-m-d)', nullable: true)]
    #[Route('/wise', name: 'wise_rates', methods: ['get'])]
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
