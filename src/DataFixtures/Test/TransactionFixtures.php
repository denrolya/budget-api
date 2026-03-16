<?php
namespace App\DataFixtures\Test;

use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\User;
use App\EventListener\TransactionListener;
use App\EventListener\DebtConvertedValueListener;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class TransactionFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private TransactionListener $transactionListener,
        private DebtConvertedValueListener $valuableEntityListener
    ) {}

    public function load(ObjectManager $manager): void
    {
        $this->transactionListener->setEnabled(false);
        $this->valuableEntityListener->setEnabled(false);

        $eurAccount = $this->getReference('account_eur_cash', CashAccount::class);
        $uahAccount = $this->getReference('account_uah_card', BankCardAccount::class);
        $user = $this->getReference('test_user', User::class);

        $groceries = $this->getReference('cat_exp_groceries', ExpenseCategory::class);
        $eatingOut = $this->getReference('cat_exp_eating_out', ExpenseCategory::class);
        $rent = $this->getReference('cat_exp_rent', ExpenseCategory::class);
        $salary = $this->getReference('cat_inc_salary', IncomeCategory::class);
        $bonus = $this->getReference('cat_inc_bonus', IncomeCategory::class);

        // Helper closures
        $exp = function(string $date, $cat, float $eur, $account = null) use ($manager, $user, $eurAccount): void {
            $account = $account ?? $eurAccount;
            $isEur = $account->getCurrency() === 'EUR';
            $amount = $isEur ? $eur : round($eur * 26, 2); // UAH amount
            $e = new Expense();
            $e->setAccount($account)->setCategory($cat)->setOwner($user)
                ->setAmount((string)$amount)
                ->setConvertedValues($isEur ? $this->eurCV($eur) : $this->uahCV($amount))
                ->setExecutedAt(CarbonImmutable::parse($date . ' 00:00:00'))
                ->setCreatedAt(CarbonImmutable::parse($date . ' 00:00:00'))
                ->setUpdatedAt(CarbonImmutable::parse($date . ' 00:00:00'))
                ->setIsDraft(false);
            $manager->persist($e);
        };
        $inc = function(string $date, $cat, float $eur) use ($manager, $user, $eurAccount): void {
            $i = new Income();
            $i->setAccount($eurAccount)->setCategory($cat)->setOwner($user)
                ->setAmount((string)$eur)
                ->setConvertedValues($this->eurCV($eur))
                ->setExecutedAt(CarbonImmutable::parse($date . ' 00:00:00'))
                ->setCreatedAt(CarbonImmutable::parse($date . ' 00:00:00'))
                ->setUpdatedAt(CarbonImmutable::parse($date . ' 00:00:00'))
                ->setIsDraft(false);
            $manager->persist($i);
        };

        // 2020 January
        $exp('2020-01-15', $groceries, 800);
        $inc('2020-01-20', $salary, 2000);

        // 2021 January - EUR Cash expenses
        foreach ([
            ['2021-01-01', $groceries, 50], ['2021-01-02', $groceries, 50], ['2021-01-03', $eatingOut, 30],
            ['2021-01-04', $groceries, 50], ['2021-01-05', $rent, 100], ['2021-01-06', $groceries, 50],
            ['2021-01-07', $eatingOut, 30], ['2021-01-08', $groceries, 50], ['2021-01-09', $groceries, 50],
            ['2021-01-10', $eatingOut, 30], ['2021-01-11', $groceries, 50], ['2021-01-12', $eatingOut, 30],
            ['2021-01-13', $groceries, 50], ['2021-01-14', $rent, 100], ['2021-01-15', $groceries, 50],
            ['2021-01-16', $eatingOut, 30], ['2021-01-17', $groceries, 50], ['2021-01-18', $eatingOut, 30],
            ['2021-01-19', $rent, 100], ['2021-01-20', $groceries, 50], ['2021-01-21', $eatingOut, 30],
            ['2021-01-22', $groceries, 50], ['2021-01-23', $eatingOut, 30], ['2021-01-24', $groceries, 50],
            ['2021-01-25', $eatingOut, 30], ['2021-01-26', $rent, 100], ['2021-01-27', $groceries, 50],
            ['2021-01-28', $eatingOut, 30], ['2021-01-29', $groceries, 50], ['2021-01-30', $eatingOut, 30],
        ] as [$date, $cat, $amount]) {
            $exp($date, $cat, $amount);
        }

        // 2021 January - EUR Cash incomes
        $inc('2021-01-01', $salary, 100);
        $inc('2021-01-05', $bonus, 200);
        $inc('2021-01-15', $salary, 500);
        $inc('2021-01-31', $salary, 1200);

        // 2021 January - UAH Card expenses (260 UAH = 10 EUR each)
        foreach (['2021-01-02', '2021-01-04', '2021-01-06', '2021-01-08', '2021-01-10'] as $date) {
            $exp($date, $groceries, 10, $uahAccount);
        }

        // 2021 February - EUR Cash
        foreach ([
            ['2021-02-01', $groceries, 100], ['2021-02-02', $eatingOut, 50], ['2021-02-03', $groceries, 80],
            ['2021-02-05', $groceries, 60], ['2021-02-06', $rent, 120], ['2021-02-07', $eatingOut, 40],
            ['2021-02-08', $groceries, 80], ['2021-02-09', $eatingOut, 30], ['2021-02-11', $groceries, 70],
            ['2021-02-12', $eatingOut, 50], ['2021-02-13', $groceries, 60], ['2021-02-15', $groceries, 90],
            ['2021-02-16', $eatingOut, 40], ['2021-02-17', $rent, 150], ['2021-02-18', $groceries, 70],
            ['2021-02-19', $eatingOut, 30], ['2021-02-20', $groceries, 80], ['2021-02-22', $groceries, 60],
            ['2021-02-23', $eatingOut, 50], ['2021-02-24', $groceries, 70], ['2021-02-26', $rent, 100],
            ['2021-02-27', $eatingOut, 30], ['2021-02-28', $groceries, 50],
        ] as [$date, $cat, $amount]) {
            $exp($date, $cat, $amount);
        }
        $inc('2021-02-01', $salary, 200);
        $inc('2021-02-04', $salary, 100);
        $inc('2021-02-07', $bonus, 150);
        $inc('2021-02-10', $salary, 300);
        $inc('2021-02-14', $bonus, 100);
        $inc('2021-02-21', $salary, 250);
        $inc('2021-02-25', $salary, 200);

        // 2021 March - EUR Cash
        foreach ([
            ['2021-03-01', $groceries, 90], ['2021-03-02', $eatingOut, 60], ['2021-03-04', $groceries, 80],
            ['2021-03-05', $eatingOut, 40], ['2021-03-06', $groceries, 100], ['2021-03-08', $rent, 150],
            ['2021-03-09', $groceries, 70], ['2021-03-10', $eatingOut, 50], ['2021-03-11', $groceries, 80],
            ['2021-03-13', $eatingOut, 40], ['2021-03-14', $groceries, 90], ['2021-03-15', $eatingOut, 60],
            ['2021-03-16', $groceries, 70], ['2021-03-17', $rent, 120], ['2021-03-18', $groceries, 80],
            ['2021-03-19', $eatingOut, 50], ['2021-03-21', $groceries, 90], ['2021-03-22', $eatingOut, 40],
            ['2021-03-23', $groceries, 80], ['2021-03-24', $rent, 100], ['2021-03-25', $eatingOut, 60],
            ['2021-03-26', $groceries, 70], ['2021-03-28', $eatingOut, 50], ['2021-03-29', $groceries, 100],
            ['2021-03-31', $rent, 150],
        ] as [$date, $cat, $amount]) {
            $exp($date, $cat, $amount);
        }
        $inc('2021-03-03', $salary, 400);
        $inc('2021-03-07', $bonus, 200);
        $inc('2021-03-12', $salary, 300);
        $inc('2021-03-20', $salary, 250);
        $inc('2021-03-27', $salary, 350);
        $inc('2021-03-30', $salary, 500);

        // April-December 2021 - simple transactions
        // April
        $exp('2021-04-01', $groceries, 200); $inc('2021-04-01', $salary, 1000);
        $exp('2021-04-15', $eatingOut, 150); $inc('2021-04-15', $bonus, 500);
        $exp('2021-04-30', $rent, 300);

        // May
        $exp('2021-05-01', $groceries, 300);
        $inc('2021-05-15', $salary, 800);
        $exp('2021-05-31', $eatingOut, 200); $exp('2021-05-31', $rent, 250);

        // June
        $exp('2021-06-01', $groceries, 400); $inc('2021-06-01', $salary, 1200);
        $exp('2021-06-30', $eatingOut, 200);

        // July
        $exp('2021-07-01', $groceries, 350); $inc('2021-07-01', $salary, 1500);
        $exp('2021-07-15', $eatingOut, 200);
        $exp('2021-07-31', $rent, 300);

        // August
        $exp('2021-08-01', $groceries, 400);
        $inc('2021-08-15', $salary, 1000);
        $exp('2021-08-31', $eatingOut, 300); $exp('2021-08-31', $rent, 200);

        // September
        $exp('2021-09-01', $groceries, 500); $inc('2021-09-01', $salary, 2000);
        $exp('2021-09-30', $eatingOut, 250);

        // October
        $exp('2021-10-01', $groceries, 450); $inc('2021-10-01', $salary, 1800);
        $exp('2021-10-15', $eatingOut, 300);
        $exp('2021-10-31', $rent, 350);

        // November
        $exp('2021-11-01', $groceries, 500); $inc('2021-11-01', $salary, 2000);
        $exp('2021-11-15', $eatingOut, 350);
        $exp('2021-11-30', $rent, 400);

        // December
        $exp('2021-12-01', $groceries, 600);
        $exp('2021-12-15', $eatingOut, 400); $inc('2021-12-15', $bonus, 500);
        $exp('2021-12-31', $rent, 500);

        $manager->flush();

        $this->transactionListener->setEnabled(true);
        $this->valuableEntityListener->setEnabled(true);
    }

    private function eurCV(float $eur): array
    {
        return [
            'EUR' => $eur,
            'USD' => round($eur * 1.12, 2),
            'UAH' => round($eur * 26, 2),
            'HUF' => round($eur * 330, 2),
            'BTC' => round($eur / 7000, 8),
        ];
    }

    private function uahCV(float $uah): array
    {
        $eur = round($uah / 26, 2);
        return [
            'EUR' => $eur,
            'USD' => round($eur * 1.12, 2),
            'UAH' => $uah,
            'HUF' => round($eur * 330, 2),
            'BTC' => round($eur / 7000, 8),
        ];
    }

    public function getDependencies(): array
    {
        return [UserAndAccountFixtures::class, CategoryFixtures::class];
    }
}
