<?php

namespace App\Controller;

use App\Attribute\MapCarbonDate;
use App\Bank\Provider\MonobankProvider;
use App\Bank\Provider\WiseProvider;
use App\Entity\ExchangeRateSnapshot;
use App\Repository\ExchangeRateSnapshotRepository;
use App\Service\FixerService;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/exchange-rates', name: 'api_v2_exchange_rates_')]
class ExchangeRatesController extends AbstractFOSRestController
{
    /**
     * Returns snapshots for a single date or a date range.
     *
     * Query parameters:
     *   - from (required): start date in YYYY-MM-DD
     *   - to   (optional): end date in YYYY-MM-DD; if omitted, "from" is used as a single day
     */
    #[Route('/snapshots', name: 'snapshots', methods: ['get'])]
    public function snapshots(Request $request, ExchangeRateSnapshotRepository $snapshotRepository): View
    {
        $fromParam = $request->query->get('from');
        $toParam   = $request->query->get('to');

        if ($fromParam === null) {
            return $this->view(
                ['error' => 'The "from" query parameter is required (YYYY-MM-DD). Optionally also pass "to".'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $fromParam);
            if ($from === false) {
                throw new \RuntimeException('Invalid date format.');
            }

            $to = $toParam !== null ? CarbonImmutable::createFromFormat('Y-m-d', $toParam) : $from;
            if ($to === false) {
                throw new \RuntimeException('Invalid "to" date format.');
            }
        } catch (\Throwable) {
            return $this->view(
                ['error' => 'Invalid date format. Expected YYYY-MM-DD.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $snapshots = $snapshotRepository->findSnapshotsInRange($from->startOfDay(), $to->endOfDay());

        return $this->view(
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'snapshots' => $snapshots],
            Response::HTTP_OK
        );
    }

    #[Rest\QueryParam(name: 'date', description: 'Date (Y-m-d)', nullable: true)]
    #[Route('', name: 'historical', methods: ['get'])] // Still used by V1 frontend app
    #[Route('/fixer', name: 'fixer_rates', methods: ['get'])]
    public function fixerRates(
        FixerService $fixerService,
        #[MapCarbonDate(format: 'Y-m-d', default: 'today')] CarbonImmutable $date,
    ): View {
        try {
            $rates = $fixerService->getHistorical($date);
        } catch (InvalidArgumentException) {
            return $this->view(
                ['error' => 'An error occurred while fetching the exchange rates. Please try again later.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->view(compact('rates'), Response::HTTP_OK);
    }

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
