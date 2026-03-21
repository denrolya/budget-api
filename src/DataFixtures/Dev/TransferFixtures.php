<?php

declare(strict_types=1);

namespace App\DataFixtures\Dev;

use App\Entity\Account;
use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\InternetAccount;
use App\Entity\Transfer;
use App\Entity\User;
use App\EventListener\DebtConvertedValueListener;
use App\EventListener\TransactionListener;
use App\Service\TransferService;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Creates realistic transfers across 15 months:
 *  - Monthly Monobank UAH → Savings EUR (savings top-up)
 *  - Monthly Monobank UAH → Cash UAH (cash withdrawal)
 *  - Quarterly PrivatBank UAH → Monobank UAH (consolidation)
 *  - Occasional PayPal USD → Wise EUR (currency exchange, with PayPal fee)
 *  - Occasional Monobank EUR → Revolut EUR (no fees, same currency)
 *  - Occasional Wise EUR → Cash EUR (with Wise withdrawal fee)
 *  - Occasional Monobank EUR → OTP HUF (with bank fee + intermediary fee)
 */
class TransferFixtures extends BaseTransactionFixtures
{
    public function __construct(
        ParameterBagInterface $params,
        TransactionListener $transactionListener,
        DebtConvertedValueListener $valuableEntityListener,
        private readonly TransferService $transferService,
    ) {
        parent::__construct($params, $transactionListener, $valuableEntityListener);
    }

