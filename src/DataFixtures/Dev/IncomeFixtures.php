<?php
namespace App\DataFixtures\Dev;

use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\InternetAccount;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ObjectManager;

/**
 * Creates 18 months of realistic income:
 *  - Monthly UAH salary: advance (15th) + main (last day) via Monobank UAH
 *  - Quarterly UAH bonus via Monobank UAH
 *  - Bi-monthly EUR freelance payment via Wise EUR
 *  - Monthly UAH cashback via Monobank UAH
 *  - Quarterly USD freelance via PayPal USD
 */
class IncomeFixtures extends BaseTransactionFixtures
{
    public function load(ObjectManager $manager): void
    {
        $user = $this->getReference('dev_user', User::class);
        $allowedCurrencies = $this->params->get('allowed_currencies');
        $this->disableListeners();

        /** @var BankCardAccount $monobankUah */
        $monobankUah   = $this->getReference('account_monobank_uah',   BankCardAccount::class);
        /** @var BankCardAccount $privatbankUah */
        $privatbankUah = $this->getReference('account_privatbank_uah', BankCardAccount::class);
        /** @var BankCardAccount $monobankEur */
        $monobankEur   = $this->getReference('account_monobank_eur',   BankCardAccount::class);
        /** @var InternetAccount $wiseEur */
        $wiseEur       = $this->getReference('account_wise_eur',       InternetAccount::class);
        /** @var InternetAccount $paypalUsd */
        $paypalUsd     = $this->getReference('account_paypal_usd',     InternetAccount::class);
        /** @var CashAccount $cashUah */
        $cashUah       = $this->getReference('account_cash_uah',       CashAccount::class);

        $repo = $manager->getRepository(IncomeCategory::class);

        $catSalaryAdvance = $repo->findOneBy(['name' => 'Advance']);
        $catSalaryMain    = $repo->findOneBy(['name' => 'Detalex']) ?? $repo->findOneBy(['name' => 'Advance']);
        $catBonus         = $repo->findOneBy(['name' => 'Bonus']);
        $catCashback      = $repo->findOneBy(['name' => 'Cashback']);
        $catFreelance     = $repo->findOneBy(['name' => 'Other']);

        $now = CarbonImmutable::now();
        $months = 18;

        for ($i = 0; $i < $months; $i++) {
            $base = $now->subMonths($i);

            // --- Salary advance ~15th, to Monobank UAH ---
            $advanceDate = $base->setDay(15)->setTime(10, 0);
            if ($advanceDate->lte($now)) {
                $amount = round(40000 + lcg_value() * 8000, 2);
                $manager->persist($this->makeIncome(
                    $monobankUah, $catSalaryAdvance, $user, $amount, 'UAH',
                    $allowedCurrencies, $advanceDate, 'Salary advance'
                ));
            }

            // --- Main salary last day of month, to Monobank UAH ---
            $salaryDate = $base->lastOfMonth()->setTime(17, 0);
            if ($salaryDate->lte($now)) {
                $amount = round(80000 + lcg_value() * 12000, 2);
                $manager->persist($this->makeIncome(
                    $monobankUah, $catSalaryMain, $user, $amount, 'UAH',
                    $allowedCurrencies, $salaryDate, 'Monthly salary'
                ));
            }

            // --- Quarterly bonus (months 0, 3, 6, 9, 12, 15) ---
            if ($i % 3 === 0) {
                $bonusDate = $base->setDay(random_int(20, 28))->setTime(12, 0);
                if ($bonusDate->lte($now)) {
                    $amount = round(30000 + lcg_value() * 20000, 2);
                    $manager->persist($this->makeIncome(
                        $monobankUah, $catBonus, $user, $amount, 'UAH',
                        $allowedCurrencies, $bonusDate, 'Quarterly bonus'
                    ));
                }
            }

            // --- Bi-monthly EUR freelance via Wise (even months) ---
            if ($i % 2 === 0) {
                $freelanceDate = $base->setDay(random_int(3, 20))->setTime(9, 30);
                if ($freelanceDate->lte($now)) {
                    $amount = round(2000 + lcg_value() * 1500, 2);
                    $manager->persist($this->makeIncome(
                        $wiseEur, $catFreelance, $user, $amount, 'EUR',
                        $allowedCurrencies, $freelanceDate, 'Freelance payment EUR'
                    ));
                }
            }

            // --- Monthly cashback on Monobank UAH ---
            $cashbackDate = $base->setDay(1)->setTime(8, 0);
            if ($cashbackDate->lte($now)) {
                $amount = round(200 + lcg_value() * 300, 2);
                $manager->persist($this->makeIncome(
                    $monobankUah, $catCashback, $user, $amount, 'UAH',
                    $allowedCurrencies, $cashbackDate, 'Monobank cashback'
                ));
            }

            // --- Quarterly USD via PayPal (months 0, 4, 8, 12, 16) ---
            if ($i % 4 === 0) {
                $paypalDate = $base->setDay(random_int(5, 25))->setTime(14, 0);
                if ($paypalDate->lte($now)) {
                    $amount = round(500 + lcg_value() * 700, 2);
                    $manager->persist($this->makeIncome(
                        $paypalUsd, $catFreelance, $user, $amount, 'USD',
                        $allowedCurrencies, $paypalDate, 'PayPal USD income'
                    ));
                }
            }

            // --- Occasional small UAH cash income (odd months) ---
            if ($i % 2 === 1) {
                $cashDate = $base->setDay(random_int(5, 20))->setTime(11, 0);
                if ($cashDate->lte($now)) {
                    $amount = round(500 + lcg_value() * 2000, 2);
                    $manager->persist($this->makeIncome(
                        $cashUah, $catFreelance, $user, $amount, 'UAH',
                        $allowedCurrencies, $cashDate, 'Cash income'
                    ));
                }
            }

            if ($i % 6 === 0) {
                $manager->flush();
            }
        }

        $manager->flush();
        $this->enableListeners();
    }

    private function makeIncome(
        object $account,
        ?IncomeCategory $category,
        User $user,
        float $amount,
        string $currency,
        array $allowedCurrencies,
        CarbonImmutable $date,
        string $note,
    ): Income {
        return (new Income())
            ->setAccount($account)
            ->setCategory($category)
            ->setOwner($user)
            ->setAmount((string)$amount)
            ->setConvertedValues($this->convertAmount($amount, $currency, $allowedCurrencies))
            ->setNote($note)
            ->setExecutedAt($date)
            ->setCreatedAt($date)
            ->setUpdatedAt($date)
            ->setIsDraft(false);
    }
}

