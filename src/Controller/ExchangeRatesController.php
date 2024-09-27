<?php

namespace App\Controller;

use App\Service\FixerService;
use App\Service\MonobankService;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/exchange-rates', name: 'api_v2_exchange_rates_')]
class ExchangeRatesController extends AbstractFOSRestController
{
    #[Rest\QueryParam(name: 'date', description: 'After date', nullable: true)]
    #[ParamConverter('date', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'today'])]
    #[Route('', name: 'historical', methods: ['get'])]
    public function historical(FixerService $fixerService, CarbonImmutable $date): View
    {
        $rates = $fixerService->getHistorical($date);

        return $this->view(compact('rates'));
    }

    #[Route('/monobank', name: 'monobank_rates', methods: ['get'])]
    public function monobankRates(MonobankService $monobankService): View
    {
        $rates = $monobankService->getMonobankRates();
        return $this->view(compact('rates'));
    }
}
