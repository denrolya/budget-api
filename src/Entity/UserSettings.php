<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Embeddable
 */
class UserSettings
{
    /**
     * Stored as string cause Symfony doesn't support relations in Embedded
     * @var string
     *
     * @ORM\Column(type="string", length=5, nullable=false)
     */
    private $baseCurrency = 'EUR';

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $uiTheme = 'primary';

    /**
     * @var array
     *
     * @ORM\Column(type="json_array")
     */
    private $dashboardStatistics;

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

    public function setBaseCurrency(string $currencyCode): static
    {
        $this->baseCurrency = $currencyCode;

        return $this;
    }

    public function getUiTheme(): string
    {
        return $this->uiTheme;
    }

    public function setUiTheme(string $uiTheme): UserSettings
    {
        $this->uiTheme = $uiTheme;

        return $this;
    }

    public function getDashboardStatistics(): array
    {
        return $this->dashboardStatistics;
    }

    public function setDashboardStatistics(array $dashboardStatistics): UserSettings
    {
        $this->dashboardStatistics = $dashboardStatistics;

        return $this;
    }
}
