<?php

namespace App\DataFixtures;

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
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Tax Refund', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Return on Investment', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Cashback', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Compensation', 'isTechnical' => false, 'isAffectingProfit' => false],
                ],
            ],
            [
                'name' => 'Spouse',
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [],
            ],
            [
                'name' => 'Bonus',
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Unknown', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Gift', 'isTechnical' => false, 'isAffectingProfit' => true],
                ],
            ],
            [
                'name' => 'Sell',
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [],
            ],
            [
                'name' => 'Salary',
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Advance', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Detalex', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Smart-Gamma', 'isTechnical' => false, 'isAffectingProfit' => true],
                ],
            ],
            [
                'name' => 'Trading',
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [],
            ],
            [
                'name' => 'Transfer',
                'isTechnical' => true,
                'isAffectingProfit' => false,
                'children' => [],
            ],
            [
                'name' => 'Debt',
                'isTechnical' => true,
                'isAffectingProfit' => false,
                'children' => [],
            ],
            [
                'name' => 'Other',
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [],
            ],
            [
                'name' => 'Rent',
                'isTechnical' => false,
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
                ->setIsTechnical($categoryData['isTechnical'])
                ->setIsAffectingProfit($categoryData['isAffectingProfit'])
                ->setRoot(null);  // Root-level category, so root is null

            $manager->persist($rootCategory);

            // Create child categories if they exist
            foreach ($categoryData['children'] as $childData) {
                $childCategory = new IncomeCategory();
                $childCategory->setName($childData['name'])
                    ->setCreatedAt(CarbonImmutable::now()->subYear())
                    ->setUpdatedAt(CarbonImmutable::now())
                    ->setIsTechnical($childData['isTechnical'])
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
