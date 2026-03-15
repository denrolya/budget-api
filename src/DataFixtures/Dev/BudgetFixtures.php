<?php

declare(strict_types=1);

namespace App\DataFixtures\Dev;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Creates a realistic set of budgets for development:
 *
 *  - Current month:  detailed EUR budget with subcategory lines and notes
 *  - Previous month: slightly different amounts to simulate month-over-month changes
 *  - Two months ago: completed budget for history-averages testing
 *  - Current year:   yearly EUR budget with all major categories
 *  - Q1 custom:      custom-period multi-currency budget (EUR + UAH)
 *  - Empty budget:   edge case — budget with no lines
 */
class BudgetFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $owner = $this->getReference('dev_user', User::class);
        $now = CarbonImmutable::now();

        $expenseRepository = $manager->getRepository(ExpenseCategory::class);
        $incomeRepository = $manager->getRepository(IncomeCategory::class);

        $foodCategory = $expenseRepository->findOneBy(['name' => 'Food & Drinks', 'parent' => null]);
        $groceriesCategory = $expenseRepository->findOneBy(['name' => 'Groceries']);
        $eatingOutCategory = $expenseRepository->findOneBy(['name' => 'Eating Out']);
        $housingCategory = $expenseRepository->findOneBy(['name' => 'Housing', 'parent' => null]);
        $rentCategory = $expenseRepository->findOneBy(['name' => 'Rent']);
        $utilitiesCategory = $expenseRepository->findOneBy(['name' => 'Utilities']);
        $internetCategory = $expenseRepository->findOneBy(['name' => 'Internet']);
        $transportCategory = $expenseRepository->findOneBy(['name' => 'Transportation', 'parent' => null]);
        $shoppingCategory = $expenseRepository->findOneBy(['name' => 'Shopping', 'parent' => null]);
        $healthCategory = $expenseRepository->findOneBy(['name' => 'Health', 'parent' => null]);
        $otherExpenseCategory = $expenseRepository->findOneBy(['name' => 'Other', 'parent' => null]);

        $salaryCategory = $incomeRepository->findOneBy(['name' => 'Salary', 'parent' => null]);
        $bonusCategory = $incomeRepository->findOneBy(['name' => 'Bonus', 'parent' => null]);
        $spouseIncomeCategory = $incomeRepository->findOneBy(['name' => 'Spouse', 'parent' => null]);
        $rentIncomeCategory = $incomeRepository->findOneBy(['name' => 'Rent', 'parent' => null]);

        // ── Current month: detailed monthly budget ──────────────────────────────
        $currentMonth = new Budget();
        $currentMonth->setOwner($owner);
        $currentMonth
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate($now->startOfMonth())
            ->setEndDate($now->endOfMonth())
            ->setName(null);

        $this->addBudgetLine($currentMonth, $groceriesCategory, '350.00', 'EUR', 'Weekly groceries ~€87');
        $this->addBudgetLine($currentMonth, $eatingOutCategory, '200.00', 'EUR', 'Restaurants and takeout');
        $this->addBudgetLine($currentMonth, $rentCategory, '1100.00', 'EUR');
        $this->addBudgetLine($currentMonth, $utilitiesCategory, '120.00', 'EUR', 'Gas + electricity + water');
        $this->addBudgetLine($currentMonth, $internetCategory, '25.00', 'EUR');
        $this->addBudgetLine($currentMonth, $transportCategory, '80.00', 'EUR', 'Metro pass + occasional taxi');
        $this->addBudgetLine($currentMonth, $shoppingCategory, '150.00', 'EUR');
        $this->addBudgetLine($currentMonth, $healthCategory, '50.00', 'EUR');
        $this->addBudgetLine($currentMonth, $otherExpenseCategory, '100.00', 'EUR', 'Miscellaneous');
        $this->addBudgetLine($currentMonth, $salaryCategory, '3800.00', 'EUR');
        $this->addBudgetLine($currentMonth, $spouseIncomeCategory, '1200.00', 'EUR');
        $manager->persist($currentMonth);

        // ── Previous month ──────────────────────────────────────────────────────
        $previousMonthStart = $now->subMonths(1)->startOfMonth();
        $previousMonthEnd = $now->subMonths(1)->endOfMonth();

        $previousMonth = new Budget();
        $previousMonth->setOwner($owner);
        $previousMonth
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate($previousMonthStart)
            ->setEndDate($previousMonthEnd)
            ->setName(null);

        $this->addBudgetLine($previousMonth, $groceriesCategory, '320.00', 'EUR');
        $this->addBudgetLine($previousMonth, $eatingOutCategory, '180.00', 'EUR');
        $this->addBudgetLine($previousMonth, $rentCategory, '1100.00', 'EUR');
        $this->addBudgetLine($previousMonth, $utilitiesCategory, '95.00', 'EUR', 'Lower heating costs');
        $this->addBudgetLine($previousMonth, $internetCategory, '25.00', 'EUR');
        $this->addBudgetLine($previousMonth, $transportCategory, '70.00', 'EUR');
        $this->addBudgetLine($previousMonth, $shoppingCategory, '200.00', 'EUR', 'Winter sale');
        $this->addBudgetLine($previousMonth, $healthCategory, '80.00', 'EUR', 'Dentist visit');
        $this->addBudgetLine($previousMonth, $salaryCategory, '3800.00', 'EUR');
        $manager->persist($previousMonth);

        // ── Two months ago ──────────────────────────────────────────────────────
        $twoMonthsAgoStart = $now->subMonths(2)->startOfMonth();
        $twoMonthsAgoEnd = $now->subMonths(2)->endOfMonth();

        $twoMonthsAgo = new Budget();
        $twoMonthsAgo->setOwner($owner);
        $twoMonthsAgo
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate($twoMonthsAgoStart)
            ->setEndDate($twoMonthsAgoEnd)
            ->setName(null);

        $this->addBudgetLine($twoMonthsAgo, $groceriesCategory, '340.00', 'EUR');
        $this->addBudgetLine($twoMonthsAgo, $eatingOutCategory, '220.00', 'EUR');
        $this->addBudgetLine($twoMonthsAgo, $rentCategory, '1100.00', 'EUR');
        $this->addBudgetLine($twoMonthsAgo, $utilitiesCategory, '130.00', 'EUR');
        $this->addBudgetLine($twoMonthsAgo, $transportCategory, '90.00', 'EUR');
        $this->addBudgetLine($twoMonthsAgo, $salaryCategory, '3800.00', 'EUR');
        $manager->persist($twoMonthsAgo);

        // ── Yearly budget ───────────────────────────────────────────────────────
        $yearBudget = new Budget();
        $yearBudget->setOwner($owner);
        $yearBudget
            ->setPeriodType(Budget::PERIOD_YEARLY)
            ->setStartDate($now->startOfYear())
            ->setEndDate($now->endOfYear())
            ->setName($now->format('Y') . ' Annual Plan');

        $this->addBudgetLine($yearBudget, $foodCategory, '6600.00', 'EUR', 'Groceries + eating out combined');
        $this->addBudgetLine($yearBudget, $housingCategory, '15000.00', 'EUR', 'Rent + utilities + internet');
        $this->addBudgetLine($yearBudget, $transportCategory, '960.00', 'EUR');
        $this->addBudgetLine($yearBudget, $shoppingCategory, '1800.00', 'EUR');
        $this->addBudgetLine($yearBudget, $healthCategory, '600.00', 'EUR');
        $this->addBudgetLine($yearBudget, $otherExpenseCategory, '1200.00', 'EUR');
        $this->addBudgetLine($yearBudget, $salaryCategory, '45600.00', 'EUR');
        $this->addBudgetLine($yearBudget, $bonusCategory, '3000.00', 'EUR', 'Expected annual bonus');
        $this->addBudgetLine($yearBudget, $spouseIncomeCategory, '14400.00', 'EUR');
        $manager->persist($yearBudget);

        // ── Custom Q1 multi-currency budget ─────────────────────────────────────
        $customQuarter = new Budget();
        $customQuarter->setOwner($owner);
        $customQuarter
            ->setPeriodType(Budget::PERIOD_CUSTOM)
            ->setStartDate($now->startOfYear())
            ->setEndDate($now->startOfYear()->addMonths(3)->subDay())
            ->setName('Q1 ' . $now->format('Y'));

        $this->addBudgetLine($customQuarter, $groceriesCategory, '1050.00', 'EUR');
        $this->addBudgetLine($customQuarter, $eatingOutCategory, '600.00', 'EUR');
        $this->addBudgetLine($customQuarter, $housingCategory, '25000.00', 'UAH', 'UAH portion of housing costs');
        $this->addBudgetLine($customQuarter, $transportCategory, '240.00', 'EUR');
        $this->addBudgetLine($customQuarter, $salaryCategory, '11400.00', 'EUR');
        $this->addBudgetLine($customQuarter, $rentIncomeCategory, '18000.00', 'UAH', 'Rental income in UAH');
        $manager->persist($customQuarter);

        // ── Empty budget (edge case) ────────────────────────────────────────────
        $emptyBudget = new Budget();
        $emptyBudget->setOwner($owner);
        $emptyBudget
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate($now->subMonths(6)->startOfMonth())
            ->setEndDate($now->subMonths(6)->endOfMonth())
            ->setName('Empty Draft');
        $manager->persist($emptyBudget);

        $manager->flush();
    }

    private function addBudgetLine(
        Budget $budget,
        ?Category $category,
        string $amount,
        string $currency,
        ?string $note = null,
    ): void {
        if (!$category instanceof Category) {
            return;
        }

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
            UserFixtures::class,
            ExpenseCategoryFixtures::class,
            IncomeCategoryFixtures::class,
        ];
    }
}
