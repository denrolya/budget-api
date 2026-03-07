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
use Faker\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AccountFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(private ParameterBagInterface $params) {}

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $user = $this->getReference('dev_user', User::class);
        $allowedCurrencies = $this->params->get('allowed_currencies');
        $baseCurrency = $this->params->get('base_currency');

        $basicAccounts = [
            ['name' => 'Main Account', 'currency' => $baseCurrency, 'balance' => $faker->randomFloat(2, 1000, 5000)],
            ['name' => 'Savings Account', 'currency' => $this->rand($allowedCurrencies), 'balance' => $faker->randomFloat(2, 2000, 7000)],
        ];
        foreach ($basicAccounts as $data) {
            $account = new Account();
            $this->populate($account, $data, $user);
            $manager->persist($account);
        }

        $bankAccounts = [
            ['name' => 'Chase Bank Account', 'currency' => $this->rand($allowedCurrencies), 'balance' => $faker->randomFloat(2, 100, 2000), 'bankName' => 'Chase Bank', 'cardNumber' => $faker->creditCardNumber, 'iban' => $faker->iban],
            ['name' => 'Bank of America Account', 'currency' => $this->rand($allowedCurrencies), 'balance' => $faker->randomFloat(2, 500, 2500), 'bankName' => 'Bank of America', 'cardNumber' => $faker->creditCardNumber, 'iban' => $faker->iban],
        ];
        foreach ($bankAccounts as $data) {
            $account = new BankCardAccount();
            $this->populate($account, $data, $user);
            $account->setBankName($data['bankName'])->setCardNumber($data['cardNumber'])->setIban($data['iban']);
            $manager->persist($account);
        }

        $internetAccounts = [
            ['name' => 'PayPal Account', 'currency' => $this->rand($allowedCurrencies), 'balance' => $faker->randomFloat(2, 1000, 3000)],
            ['name' => 'Stripe Wallet', 'currency' => $this->rand($allowedCurrencies), 'balance' => $faker->randomFloat(2, 300, 1500)],
        ];
        foreach ($internetAccounts as $data) {
            $account = new InternetAccount();
            $this->populate($account, $data, $user);
            $manager->persist($account);
        }

        $cashAccounts = [
            ['name' => 'Home Safe', 'currency' => $this->rand($allowedCurrencies), 'balance' => $faker->randomFloat(2, 100, 500)],
            ['name' => 'Office Cash', 'currency' => $this->rand($allowedCurrencies), 'balance' => $faker->randomFloat(2, 200, 1000)],
        ];
        foreach ($cashAccounts as $data) {
            $account = new CashAccount();
            $this->populate($account, $data, $user);
            $manager->persist($account);
        }

        $manager->flush();
    }

    private function populate(Account $account, array $data, $user): void
    {
        $account->setName($data['name'])->setCurrency($data['currency'])->setBalance($data['balance'])
            ->setCreatedAt(CarbonImmutable::now()->subYear())->setUpdatedAt(CarbonImmutable::now())->setOwner($user);
    }

    private function rand(array $currencies): string
    {
        return $currencies[array_rand($currencies)];
    }

    public function getDependencies(): array { return [UserFixtures::class]; }
}
