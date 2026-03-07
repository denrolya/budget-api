<?php
namespace App\DataFixtures\Test;

use App\Entity\CashAccount;
use App\Entity\Debt;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\EventListener\TransactionListener;
use App\EventListener\ValuableEntityEventListener;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DebtFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private TransactionListener $transactionListener,
        private ValuableEntityEventListener $valuableEntityListener
    ) {}

    public function load(ObjectManager $manager): void
    {
        $this->transactionListener->setEnabled(false);
        $this->valuableEntityListener->setEnabled(false);

        $user = $this->getReference('test_user', User::class);
        $eurAccount = $this->getReference('account_eur_cash', CashAccount::class);
        $debtCategory = $this->getReference('cat_exp_debt', ExpenseCategory::class);

        $debt = new Debt();
        $debt->setDebtor('Test Debtor')
            ->setCurrency('EUR')
            ->setBalance('200')
            ->setNote('Test Debt')
            ->setOwner($user)
            ->setCreatedAt(CarbonImmutable::parse('2021-06-01'))
            ->setUpdatedAt(CarbonImmutable::parse('2021-06-01'));
        $manager->persist($debt);
        $manager->flush();

        $expense = new Expense();
        $expense->setAccount($eurAccount)->setCategory($debtCategory)->setOwner($user)
            ->setAmount('200')
            ->setConvertedValues(['EUR' => 200, 'USD' => 224.0, 'UAH' => 5200.0, 'HUF' => 66000.0, 'BTC' => 0.02857143])
            ->setDebt($debt)
            ->setExecutedAt(CarbonImmutable::parse('2021-06-01 00:00:00'))
            ->setCreatedAt(CarbonImmutable::parse('2021-06-01'))
            ->setUpdatedAt(CarbonImmutable::parse('2021-06-01'))
            ->setIsDraft(false);
        $manager->persist($expense);
        $manager->flush();

        $this->transactionListener->setEnabled(true);
        $this->valuableEntityListener->setEnabled(true);
    }

    public function getDependencies(): array
    {
        return [UserAndAccountFixtures::class, CategoryFixtures::class, TransactionFixtures::class];
    }
}
