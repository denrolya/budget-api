<?php
namespace App\DataFixtures\Dev;

use App\Entity\Account;
use App\Entity\Debt;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class DebtFixtures extends BaseTransactionFixtures
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $user = $this->getReference('dev_user', User::class);
        $allowedCurrencies = $this->params->get('allowed_currencies');
        $baseCurrency = $this->params->get('base_currency');

        $this->disableListeners();

        $accounts = $manager->getRepository(Account::class)->findAll();
        if ($accounts === []) {
            $this->enableListeners();
            return;
        }
        $account = $accounts[0];

        $debtCategory = $manager->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Debt']);
        if (!$debtCategory) {
            $this->enableListeners();
            return;
        }

        // Create a debt
        $debtAmount = $faker->randomFloat(2, 100, 2000);
        $debt = new Debt();
        $debt->setDebtor($faker->name)
            ->setCurrency($baseCurrency)
            ->setBalance((string)$debtAmount)
            ->setNote($faker->sentence)
            ->setOwner($user)
            ->setCreatedAt(CarbonImmutable::now()->subMonths(3))
            ->setUpdatedAt(CarbonImmutable::now());
        $manager->persist($debt);
        $manager->flush();

        // Create an expense linked to the debt
        $amount = 100.0;
        $expense = new Expense();
        $expense->setAccount($account)->setCategory($debtCategory)->setOwner($user)
            ->setAmount((string)$amount)
            ->setConvertedValues($this->convertAmount($amount, $account->getCurrency(), $allowedCurrencies))
            ->setDebt($debt)
            ->setExecutedAt(CarbonImmutable::now()->subMonths(2))
            ->setCreatedAt(CarbonImmutable::now()->subMonths(2))
            ->setUpdatedAt(CarbonImmutable::now())
            ->setIsDraft(false);
        $manager->persist($expense);

        $manager->flush();
        $this->enableListeners();
    }
}
