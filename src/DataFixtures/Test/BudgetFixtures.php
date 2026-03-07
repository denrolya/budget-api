<?php

namespace App\DataFixtures\Test;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Creates two budgets for testing:
 *  - budget_january_2021: monthly budget for Jan 2021 (matches TransactionFixtures period)
 *  - budget_yearly_2021:  yearly budget for 2021
 *
 * Lines reference categories created by CategoryFixtures.
 */
class BudgetFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $foodCat = $this->getReference('cat_exp_food', ExpenseCategory::class);
        $groceriesCat = $this->getReference('cat_exp_groceries', ExpenseCategory::class);
        $rentCat = $this->getReference('cat_exp_rent', ExpenseCategory::class);
        $salaryCat = $this->getReference('cat_inc_salary', IncomeCategory::class);
        $bonusCat = $this->getReference('cat_inc_bonus', IncomeCategory::class);

        // ── Monthly budget: January 2021 ──────────────────────────────────────
        $jan2021 = new Budget();
        $jan2021->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-01-31'))
            ->setName('January 2021');

        $lineFood = new BudgetLine();
        $lineFood->setCategory($foodCat)->setPlannedAmount('500.00')->setPlannedCurrency('EUR');
        $jan2021->addLine($lineFood);

        $lineGroceries = new BudgetLine();
        $lineGroceries->setCategory($groceriesCat)->setPlannedAmount('300.00')->setPlannedCurrency('EUR');
        $jan2021->addLine($lineGroceries);

        $lineRent = new BudgetLine();
        $lineRent->setCategory($rentCat)->setPlannedAmount('800.00')->setPlannedCurrency('EUR');
        $jan2021->addLine($lineRent);

        $lineSalary = new BudgetLine();
        $lineSalary->setCategory($salaryCat)->setPlannedAmount('3000.00')->setPlannedCurrency('EUR');
        $jan2021->addLine($lineSalary);

        $manager->persist($jan2021);

        // ── Yearly budget: 2021 ───────────────────────────────────────────────
        $year2021 = new Budget();
        $year2021->setPeriodType(Budget::PERIOD_YEARLY)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-12-31'))
            ->setName('Full Year 2021');

        $lineFoodYear = new BudgetLine();
        $lineFoodYear->setCategory($foodCat)->setPlannedAmount('6000.00')->setPlannedCurrency('EUR');
        $year2021->addLine($lineFoodYear);

        $lineRentYear = new BudgetLine();
        $lineRentYear->setCategory($rentCat)->setPlannedAmount('9600.00')->setPlannedCurrency('EUR');
        $year2021->addLine($lineRentYear);

        $lineSalaryYear = new BudgetLine();
        $lineSalaryYear->setCategory($salaryCat)->setPlannedAmount('36000.00')->setPlannedCurrency('EUR');
        $year2021->addLine($lineSalaryYear);

        $lineBonusYear = new BudgetLine();
        $lineBonusYear->setCategory($bonusCat)->setPlannedAmount('3000.00')->setPlannedCurrency('EUR');
        $year2021->addLine($lineBonusYear);

        $manager->persist($year2021);

        $manager->flush();

        $this->addReference('budget_january_2021', $jan2021);
        $this->addReference('budget_yearly_2021', $year2021);
    }

    public function getDependencies(): array
    {
        return [CategoryFixtures::class];
    }
}
