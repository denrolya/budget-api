<?php

declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $now = CarbonImmutable::parse('2019-01-01');
        $user = $this->getReference('test_user', User::class);

        // --- Expense categories ---
        $foodAndDrinks = new ExpenseCategory();
        $foodAndDrinks->setName('Food & Drinks')->setIsAffectingProfit(true)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($foodAndDrinks);

        $groceries = new ExpenseCategory();
        $groceries->setName('Groceries')->setIsAffectingProfit(true)
            ->setOwner($user)->setRoot($foodAndDrinks)->setParent($foodAndDrinks)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($groceries);

        $eatingOut = new ExpenseCategory();
        $eatingOut->setName('Eating Out')->setIsAffectingProfit(true)
            ->setOwner($user)->setRoot($foodAndDrinks)->setParent($foodAndDrinks)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($eatingOut);

        $rent = new ExpenseCategory();
        $rent->setName('Rent')->setIsAffectingProfit(true)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($rent);

        $expenseTransfer = new ExpenseCategory();
        $expenseTransfer->setName('Transfer')->setIsAffectingProfit(false)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($expenseTransfer);

        $expenseDebt = new ExpenseCategory();
        $expenseDebt->setName('Debt')->setIsAffectingProfit(false)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($expenseDebt);

        $expenseTransferFee = new ExpenseCategory();
        $expenseTransferFee->setName('Transfer Fee')->setIsAffectingProfit(false)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($expenseTransferFee);

        // --- Income categories ---
        $salary = new IncomeCategory();
        $salary->setName('Salary')->setIsAffectingProfit(true)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($salary);

        $bonus = new IncomeCategory();
        $bonus->setName('Bonus')->setIsAffectingProfit(true)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($bonus);

        $incomeTransfer = new IncomeCategory();
        $incomeTransfer->setName('Transfer')->setIsAffectingProfit(false)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($incomeTransfer);

        $incomeDebt = new IncomeCategory();
        $incomeDebt->setName('Debt')->setIsAffectingProfit(false)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($incomeDebt);

        $compensation = new IncomeCategory();
        $compensation->setName('Compensation')->setIsAffectingProfit(false)
            ->setOwner($user)->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($compensation);

        $manager->flush();

        // Insert the hardcoded "Unknown" catch-all categories used by BankSyncService and
        // BankWebhookService (Category::EXPENSE_CATEGORY_ID_UNKNOWN = 17, INCOME = 39).
        // Raw DBAL is used to force the exact IDs that the services look up.
        $conn = $manager->getConnection();
        $rows = $conn->fetchAllAssociative('SELECT id FROM category WHERE id IN (17, 39)');
        $existingIds = array_column($rows, 'id');
        $ts = $now->format('Y-m-d H:i:s');
        $userId = $user->getId();

        if (!\in_array(17, $existingIds, true)) {
            $conn->executeStatement(
                'INSERT INTO category (id, type, name, is_affecting_profit, owner_id, created_at, updated_at) VALUES (17, \'expense\', \'Unknown\', 0, ?, ?, ?)',
                [$userId, $ts, $ts],
            );
        }

        if (!\in_array(39, $existingIds, true)) {
            $conn->executeStatement(
                'INSERT INTO category (id, type, name, is_affecting_profit, owner_id, created_at, updated_at) VALUES (39, \'income\', \'Unknown\', 0, ?, ?, ?)',
                [$userId, $ts, $ts],
            );
        }

        $this->addReference('cat_exp_food', $foodAndDrinks);
        $this->addReference('cat_exp_groceries', $groceries);
        $this->addReference('cat_exp_eating_out', $eatingOut);
        $this->addReference('cat_exp_rent', $rent);
        $this->addReference('cat_exp_transfer', $expenseTransfer);
        $this->addReference('cat_exp_debt', $expenseDebt);
        $this->addReference('cat_exp_transfer_fee', $expenseTransferFee);
        $this->addReference('cat_inc_salary', $salary);
        $this->addReference('cat_inc_bonus', $bonus);
        $this->addReference('cat_inc_transfer', $incomeTransfer);
        $this->addReference('cat_inc_debt', $incomeDebt);
        $this->addReference('cat_inc_compensation', $compensation);
    }

    public function getDependencies(): array
    {
        return [UserAndAccountFixtures::class];
    }
}
