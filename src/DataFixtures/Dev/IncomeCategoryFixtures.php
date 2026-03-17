<?php

declare(strict_types=1);

namespace App\DataFixtures\Dev;

use App\Entity\IncomeCategory;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class IncomeCategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Root-level categories and their children
        $categories = [
            [
                'name' => 'Return',
                'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Tax Refund', 'isAffectingProfit' => true],
                    ['name' => 'Return on Investment', 'isAffectingProfit' => true],
                    ['name' => 'Cashback', 'isAffectingProfit' => true],
                    ['name' => 'Compensation', 'isAffectingProfit' => false],
                ],
            ],
            [
                'name' => 'Spouse',
                'isAffectingProfit' => true,
                'children' => [],
            ],
            [
                'name' => 'Bonus',
                'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Unknown', 'isAffectingProfit' => true],
                    ['name' => 'Gift', 'isAffectingProfit' => true],
                ],
            ],
            [
                'name' => 'Sell',
                'isAffectingProfit' => true,
                'children' => [],
            ],
            [
                'name' => 'Salary',
                'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Advance', 'isAffectingProfit' => true],
                    ['name' => 'Detalex', 'isAffectingProfit' => true],
                    ['name' => 'Smart-Gamma', 'isAffectingProfit' => true],
                ],
            ],
            [
                'name' => 'Trading',
                'isAffectingProfit' => true,
                'children' => [],
            ],
            [
                'name' => 'Transfer',
                'isAffectingProfit' => false,
                'children' => [],
            ],
            [
                'name' => 'Debt',
                'isAffectingProfit' => false,
                'children' => [],
            ],
            [
                'name' => 'Other',
                'isAffectingProfit' => true,
                'children' => [],
            ],
            [
                'name' => 'Rent',
                'isAffectingProfit' => true,
                'children' => [],
            ],
        ];

        foreach ($categories as $categoryData) {
            // Create root category
            $rootCategory = new IncomeCategory();
            $rootCategory->setName($categoryData['name'])
                ->setCreatedAt(CarbonImmutable::now()->subYears(2))
                ->setUpdatedAt(CarbonImmutable::now())
                ->setIsAffectingProfit($categoryData['isAffectingProfit'])
                ->setRoot(null);  // Root-level category, so root is null

            $manager->persist($rootCategory);

            // Create child categories if they exist
            foreach ($categoryData['children'] as $childData) {
                $childCategory = new IncomeCategory();
                $childCategory->setName($childData['name'])
                    ->setCreatedAt(CarbonImmutable::now()->subYear())
                    ->setUpdatedAt(CarbonImmutable::now())
                    ->setIsAffectingProfit($childData['isAffectingProfit'])
                    ->setParent($rootCategory)
                    ->setRoot($rootCategory);

                $manager->persist($childCategory);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
