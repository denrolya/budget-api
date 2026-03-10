<?php
namespace App\DataFixtures\Dev;

use App\Entity\Account;
use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\InternetAccount;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AccountFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $user = $this->getReference('dev_user', User::class);
        $now = CarbonImmutable::now();

        // ── Bank card accounts ─────────────────────────────────────────────────

        $monobankUah = (new BankCardAccount())
            ->setName('Monobank UAH')
            ->setCurrency('UAH')
            ->setBalance('148600.50')
            ->setBankName('Monobank')
            ->setCardNumber('4441111122223333')
            ->setIban('UA213223130000026007233566001')
            ->setIsDisplayedOnSidebar(true)
            ->setOwner($user)
            ->setCreatedAt($now->subYears(2))
            ->setUpdatedAt($now);
        $manager->persist($monobankUah);
        $this->addReference('account_monobank_uah', $monobankUah);

        $monobankEur = (new BankCardAccount())
            ->setName('Monobank EUR')
            ->setCurrency('EUR')
            ->setBalance('3480.00')
            ->setBankName('Monobank')
            ->setCardNumber('4441111122224444')
            ->setIban('UA213223130000026007233566002')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subYears(2))
            ->setUpdatedAt($now);
        $manager->persist($monobankEur);
        $this->addReference('account_monobank_eur', $monobankEur);

        $privatbankUah = (new BankCardAccount())
            ->setName('PrivatBank UAH')
            ->setCurrency('UAH')
            ->setBalance('42350.00')
            ->setBankName('PrivatBank')
            ->setCardNumber('5168742212345678')
            ->setIban('UA613052992900000026003145790')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subYears(2))
            ->setUpdatedAt($now);
        $manager->persist($privatbankUah);
        $this->addReference('account_privatbank_uah', $privatbankUah);

        $revolutEur = (new BankCardAccount())
            ->setName('Revolut EUR')
            ->setCurrency('EUR')
            ->setBalance('1250.00')
            ->setBankName('Revolut')
            ->setCardNumber('5222334455667788')
            ->setIban('LT121000011101001000')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subMonths(18))
            ->setUpdatedAt($now);
        $manager->persist($revolutEur);
        $this->addReference('account_revolut_eur', $revolutEur);

        $otpHuf = (new BankCardAccount())
            ->setName('OTP Hungary')
            ->setCurrency('HUF')
            ->setBalance('148000.00')
            ->setBankName('OTP Bank')
            ->setCardNumber('4111223344556677')
            ->setIban('HU42117730161111101800000000')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subYears(1))
            ->setUpdatedAt($now);
        $manager->persist($otpHuf);
        $this->addReference('account_otp_huf', $otpHuf);

        // ── Internet / online accounts ─────────────────────────────────────────

        $wiseEur = (new InternetAccount())
            ->setName('Wise EUR')
            ->setCurrency('EUR')
            ->setBalance('820.00')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subMonths(20))
            ->setUpdatedAt($now);
        $manager->persist($wiseEur);
        $this->addReference('account_wise_eur', $wiseEur);

        $paypalUsd = (new InternetAccount())
            ->setName('PayPal USD')
            ->setCurrency('USD')
            ->setBalance('350.00')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subMonths(20))
            ->setUpdatedAt($now);
        $manager->persist($paypalUsd);
        $this->addReference('account_paypal_usd', $paypalUsd);

        // ── Cash wallets ───────────────────────────────────────────────────────

        $cashUah = (new CashAccount())
            ->setName('Cash UAH')
            ->setCurrency('UAH')
            ->setBalance('5200.00')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subYears(3))
            ->setUpdatedAt($now);
        $manager->persist($cashUah);
        $this->addReference('account_cash_uah', $cashUah);

        $cashEur = (new CashAccount())
            ->setName('Cash EUR')
            ->setCurrency('EUR')
            ->setBalance('380.00')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subYears(3))
            ->setUpdatedAt($now);
        $manager->persist($cashEur);
        $this->addReference('account_cash_eur', $cashEur);

        // ── Savings / investment accounts ──────────────────────────────────────

        $savingsEur = (new Account())
            ->setName('Savings EUR')
            ->setCurrency('EUR')
            ->setBalance('12500.00')
            ->setIsDisplayedOnSidebar(true)
            ->setOwner($user)
            ->setCreatedAt($now->subYears(2))
            ->setUpdatedAt($now);
        $manager->persist($savingsEur);
        $this->addReference('account_savings_eur', $savingsEur);

        $btcWallet = (new Account())
            ->setName('Bitcoin Wallet')
            ->setCurrency('BTC')
            ->setBalance('0.04820000')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subMonths(14))
            ->setUpdatedAt($now);
        $manager->persist($btcWallet);
        $this->addReference('account_btc', $btcWallet);

        // ── Archived account ───────────────────────────────────────────────────

        $oldBank = (new BankCardAccount())
            ->setName('Alpha Bank UAH')
            ->setCurrency('UAH')
            ->setBalance('0.00')
            ->setBankName('Alpha Bank')
            ->setCardNumber('4111000000000001')
            ->setIban('UA213999990000060070000100011')
            ->setIsDisplayedOnSidebar(false)
            ->setOwner($user)
            ->setCreatedAt($now->subYears(3))
            ->setUpdatedAt($now->subYears(1))
            ->setArchivedAt($now->subYears(1));
        $manager->persist($oldBank);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
