<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use DateTimeInterface;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    collectionOperations: [],
    itemOperations: [
        'get',
    ],
    normalizationContext: ['groups' => ['rates:item:read']],
)]
class ExchangeRates
{
    #[Groups(['rates:item:read'])]
    public DateTimeInterface $date;

    #[Groups(['rates:item:read'])]
    public array $rates;

    /**
     * @param DateTimeInterface $date
     * @param array $rates
     */
    public function __construct(DateTimeInterface $date, array $rates)
    {
        $this->date = $date;
        $this->rates = $rates;
    }

    #[ApiProperty(identifier: true)]
    public function getDateString(): string
    {
        return $this->date->format('d-m-Y');
    }
}
