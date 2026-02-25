<?php

namespace App\Entity;

use App\Repository\ExchangeRateSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExchangeRateSnapshotRepository::class)]
#[ORM\Table(
    name: 'exchange_rate_snapshot',
    indexes: [
        new ORM\Index(
            columns: ['effective_at'],
            name: 'idx_exchange_rate_effective_at'
        ),
    ]
)]
class ExchangeRateSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $effectiveAt = null;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 18,
        scale: 8,
        nullable: true,
        options: ['comment' => '1 EUR = N USD']
    )]
    private ?string $usdPerEur = null;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 18,
        scale: 6,
        nullable: true,
        options: ['comment' => '1 EUR = N HUF']
    )]
    private ?string $hufPerEur = null;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 18,
        scale: 8,
        nullable: true,
        options: ['comment' => '1 EUR = N UAH']
    )]
    private ?string $uahPerEur = null;

    #[ORM\Column(
        name: 'eur_per_btc',
        type: Types::DECIMAL,
        precision: 24,
        scale: 10,
        nullable: true,
        options: ['comment' => '1 BTC = N EUR']
    )]
    private ?string $eurPerBtc = null;

    #[ORM\Column(
        name: 'eur_per_eth',
        type: Types::DECIMAL,
        precision: 24,
        scale: 10,
        nullable: true,
        options: ['comment' => '1 ETH = N EUR']
    )]
    private ?string $eurPerEth = null;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEffectiveAt(): ?\DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    public function setEffectiveAt(\DateTimeImmutable $effectiveAt): self
    {
        $this->effectiveAt = $effectiveAt;

        return $this;
    }

    public function getUsdPerEur(): ?string
    {
        return $this->usdPerEur;
    }

    public function setUsdPerEur(?string $usdPerEur): self
    {
        $this->usdPerEur = $usdPerEur;

        return $this;
    }

    public function getHufPerEur(): ?string
    {
        return $this->hufPerEur;
    }

    public function setHufPerEur(?string $hufPerEur): self
    {
        $this->hufPerEur = $hufPerEur;

        return $this;
    }

    public function getUahPerEur(): ?string
    {
        return $this->uahPerEur;
    }

    public function setUahPerEur(?string $uahPerEur): self
    {
        $this->uahPerEur = $uahPerEur;

        return $this;
    }

    public function getEurPerBtc(): ?string
    {
        return $this->eurPerBtc;
    }

    public function setEurPerBtc(?string $eurPerBtc): self
    {
        $this->eurPerBtc = $eurPerBtc;

        return $this;
    }

    public function getEurPerEth(): ?string
    {
        return $this->eurPerEth;
    }

    public function setEurPerEth(?string $eurPerEth): self
    {
        $this->eurPerEth = $eurPerEth;

        return $this;
    }

    public function getUsdPerEurFloat(): ?float
    {
        return $this->usdPerEur !== null ? (float)$this->usdPerEur : null;
    }

    public function getEurPerUsdFloat(): ?float
    {
        $usdPerEur = $this->getUsdPerEurFloat();

        if ($usdPerEur === null || $usdPerEur == 0.0) {
            return null;
        }

        return 1.0 / $usdPerEur;
    }

    public function getHufPerEurFloat(): ?float
    {
        return $this->hufPerEur !== null ? (float)$this->hufPerEur : null;
    }

    public function getEurPerHufFloat(): ?float
    {
        $hufPerEur = $this->getHufPerEurFloat();

        if ($hufPerEur === null || $hufPerEur == 0.0) {
            return null;
        }

        return 1.0 / $hufPerEur;
    }

    public function getUahPerEurFloat(): ?float
    {
        return $this->uahPerEur !== null ? (float)$this->uahPerEur : null;
    }

    public function getEurPerUahFloat(): ?float
    {
        $uahPerEur = $this->getUahPerEurFloat();

        if ($uahPerEur === null || $uahPerEur == 0.0) {
            return null;
        }

        return 1.0 / $uahPerEur;
    }

    public function getEurPerBtcFloat(): ?float
    {
        return $this->eurPerBtc !== null ? (float)$this->eurPerBtc : null;
    }

    public function getBtcPerEurFloat(): ?float
    {
        $eurPerBtc = $this->getEurPerBtcFloat();

        if ($eurPerBtc === null || $eurPerBtc == 0.0) {
            return null;
        }

        return 1.0 / $eurPerBtc;
    }

    public function getEurPerEthFloat(): ?float
    {
        return $this->eurPerEth !== null ? (float)$this->eurPerEth : null;
    }

    public function getEthPerEurFloat(): ?float
    {
        $eurPerEth = $this->getEurPerEthFloat();

        if ($eurPerEth === null || $eurPerEth == 0.0) {
            return null;
        }

        return 1.0 / $eurPerEth;
    }

    public function getRateFromEur(string $currency): ?float
    {
        $code = strtoupper($currency);

        return match ($code) {
            'EUR' => 1.0,
            'USD' => $this->getUsdPerEurFloat(),
            'HUF' => $this->getHufPerEurFloat(),
            'UAH' => $this->getUahPerEurFloat(),
            'BTC' => $this->getBtcPerEurFloat(),
            'ETH' => $this->getEthPerEurFloat(),
            default => null,
        };
    }

    public function getRateToEur(string $currency): ?float
    {
        $code = strtoupper($currency);

        return match ($code) {
            'EUR' => 1.0,
            'USD' => $this->getEurPerUsdFloat(),
            'HUF' => $this->getEurPerHufFloat(),
            'UAH' => $this->getEurPerUahFloat(),
            'BTC' => $this->getEurPerBtcFloat(),
            'ETH' => $this->getEurPerEthFloat(),
            default => null,
        };
    }

    public function convert(float $amount, string $fromCurrency, string $toCurrency): ?float
    {
        $from = strtoupper($fromCurrency);
        $to = strtoupper($toCurrency);

        if ($from === $to) {
            return $amount;
        }

        $rateToEur = $this->getRateToEur($from);   // 1 FROM = N EUR
        $rateFromEur = $this->getRateFromEur($to);   // 1 EUR = N TO

        if ($rateToEur === null || $rateFromEur === null) {
            return null;
        }

        // amount (FROM) -> EUR -> TO
        $eurAmount = $amount * $rateToEur;

        return $eurAmount * $rateFromEur;
    }

    public function hasFiatRates(): bool
    {
        return $this->usdPerEur !== null
            || $this->hufPerEur !== null
            || $this->uahPerEur !== null;
    }

    public function hasCryptoRates(): bool
    {
        return $this->eurPerBtc !== null
            || $this->eurPerEth !== null;
    }

    public function getAvailableCurrencies(): array
    {
        $currencies = ['EUR'];

        if ($this->usdPerEur !== null) {
            $currencies[] = 'USD';
        }
        if ($this->hufPerEur !== null) {
            $currencies[] = 'HUF';
        }
        if ($this->uahPerEur !== null) {
            $currencies[] = 'UAH';
        }
        if ($this->eurPerBtc !== null) {
            $currencies[] = 'BTC';
        }
        if ($this->eurPerEth !== null) {
            $currencies[] = 'ETH';
        }

        return $currencies;
    }
}
