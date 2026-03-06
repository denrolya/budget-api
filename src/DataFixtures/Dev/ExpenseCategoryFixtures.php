<?php
namespace App\DataFixtures\Dev;

use App\Entity\ExpenseCategory;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ExpenseCategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $categories = [
            [
                'name' => 'Food & Drinks', 'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Water', 'isAffectingProfit' => true],
                    ['name' => 'Eating Out', 'isAffectingProfit' => true, 'children' => [
                        ['name' => 'Fast Food', 'isAffectingProfit' => true, 'children' => [
                            ['name' => 'Kebab', 'isAffectingProfit' => true],
                            ['name' => 'Burger', 'isAffectingProfit' => true, 'children' => [
                                ['name' => 'MacDonalds', 'isAffectingProfit' => true],
                            ]],
                        ]],
                        ['name' => 'Restaurant', 'isAffectingProfit' => true],
                        ['name' => 'Pizza', 'isAffectingProfit' => true],
                        ['name' => 'Asian', 'isAffectingProfit' => true, 'children' => [
                            ['name' => 'Sushi', 'isAffectingProfit' => true],
                        ]],
                        ['name' => 'Canteen', 'isAffectingProfit' => true],
                    ]],
                    ['name' => 'Alcohol', 'isAffectingProfit' => true],
                    ['name' => 'Groceries', 'isAffectingProfit' => true, 'children' => [
                        ['name' => 'Fruits', 'isAffectingProfit' => true],
                        ['name' => 'Ready-to-cook', 'isAffectingProfit' => true],
                    ]],
                    ['name' => 'Goodies', 'isAffectingProfit' => true],
                    ['name' => 'Delivery', 'isAffectingProfit' => true],
                    ['name' => 'Pastry', 'isAffectingProfit' => true],
                    ['name' => 'Tea', 'isAffectingProfit' => true],
                    ['name' => 'Bar, cafe', 'isAffectingProfit' => true, 'children' => [
                        ['name' => 'Coffee', 'isAffectingProfit' => true],
                    ]],
                ],
            ],
            [
                'name' => 'Housing', 'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Rent', 'isAffectingProfit' => true],
                    ['name' => 'Spouse', 'isAffectingProfit' => true],
                    ['name' => 'Utilities', 'isAffectingProfit' => true, 'children' => [
                        ['name' => 'Gas', 'isAffectingProfit' => true],
                        ['name' => 'Electricity', 'isAffectingProfit' => true],
                        ['name' => 'Water utilities costs', 'isAffectingProfit' => true],
                    ]],
                    ['name' => 'Bath', 'isAffectingProfit' => true, 'children' => [
                        ['name' => 'Laundry', 'isAffectingProfit' => true],
                    ]],
                    ['name' => 'Internet', 'isAffectingProfit' => true],
                    ['name' => 'Pet', 'isAffectingProfit' => true],
                    ['name' => 'Tools', 'isAffectingProfit' => true],
                    ['name' => 'Furniture', 'isAffectingProfit' => true],
                    ['name' => 'Construction', 'isAffectingProfit' => true],
                    ['name' => 'Kids', 'isAffectingProfit' => true],
                ],
            ],
            ['name' => 'Transportation', 'isAffectingProfit' => true, 'children' => [
                ['name' => 'Public Transport', 'isAffectingProfit' => true],
                ['name' => 'Taxi', 'isAffectingProfit' => true],
                ['name' => 'Car', 'isAffectingProfit' => true],
            ]],
            ['name' => 'Shopping', 'isAffectingProfit' => true, 'children' => [
                ['name' => 'Clothes', 'isAffectingProfit' => true],
                ['name' => 'Electronics', 'isAffectingProfit' => true],
            ]],
            ['name' => 'Health', 'isAffectingProfit' => true, 'children' => [
                ['name' => 'Medicine', 'isAffectingProfit' => true],
                ['name' => 'Doctor', 'isAffectingProfit' => true],
            ]],
            ['name' => 'Other', 'isAffectingProfit' => true],
            ['name' => 'Transfer', 'isAffectingProfit' => false],
            ['name' => 'Debt', 'isAffectingProfit' => false],
        ];

        $this->createCategories($manager, $categories);
        $manager->flush();
    }

    private function createCategories(ObjectManager $manager, array $categories, ?ExpenseCategory $parent = null): void
    {
        foreach ($categories as $data) {
            $category = new ExpenseCategory();
            $category->setName($data['name'])
                ->setCreatedAt(CarbonImmutable::now()->subYears(2))
                ->setUpdatedAt(CarbonImmutable::now())
                ->setIsAffectingProfit($data['isAffectingProfit'])
                ->setParent($parent)
                ->setRoot($parent ? $parent->getRoot() ?? $parent : null);
            $manager->persist($category);
            if (!empty($data['children'])) {
                $this->createCategories($manager, $data['children'], $category);
            }
        }
    }

    public function getDependencies(): array { return [UserFixtures::class]; }
}
