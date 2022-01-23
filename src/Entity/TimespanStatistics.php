<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    collectionOperations: [
        'moneyFlow' => [
            'method' => 'GET',
            'path' => '/money-flow'
        ],
        'incomeExpense' => [
            'method' => 'GET',
            'path' => '/income-expense'
        ],
        'expenseCategoriesTree' => [
            'method' => 'GET',
            'path' => '/expense-categories-tree'
        ],
        'foodExpenses' => [
            'method' => 'GET',
            'path' => '/food-expenses',
        ]
    ],
    itemOperations: [
      'get' => [
          'method' => 'GET',
          'path' => '/{id}',
          'controller' => NotFoundAction::class,
          'read' => false,
          'output' => false,
      ]
    ],
    shortName: 'statistics',
    normalizationContext: ['groups' => ['statistics:item:read']],
    paginationEnabled: false,
    routePrefix: '/statistics'
)]
class TimespanStatistics
{
    public CarbonInterface $from;

    public CarbonInterface $to;

    #[Groups(['statistics:item:read'])]
    public ?CarbonInterval $interval;

    #[Groups(['statistics:item:read'])]
    public mixed $data;

    public function __construct(CarbonInterface $from, CarbonInterface $to, ?CarbonInterval $interval = null, mixed $data = null)
    {
        $this->from = $from;
        $this->to = $to;
        $this->interval = $interval;
        $this->data = $data;
    }

    #[Groups(['statistics:item:read'])]
    #[ApiProperty(identifier: true)]
    public function getId(): string
    {
        $id = 'F' . $this->getFrom() . 'T' . $this->getTo();

        return $this->interval !== null ? $id . $this->getInterval() : $id;
    }

    #[Groups(['statistics:item:read'])]
    public function getFrom(): string
    {
        return $this->from->format('d-m-Y');
    }

    #[Groups(['statistics:item:read'])]
    public function getTo(): string
    {
        return $this->to->format('d-m-Y');
    }

    public function getInterval(): ?string
    {
        return $this->interval?->format('P%yY%mM%dDT%hH%iM%sS');
    }
}
