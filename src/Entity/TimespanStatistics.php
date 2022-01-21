<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    collectionOperations: [],
    itemOperations: [
        'moneyFlow' => [
            'method' => 'GET',
            'requirements' => ['name' => 'moneyFlow'],
        ],
        'incomeExpense' => [
            'method' => 'GET',
            'requirements' => ['name' => 'incomeExpense'],
        ],
    ],
    shortName: 'statistics',
    normalizationContext: ['groups' => ['statistics:item:read']],
)]
#[ApiFilter(DateFilter::class, properties: ['from'])]
#[ApiFilter(DateFilter::class, properties: ['to'])]
#[ApiFilter(SearchFilter::class, properties: ['interval' => 'exact'])]
class TimespanStatistics
{
    #[ApiProperty(identifier: true)]
    #[Groups(['statistics:item:read'])]
    public string $name;

    public CarbonInterface $from;

    public CarbonInterface $to;

    #[Groups(['statistics:item:read'])]
    public ?CarbonInterval $interval;

    #[Groups(['statistics:item:read'])]
    public mixed $data;

    public function __construct(string $name, CarbonInterface $from, CarbonInterface $to, ?CarbonInterval $interval = null, mixed $data = null)
    {
        $this->name = $name;
        $this->from = $from;
        $this->to = $to;
        $this->interval = $interval;
        $this->data = $data;
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
}
