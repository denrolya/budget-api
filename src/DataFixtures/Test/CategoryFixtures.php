<?php
namespace App\DataFixtures\Test;

use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $now = CarbonImmutable::parse('2019-01-01');

        // --- Expense categories ---
        $foodAndDrinks = new ExpenseCategory();
        $foodAndDrinks->setName('Food & Drinks')->setIsAffectingProfit(true)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($foodAndDrinks);

        $groceries = new ExpenseCategory();
        $groceries->setName('Groceries')->setIsAffectingProfit(true)
            ->setRoot($foodAndDrinks)->setParent($foodAndDrinks)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($groceries);

        $eatingOut = new ExpenseCategory();
        $eatingOut->setName('Eating Out')->setIsAffectingProfit(true)
            ->setRoot($foodAndDrinks)->setParent($foodAndDrinks)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($eatingOut);

        $rent = new ExpenseCategory();
        $rent->setName('Rent')->setIsAffectingProfit(true)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($rent);

        $expenseTransfer = new ExpenseCategory();
        $expenseTransfer->setName('Transfer')->setIsAffectingProfit(false)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($expenseTransfer);

        $expenseDebt = new ExpenseCategory();
        $expenseDebt->setName('Debt')->setIsAffectingProfit(false)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($expenseDebt);

        $expenseTransferFee = new ExpenseCategory();
        $expenseTransferFee->setName('Transfer Fee')->setIsAffectingProfit(false)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($expenseTransferFee);

        // --- Income categories ---
        $salary = new IncomeCategory();
        $salary->setName('Salary')->setIsAffectingProfit(true)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($salary);

        $bonus = new IncomeCategory();
        $bonus->setName('Bonus')->setIsAffectingProfit(true)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($bonus);

        $incomeTransfer = new IncomeCategory();
        $incomeTransfer->setName('Transfer')->setIsAffectingProfit(false)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($incomeTransfer);

        $incomeDebt = new IncomeCategory();
        $incomeDebt->setName('Debt')->setIsAffectingProfit(false)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($incomeDebt);

        $compensation = new IncomeCategory();
        $compensation->setName('Compensation')->setIsAffectingProfit(false)
            ->setRoot(null)->setParent(null)->setCreatedAt($now)->setUpdatedAt($now);
        $manager->persist($compensation);

        $manager->flush();

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

    public function getDependencies(): array { return [UserAndAccountFixtures::class]; }
}
