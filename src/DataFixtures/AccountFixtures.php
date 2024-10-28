<?php

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\InternetAccount;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AccountFixtures extends Fixture implements DependentFixtureInterface
{
    private ParameterBagInterface $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $user = $this->getReference('test_user');
        $allowedCurrencies = $this->params->get('allowed_currencies');
        $baseCurrency = $this->params->get('base_currency');

        // Batch 1: Basic Accounts
        $basicAccounts = [
            ['name' => 'Main Account', 'currency' => $baseCurrency, 'balance' => $faker->randomFloat(2, 1000, 5000)],
            [
                'name' => 'Savings Account',
                'currency' => $this->getRandomCurrency($allowedCurrencies),
                'balance' => $faker->randomFloat(2, 2000, 7000),
            ],
        ];
        foreach ($basicAccounts as $data) {
            $account = new Account();
            $this->populateAccountData($account, $data, $user);
            $manager->persist($account);
        }

        // Batch 2: Bank Card Accounts with Fixed Bank Names
        $bankAccounts = [
            [
                'name' => 'Chase Bank Account',
                'currency' => $this->getRandomCurrency($allowedCurrencies),
                'balance' => $faker->randomFloat(2, 100, 2000),
                'bankName' => 'Chase Bank',
                'cardNumber' => $faker->creditCardNumber,
                'iban' => $faker->iban,
            ],
            [
                'name' => 'Bank of America Account',
                'currency' => $this->getRandomCurrency($allowedCurrencies),
                'balance' => $faker->randomFloat(2, 500, 2500),
                'bankName' => 'Bank of America',
                'cardNumber' => $faker->creditCardNumber,
                'iban' => $faker->iban,
            ],
        ];
        foreach ($bankAccounts as $data) {
            $account = new BankCardAccount();
            $this->populateBankAccountData($account, $data, $user);
            $manager->persist($account);
        }

        // Batch 3: Internet Accounts
        $internetAccounts = [
            [
                'name' => 'PayPal Account',
                'currency' => $this->getRandomCurrency($allowedCurrencies),
                'balance' => $faker->randomFloat(2, 1000, 3000),
            ],
            [
                'name' => 'Stripe Wallet',
                'currency' => $this->getRandomCurrency($allowedCurrencies),
                'balance' => $faker->randomFloat(2, 300, 1500),
            ],
        ];
        foreach ($internetAccounts as $data) {
            $account = new InternetAccount();
            $this->populateAccountData($account, $data, $user);
            $manager->persist($account);
        }

        // Batch 4: Cash Accounts
        $cashAccounts = [
            [
                'name' => 'Home Safe',
                'currency' => $this->getRandomCurrency($allowedCurrencies),
                'balance' => $faker->randomFloat(2, 100, 500),
            ],
            [
                'name' => 'Office Cash',
                'currency' => $this->getRandomCurrency($allowedCurrencies),
                'balance' => $faker->randomFloat(2, 200, 1000),
            ],
        ];
        foreach ($cashAccounts as $data) {
            $account = new CashAccount();
            $this->populateAccountData($account, $data, $user);
            $manager->persist($account);
        }

        $manager->flush();
    }

    private function populateAccountData(Account $account, array $data, $user): void
    {
        $account->setName($data['name'])
            ->setCurrency($data['currency'])
            ->setBalance($data['balance'])
            ->setCreatedAt(CarbonImmutable::now()->subYear())
            ->setUpdatedAt(CarbonImmutable::now())
            ->setOwner($user);
    }

    private function populateBankAccountData(BankCardAccount $account, array $data, $user): void
    {
        $this->populateAccountData($account, $data, $user);
        $account->setBankName($data['bankName'])
            ->setCardNumber($data['cardNumber'])
            ->setIban($data['iban']);
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }

    private function getRandomCurrency(array $allowedCurrencies): string
    {
        return $allowedCurrencies[array_rand($allowedCurrencies)];
    }
}
