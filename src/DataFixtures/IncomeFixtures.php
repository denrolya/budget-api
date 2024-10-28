<?php

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Random\RandomException;

class IncomeFixtures extends BaseTransactionFixtures
{
    /**
     * @throws RandomException
     */
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        $user = $this->getReference('test_user');
        $allowedCurrencies = $this->params->get('allowed_currencies');

        // Disable listeners
        $this->disableListeners();

        $categories = $manager->getRepository(IncomeCategory::class)->findAll();
        $accounts = $manager->getRepository(Account::class)->findAll();

        foreach ($accounts as $account) {
            $transactionCount = random_int(10, 30);

            for ($i = 0; $i < $transactionCount; $i++) {
                $category = $faker->randomElement($categories);

                // FIXME: Make some exclusion for now
                if ($category->getName() === 'Transfer' || $category->getName() === 'Debt' || $account->getCurrency() === 'BTC') {
                    continue;
                }

                $amount = $faker->randomFloat(2, 50, 5000);
                $transaction = new Income();
                $transaction->setAccount($account)
                    ->setCategory($category)
                    ->setOwner($user)
                    ->setAmount((string)$amount)
                    ->setConvertedValues($this->convertAmount($amount, $account->getCurrency(), $allowedCurrencies))
                    ->setNote($faker->optional()->sentence)
                    ->setExecutedAt(CarbonImmutable::now()->subDays(random_int(0, 365)))
                    ->setCreatedAt(CarbonImmutable::now()->subDays(random_int(0, 365)))
                    ->setUpdatedAt(CarbonImmutable::now())
                    ->setIsDraft($faker->boolean(5));

                $manager->persist($transaction);
            }
        }

        $manager->flush();

        // Re-enable listeners after loading
        $this->enableListeners();
    }
}
