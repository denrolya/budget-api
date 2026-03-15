<?php

declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Creates budgets covering the main test scenarios:
 *
 *  - budget_january_2021:  monthly, 4 EUR lines + 1 UAH line (matches TransactionFixtures)
 *  - budget_yearly_2021:   yearly, 4 lines with notes
 *  - budget_custom_q1:     custom period (Q1 2021), multi-currency lines
 *  - budget_empty:         monthly with zero lines — edge-case for analytics
 */
class BudgetFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $owner = $this->getReference('test_user', User::class);

        $foodCategory = $this->getReference('cat_exp_food', ExpenseCategory::class);
        $groceriesCategory = $this->getReference('cat_exp_groceries', ExpenseCategory::class);
        $eatingOutCategory = $this->getReference('cat_exp_eating_out', ExpenseCategory::class);
        $rentCategory = $this->getReference('cat_exp_rent', ExpenseCategory::class);
        $salaryCategory = $this->getReference('cat_inc_salary', IncomeCategory::class);
        $bonusCategory = $this->getReference('cat_inc_bonus', IncomeCategory::class);

        $this->createMonthlyJanuary2021(
            $manager,
            $owner,
            $foodCategory,
            $groceriesCategory,
            $eatingOutCategory,
            $rentCategory,
            $salaryCategory,
        );

        $this->createYearly2021(
            $manager,
            $owner,
            $foodCategory,
            $rentCategory,
            $salaryCategory,
            $bonusCategory,
        );

        $this->createCustomQuarter(
            $manager,
            $owner,
            $groceriesCategory,
            $eatingOutCategory,
            $rentCategory,
            $salaryCategory,
        );

        $this->createEmptyBudget($manager, $owner);

        $manager->flush();
    }

    /**
     * Monthly budget for Jan 2021 — matches TransactionFixtures period.
     * Includes a UAH line to test multi-currency budget analytics.
     */
    private function createMonthlyJanuary2021(
        ObjectManager $manager,
        User $owner,
        ExpenseCategory $foodCategory,
        ExpenseCategory $groceriesCategory,
        ExpenseCategory $eatingOutCategory,
        ExpenseCategory $rentCategory,
        IncomeCategory $salaryCategory,
    ): void {
        $budget = new Budget();
        $budget->setOwner($owner);
        $budget->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-01-31'))
            ->setName('January 2021');

        $this->addLine($budget, $foodCategory, '500.00', 'EUR');
        $this->addLine($budget, $groceriesCategory, '300.00', 'EUR');
        $this->addLine($budget, $rentCategory, '800.00', 'EUR');
        $this->addLine($budget, $salaryCategory, '3000.00', 'EUR');
        $this->addLine($budget, $eatingOutCategory, '15000.00', 'UAH', 'UAH eating out portion');

        $manager->persist($budget);
        $this->addReference('budget_january_2021', $budget);
    }

    /**
     * Yearly budget for 2021 — lines include notes for note CRUD testing.
     */
    private function createYearly2021(
        ObjectManager $manager,
        User $owner,
        ExpenseCategory $foodCategory,
        ExpenseCategory $rentCategory,
        IncomeCategory $salaryCategory,
        IncomeCategory $bonusCategory,
    ): void {
        $budget = new Budget();
        $budget->setOwner($owner);
        $budget->setPeriodType(Budget::PERIOD_YEARLY)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-12-31'))
            ->setName('Full Year 2021');

        $this->addLine($budget, $foodCategory, '6000.00', 'EUR', 'Includes eating out and groceries');
        $this->addLine($budget, $rentCategory, '9600.00', 'EUR', 'Monthly rent × 12');
        $this->addLine($budget, $salaryCategory, '36000.00', 'EUR');
        $this->addLine($budget, $bonusCategory, '3000.00', 'EUR', 'Expected annual bonus');

        $manager->persist($budget);
        $this->addReference('budget_yearly_2021', $budget);
    }

    /**
     * Custom-period budget (Q1 2021) — tests the PERIOD_CUSTOM type
     * and multi-currency lines (EUR + UAH).
     */
    private function createCustomQuarter(
        ObjectManager $manager,
        User $owner,
        ExpenseCategory $groceriesCategory,
        ExpenseCategory $eatingOutCategory,
        ExpenseCategory $rentCategory,
        IncomeCategory $salaryCategory,
    ): void {
        $budget = new Budget();
        $budget->setOwner($owner);
        $budget->setPeriodType(Budget::PERIOD_CUSTOM)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-03-31'))
            ->setName('Q1 2021');

        $this->addLine($budget, $groceriesCategory, '900.00', 'EUR');
        $this->addLine($budget, $eatingOutCategory, '450.00', 'EUR');
        $this->addLine($budget, $rentCategory, '25000.00', 'UAH', 'UAH rent for 3 months');
        $this->addLine($budget, $salaryCategory, '120000.00', 'UAH');

        $manager->persist($budget);
        $this->addReference('budget_custom_q1_2021', $budget);
    }

    /**
     * Empty budget with no lines — edge case for analytics and history-averages endpoints.
     */
    private function createEmptyBudget(ObjectManager $manager, User $owner): void
    {
        $budget = new Budget();
        $budget->setOwner($owner);
        $budget->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2020-06-01'))
            ->setEndDate(new \DateTimeImmutable('2020-06-30'))
            ->setName('Empty June 2020');

        $manager->persist($budget);
        $this->addReference('budget_empty', $budget);
    }

    private function addLine(
        Budget $budget,
        ExpenseCategory|IncomeCategory $category,
        string $amount,
        string $currency,
        ?string $note = null,
    ): void {
        $line = new BudgetLine();
        $line->setCategory($category);
        $line->setPlannedAmount($amount);
        $line->setPlannedCurrency($currency);
        $line->setNote($note);
        $budget->addLine($line);
    }

    public function getDependencies(): array
    {
        return [
            UserAndAccountFixtures::class,
            CategoryFixtures::class,
        ];
    }
}
