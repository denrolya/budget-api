<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation\Groups;

#[ORM\Embeddable]
class UserSettings
{
    #[ORM\Column(type: 'string', length: 5)]
    #[Groups(['user:write'])]
    private string $baseCurrency = 'EUR';

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    #[Groups(['user:write'])]
    private ?string $uiTheme = 'primary';

    #[ORM\Column(type: 'json')]
    #[Groups(['user:write'])]
    private ?array $dashboardStatistics;

    public function __construct()
    {
        $this->dashboardStatistics = [
            'moneyFlow',
            'expenseCategoriesTree',
            'shortExpenseForGivenPeriod',
        ];
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function setBaseCurrency(string $currencyCode): self
    {
        $this->baseCurrency = $currencyCode;

        return $this;
    }

    public function getUiTheme(): ?string
    {
        return $this->uiTheme;
    }

    public function setUiTheme(?string $uiTheme): self
    {
        $this->uiTheme = $uiTheme;

        return $this;
    }

    public function getDashboardStatistics(): ?array
    {
        return $this->dashboardStatistics;
    }

    public function setDashboardStatistics(?array $dashboardStatistics): self
    {
        $this->dashboardStatistics = $dashboardStatistics;

        return $this;
    }
}
