<?php

namespace App\DataFixtures\Dev;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class BudgetFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $now = CarbonImmutable::now();

        // Fetch root expense categories by name
        $expRepo = $manager->getRepository(ExpenseCategory::class);
        $incRepo = $manager->getRepository(IncomeCategory::class);

        $catFood       = $expRepo->findOneBy(['name' => 'Food & Drinks', 'parent' => null]);
        $catHousing    = $expRepo->findOneBy(['name' => 'Housing', 'parent' => null]);
        $catTransport  = $expRepo->findOneBy(['name' => 'Transportation', 'parent' => null]);
        $catShopping   = $expRepo->findOneBy(['name' => 'Shopping', 'parent' => null]);
        $catHealth     = $expRepo->findOneBy(['name' => 'Health', 'parent' => null]);

        $catSalary     = $incRepo->findOneBy(['name' => 'Salary', 'parent' => null]);
        $catBonus      = $incRepo->findOneBy(['name' => 'Bonus', 'parent' => null]);

        // ── Monthly budget: current month ─────────────────────────────────────
        $currentMonth = new Budget();
        $currentMonth
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate($now->startOfMonth())
            ->setEndDate($now->endOfMonth())
            ->setName(null);

        $this->addExpenseLine($currentMonth, $catFood, '600.00', 'EUR', $manager);
        $this->addExpenseLine($currentMonth, $catHousing, '1200.00', 'EUR', $manager);
        $this->addExpenseLine($currentMonth, $catTransport, '150.00', 'EUR', $manager);
        $this->addExpenseLine($currentMonth, $catShopping, '200.00', 'EUR', $manager);
        $this->addExpenseLine($currentMonth, $catHealth, '100.00', 'EUR', $manager);
        $this->addExpenseLine($currentMonth, $catSalary, '3500.00', 'EUR', $manager);
        $this->addExpenseLine($currentMonth, $catBonus, '500.00', 'EUR', $manager);

        $manager->persist($currentMonth);

        // ── Monthly budget: previous month ────────────────────────────────────
        $prevMonthStart = $now->subMonths(1)->startOfMonth();
        $prevMonthEnd   = $now->subMonths(1)->endOfMonth();

        $prevMonth = new Budget();
        $prevMonth
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate($prevMonthStart)
            ->setEndDate($prevMonthEnd)
            ->setName(null);

        $this->addExpenseLine($prevMonth, $catFood, '550.00', 'EUR', $manager);
        $this->addExpenseLine($prevMonth, $catHousing, '1200.00', 'EUR', $manager);
        $this->addExpenseLine($prevMonth, $catTransport, '120.00', 'EUR', $manager);
        $this->addExpenseLine($prevMonth, $catSalary, '3500.00', 'EUR', $manager);

        $manager->persist($prevMonth);

        // ── Yearly budget: current year ───────────────────────────────────────
        $yearBudget = new Budget();
        $yearBudget
            ->setPeriodType(Budget::PERIOD_YEARLY)
            ->setStartDate($now->startOfYear())
            ->setEndDate($now->endOfYear())
            ->setName(null);

        $this->addExpenseLine($yearBudget, $catFood, '7200.00', 'EUR', $manager);
        $this->addExpenseLine($yearBudget, $catHousing, '14400.00', 'EUR', $manager);
        $this->addExpenseLine($yearBudget, $catTransport, '1800.00', 'EUR', $manager);
        $this->addExpenseLine($yearBudget, $catShopping, '2400.00', 'EUR', $manager);
        $this->addExpenseLine($yearBudget, $catHealth, '1200.00', 'EUR', $manager);
        $this->addExpenseLine($yearBudget, $catSalary, '42000.00', 'EUR', $manager);
        $this->addExpenseLine($yearBudget, $catBonus, '3000.00', 'EUR', $manager);

        $manager->persist($yearBudget);

        $manager->flush();
    }

    private function addExpenseLine(
        Budget $budget,
        ?object $category,
        string $amount,
        string $currency,
        ObjectManager $manager,
    ): void {
        if (!$category) {
            return;
        }

        $line = new BudgetLine();
        $line->setCategory($category);
        $line->setPlannedAmount($amount);
        $line->setPlannedCurrency($currency);
        $budget->addLine($line);
        $manager->persist($line);
    }

    public function getDependencies(): array
    {
        return [
            ExpenseCategoryFixtures::class,
            IncomeCategoryFixtures::class,
        ];
    }
}
