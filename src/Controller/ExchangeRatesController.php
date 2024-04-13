<?php

namespace App\Controller;

use App\Service\FixerService;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/exchange-rates', name: 'api_v2_exchange_rates_')]
class ExchangeRatesController extends AbstractFOSRestController
{
    public function __construct(
        private readonly FixerService $fixerService
    ) {
    }

    #[Rest\QueryParam(name: 'date', description: 'After date', nullable: true)]
    #[ParamConverter('date', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'today'])]
    #[Route('', name: 'historical', methods: ['get'])]
    public function historical(FixerService $fixerService, CarbonImmutable $date): View
    {
        $rates = $fixerService->getHistorical($date);

        return $this->view(compact('rates'));
    }
}
