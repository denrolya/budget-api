<?php

namespace App\Controller;

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


#[Route('/api/v2/exchange-rates', name: 'api_v2_exchange_rates_')]
class ExchangeRatesController extends AbstractFOSRestController
{
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
