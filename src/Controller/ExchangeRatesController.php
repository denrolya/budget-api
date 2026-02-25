<?php

namespace App\Controller;

use App\Entity\ExchangeRateSnapshot;
use App\Repository\ExchangeRateSnapshotRepository;
use App\Service\FixerService;
use App\Service\MonobankService;
use App\Service\WiseService;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Psr\Cache\InvalidArgumentException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;

#[Route('/api/v2/exchange-rates', name: 'api_v2_exchange_rates_')]
class ExchangeRatesController extends AbstractFOSRestController
{

    /**
     * Returns snapshots for a single date or a date range.
     *
     * Query parameters:
     *   - from (required): start date in YYYY-MM-DD
     *   - to   (optional): end date in YYYY-MM-DD; if omitted, "from" is used as a single day
     *
     * Example:
     *   GET /api/v2/exchange-rates/snapshots?from=2020-01-01&to=2020-12-31
     *   GET /api/v2/exchange-rates/snapshots?from=2020-01-01
     */
    #[Route('/snapshots', name: 'snapshots', methods: ['get'])]
    public function snapshots(
        Request $request,
        ExchangeRateSnapshotRepository $snapshotRepository
    ): View {
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        if ($fromParam === null && $toParam === null) {
            return $this->view(
                [
                    'error' => 'Either "from" or "from" and "to" query parameters are required (YYYY-MM-DD).',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            if ($fromParam !== null) {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $fromParam);
                if ($from === false) {
                    throw new \RuntimeException('Invalid "from" date format.');
                }
            } else {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $toParam);
                if ($from === false) {
                    throw new \RuntimeException('Invalid "to" date format.');
                }
            }

            if ($toParam !== null) {
                $to = CarbonImmutable::createFromFormat('Y-m-d', $toParam);
                if ($to === false) {
                    throw new \RuntimeException('Invalid "to" date format.');
                }
            } else {
                $to = $from;
            }
        } catch (\Throwable $e) {
            return $this->view(
                ['error' => 'Invalid date format. Expected YYYY-MM-DD.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        // Normalize to full-day range (assuming effectiveAt is stored as 00:00 for the day)
        $fromDateTime = $from->startOfDay();
        $toDateTime = $to->endOfDay();

        $snapshots = $snapshotRepository->findSnapshotsInRange($fromDateTime, $toDateTime);

        return $this->view(
            [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'snapshots' => $snapshots,
            ],
            Response::HTTP_OK
        );
    }

    #[Rest\QueryParam(name: 'date', description: 'After date', nullable: true)]
    #[ParamConverter('date', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'today'])]
    #[Route('', name: 'historical', methods: ['get'])] // Still used by V1 frontend app
    #[Route('/fixer', name: 'fixer_rates', methods: ['get'])]
    public function fixerRates(
        FixerService $fixerService,
        CarbonImmutable $date
    ): View {
        try {
            $rates = $fixerService->getHistorical($date);
        } catch (InvalidArgumentException $e) {
            return $this->view(
                ['error' => 'An error occurred while fetching the exchange rates. Please try again later.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->view(compact('rates'), Response::HTTP_OK);
    }

    #[Route('/monobank', name: 'monobank_rates', methods: ['get'])]
    public function monobankRates(MonobankService $monobankService): View
    {
        try {
            $rates = $monobankService->getLatest();
        } catch (InvalidArgumentException $e) {
            return $this->view(
                ['error' => 'An error occurred while fetching the exchange rates. Please try again later.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->view(compact('rates'), Response::HTTP_OK);
    }

    #[Rest\QueryParam(name: 'date', description: 'After date', nullable: true)]
    #[ParamConverter('date', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'today'])]
    #[Route('/wise', name: 'wise_rates', methods: ['get'])]
    public function wiseRates(WiseService $wiseService, CarbonImmutable $date): View
    {
        try {
            $rates = $wiseService->getHistorical($date);
        } catch (InvalidArgumentException $e) {
            return $this->view(
                ['error' => 'An error occurred while fetching the exchange rates. Please try again later.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->view(compact('rates'), Response::HTTP_OK);
    }
}