    public function load(ObjectManager $manager): void
    {
        $user = $this->getReference('dev_user', User::class);
        $this->disableListeners();

        $now = CarbonImmutable::now();

        /** @var BankCardAccount $monobankUah */
        $monobankUah   = $this->getReference('account_monobank_uah',   BankCardAccount::class);
        /** @var BankCardAccount $monobankEur */
        $monobankEur   = $this->getReference('account_monobank_eur',   BankCardAccount::class);
        /** @var BankCardAccount $privatbankUah */
        $privatbankUah = $this->getReference('account_privatbank_uah', BankCardAccount::class);
        /** @var BankCardAccount $revolutEur */
        $revolutEur    = $this->getReference('account_revolut_eur',    BankCardAccount::class);
        /** @var BankCardAccount $otpHuf */
        $otpHuf        = $this->getReference('account_otp_huf',        BankCardAccount::class);
        /** @var InternetAccount $wiseEur */
        $wiseEur       = $this->getReference('account_wise_eur',       InternetAccount::class);
        /** @var InternetAccount $paypalUsd */
        $paypalUsd     = $this->getReference('account_paypal_usd',     InternetAccount::class);
        /** @var CashAccount $cashUah */
        $cashUah       = $this->getReference('account_cash_uah',       CashAccount::class);
        /** @var CashAccount $cashEur */
        $cashEur       = $this->getReference('account_cash_eur',       CashAccount::class);
        /** @var Account $savingsEur */
        $savingsEur    = $this->getReference('account_savings_eur',    Account::class);

        $uahToEurRate  = 0.0241;
        $usdToEurRate  = 0.926;
        $eurToHufRate  = 400.0;

        $transfers = [];

        // Monthly: Monobank UAH → Savings EUR (savings, no fees — internal bank transfer)
        for ($i = 0; $i < 15; $i++) {
            $date = $now->subMonths($i)->setDay(random_int(25, 28))->setTime(19, 0);
            if ($date->gt($now)) {
                continue;
            }
            $eurAmount  = round(250 + lcg_value() * 150, 2);
            $uahAmount  = round($eurAmount / $uahToEurRate, 2);
            $transfers[] = [
                'from' => $monobankUah, 'to' => $savingsEur,
                'amount' => $uahAmount, 'rate' => $uahToEurRate, 'fees' => [],
                'note' => 'Monthly savings', 'date' => $date,
            ];
        }

        // Monthly: Monobank UAH → Cash UAH (cash withdrawal, no fees)
        for ($i = 0; $i < 12; $i++) {
            $date = $now->subMonths($i)->setDay(random_int(1, 5))->setTime(10, 30);
            if ($date->gt($now)) {
                continue;
            }
            $transfers[] = [
                'from' => $monobankUah, 'to' => $cashUah,
                'amount' => round(2000 + lcg_value() * 3000, 2), 'rate' => 1.0, 'fees' => [],
                'note' => 'Cash withdrawal UAH', 'date' => $date,
            ];
        }

        // Quarterly: PrivatBank UAH → Monobank UAH (consolidation, small interbank fee)
        foreach ([2, 5, 8, 11, 14] as $offset) {
            $date = $now->subMonths($offset)->setDay(random_int(10, 20))->setTime(14, 0);
            if ($date->gt($now)) {
                continue;
            }
            $amount = round(15000 + lcg_value() * 20000, 2);
            $transfers[] = [
                'from' => $privatbankUah, 'to' => $monobankUah,
                'amount' => $amount, 'rate' => 1.0,
                'fees' => [
                    ['amount' => round(max(5, $amount * 0.001), 2), 'account' => $privatbankUah],
                ],
                'note' => 'PrivatBank → Monobank consolidation', 'date' => $date,
            ];
        }

        // Occasional: PayPal USD → Wise EUR (PayPal conversion fee from source)
        foreach ([0, 3, 6, 9, 12] as $offset) {
            $date = $now->subMonths($offset)->setDay(random_int(5, 25))->setTime(11, 0);
            if ($date->gt($now)) {
                continue;
            }
            $usdAmount = round(200 + lcg_value() * 300, 2);
            $transfers[] = [
                'from' => $paypalUsd, 'to' => $wiseEur,
                'amount' => $usdAmount, 'rate' => $usdToEurRate,
                'fees' => [
                    ['amount' => round($usdAmount * 0.029 + 0.30, 2), 'account' => $paypalUsd],
                ],
                'note' => 'PayPal → Wise exchange', 'date' => $date,
            ];
        }

        // Occasional: Monobank EUR → Revolut EUR (same currency, no fees)
        foreach ([1, 4, 7, 10, 14] as $offset) {
            $date = $now->subMonths($offset)->setDay(random_int(8, 22))->setTime(15, 0);
            if ($date->gt($now)) {
                continue;
            }
            $transfers[] = [
                'from' => $monobankEur, 'to' => $revolutEur,
                'amount' => round(200 + lcg_value() * 500, 2), 'rate' => 1.0, 'fees' => [],
                'note' => 'Monobank EUR → Revolut', 'date' => $date,
            ];
        }

        // Occasional: Wise EUR → Cash EUR (Wise withdrawal fee from source)
        foreach ([2, 6, 10] as $offset) {
            $date = $now->subMonths($offset)->setDay(random_int(12, 25))->setTime(13, 0);
            if ($date->gt($now)) {
                continue;
            }
            $amount = round(100 + lcg_value() * 200, 2);
            $transfers[] = [
                'from' => $wiseEur, 'to' => $cashEur,
                'amount' => $amount, 'rate' => 1.0,
                'fees' => [
                    ['amount' => round(max(0.50, $amount * 0.005), 2), 'account' => $wiseEur],
                ],
                'note' => 'Wise → Cash EUR', 'date' => $date,
            ];
        }

        // Occasional: Monobank EUR → OTP HUF (bank fee from sender + intermediary fee from receiver)
        foreach ([1, 5, 9, 13] as $offset) {
            $date = $now->subMonths($offset)->setDay(random_int(3, 15))->setTime(9, 0);
            if ($date->gt($now)) {
                continue;
            }
            $eurAmount = round(200 + lcg_value() * 300, 2);
            $transfers[] = [
                'from' => $monobankEur, 'to' => $otpHuf,
                'amount' => $eurAmount, 'rate' => $eurToHufRate,
                'fees' => [
                    ['amount' => round($eurAmount * 0.005, 2), 'account' => $monobankEur],
                    ['amount' => round($eurAmount * $eurToHufRate * 0.003, 2), 'account' => $otpHuf],
                ],
                'note' => 'EUR → HUF for Hungary', 'date' => $date,
            ];
        }

        foreach ($transfers as $data) {
            $transfer = (new Transfer())
                ->setFrom($data['from'])
                ->setTo($data['to'])
                ->setAmount((string)$data['amount'])
                ->setRate((string)$data['rate'])
                ->setNote($data['note'])
                ->setOwner($user)
                ->setExecutedAt($data['date'])
                ->setCreatedAt($data['date'])
                ->setUpdatedAt($data['date']);

            $this->transferService->createTransactions($transfer, $data['fees']);
            $manager->persist($transfer);
        }

        $manager->flush();
        $this->enableListeners();
    }

    public function getDependencies(): array
    {
        return array_merge(parent::getDependencies(), [AccountFixtures::class]);
    }
}
