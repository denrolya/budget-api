<?php

namespace App\DataFixtures;

use App\Entity\ExpenseCategory;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ExpenseCategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Define root-level categories and their children
        $categories = [
            [
                'name' => 'Food & Drinks',
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Water', 'isTechnical' => false, 'isAffectingProfit' => true],
                    [
                        'name' => 'Eating Out',
                        'isTechnical' => false,
                        'isAffectingProfit' => true,
                        'children' => [
                            [
                                'name' => 'Fast Food',
                                'isTechnical' => false,
                                'isAffectingProfit' => true,
                                'children' => [
                                    ['name' => 'Kebab', 'isTechnical' => false, 'isAffectingProfit' => true],
                                    [
                                        'name' => 'Burger',
                                        'isTechnical' => false,
                                        'isAffectingProfit' => true,
                                        'children' => [
                                            [
                                                'name' => 'MacDonalds',
                                                'isTechnical' => false,
                                                'isAffectingProfit' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            ['name' => 'Restaurant', 'isTechnical' => false, 'isAffectingProfit' => true],
                            ['name' => 'Pizza', 'isTechnical' => false, 'isAffectingProfit' => true],
                            [
                                'name' => 'Asian',
                                'isTechnical' => false,
                                'isAffectingProfit' => true,
                                'children' => [
                                    ['name' => 'Sushi', 'isTechnical' => false, 'isAffectingProfit' => true],
                                ],
                            ],
                            ['name' => 'Canteen', 'isTechnical' => false, 'isAffectingProfit' => true],
                        ],
                    ],
                    ['name' => 'Alcohol', 'isTechnical' => false, 'isAffectingProfit' => true],
                    [
                        'name' => 'Groceries',
                        'isTechnical' => false,
                        'isAffectingProfit' => true,
                        'children' => [
                            ['name' => 'Fruits', 'isTechnical' => false, 'isAffectingProfit' => true],
                            ['name' => 'Ready-to-cook', 'isTechnical' => false, 'isAffectingProfit' => true],
                        ],
                    ],
                    ['name' => 'Goodies', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Delivery', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Pastry', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Tea', 'isTechnical' => false, 'isAffectingProfit' => true],
                    [
                        'name' => 'Bar, cafe',
                        'isTechnical' => false,
                        'isAffectingProfit' => true,
                        'children' => [
                            ['name' => 'Coffee', 'isTechnical' => false, 'isAffectingProfit' => true],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Housing',
                'isTechnical' => false,
                'isAffectingProfit' => true,
                'children' => [
                    ['name' => 'Rent', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Spouse', 'isTechnical' => false, 'isAffectingProfit' => true],
                    [
                        'name' => 'Utilities',
                        'isTechnical' => false,
                        'isAffectingProfit' => true,
                        'children' => [
                            ['name' => 'Gas', 'isTechnical' => false, 'isAffectingProfit' => true],
                            ['name' => 'Electricity', 'isTechnical' => false, 'isAffectingProfit' => true],
                            ['name' => 'Water utilities costs', 'isTechnical' => false, 'isAffectingProfit' => true],
                        ],
                    ],
                    [
                        'name' => 'Bath',
                        'isTechnical' => false,
                        'isAffectingProfit' => true,
                        'children' => [
                            ['name' => 'Laundry', 'isTechnical' => false, 'isAffectingProfit' => true],
                        ],
                    ],
                    ['name' => 'Internet', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Pet', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Tools', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Furniture', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Construction', 'isTechnical' => false, 'isAffectingProfit' => true],
                    ['name' => 'Kids', 'isTechnical' => false, 'isAffectingProfit' => true],
                ],
            ],
            // Additional categories would continue here based on your provided structure
        ];

        // Recursive function to create categories
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
                ->setIsTechnical($data['isTechnical'])
                ->setIsAffectingProfit($data['isAffectingProfit'])
                ->setParent($parent)
                ->setRoot($parent ? $parent->getRoot() ?? $parent : null);

            $manager->persist($category);

            // Recursive call for any children
            if (!empty($data['children'])) {
                $this->createCategories($manager, $data['children'], $category);
            }
        }
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
