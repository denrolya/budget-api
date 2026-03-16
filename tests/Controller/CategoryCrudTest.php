<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Tests\BaseApiTestCase;

/**
 * API contract tests for Category CRUD endpoints.
 *
 * Endpoints covered:
 *   GET    /api/categories             (API Platform GetCollection)
 *   POST   /api/categories/expense     (API Platform Post on ExpenseCategory)
 *   POST   /api/categories/income      (API Platform Post on IncomeCategory)
 *   PUT    /api/categories/{id}        (API Platform Put)
 *   DELETE /api/categories/{id}        (API Platform Delete)
 *
 * Fixtures: CategoryFixtures (Food & Drinks, Groceries, Eating Out, Rent, Transfer, Debt, etc.)
 */
class CategoryCrudTest extends BaseApiTestCase
{
    private const CATEGORY_URL = '/api/categories';
    private const EXPENSE_CATEGORY_URL = '/api/categories/expense';
    private const INCOME_CATEGORY_URL = '/api/categories/income';

    // ──────────────────────────────────────────────────────────────────────
    //  LIST — response shape
    // ──────────────────────────────────────────────────────────────────────

    public function testListCategories_returnsCorrectShape(): void
    {
        $response = $this->client->request('GET', self::CATEGORY_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        self::assertIsArray($items);
        self::assertNotEmpty($items, 'Fixture categories must be present.');

        $category = $items[0];

        // Keys expected by frontend
        self::assertArrayHasKey('id', $category);
        self::assertArrayHasKey('name', $category);
        self::assertArrayHasKey('type', $category);
        self::assertArrayHasKey('isAffectingProfit', $category);
    }

    public function testListCategories_containsExpenseAndIncomeTypes(): void
    {
        $response = $this->client->request('GET', self::CATEGORY_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        $types = array_unique(array_column($items, 'type'));
        self::assertContains('expense', $types, 'List must include expense categories.');
        self::assertContains('income', $types, 'List must include income categories.');
    }

    public function testListCategories_parentAndRootPresent(): void
    {
        $response = $this->client->request('GET', self::CATEGORY_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();

        // Find a subcategory (Groceries has parent = Food & Drinks)
        $groceries = null;
        foreach ($items as $item) {
            if ($item['name'] === 'Groceries') {
                $groceries = $item;
                break;
            }
        }
        self::assertNotNull($groceries, 'Groceries category must exist in fixtures.');
        self::assertArrayHasKey('parent', $groceries);
        self::assertArrayHasKey('root', $groceries);
        // parent should be an IRI string (API Platform serialization for relations)
        self::assertNotNull($groceries['parent'], 'Groceries must have a parent.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — expense category
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateExpenseCategory_returnsCreatedCategory(): void
    {
        $response = $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => 'Entertainment',
                'isAffectingProfit' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        // POST normalization uses category:write group — only name + isAffectingProfit (no id).
        self::assertEquals('Entertainment', $content['name']);

        // Verify in DB
        $category = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Entertainment']);
        self::assertNotNull($category);
        self::assertEquals('expense', $category->getType());
    }

    public function testCreateExpenseCategory_withParent_createsSubcategory(): void
    {
        $foodCategory = $this->em->getRepository(Category::class)->findOneBy(['name' => 'Food & Drinks']);
        self::assertNotNull($foodCategory);

        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => 'Snacks',
                'isAffectingProfit' => true,
                'parent' => $this->iri($foodCategory),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $category = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Snacks']);
        self::assertNotNull($category);
        self::assertTrue($category->hasParent());
        self::assertEquals($foodCategory->getId(), $category->getParent()->getId());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — income category
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateIncomeCategory_returnsCreatedCategory(): void
    {
        $response = $this->client->request('POST', self::INCOME_CATEGORY_URL, [
            'json' => [
                'name' => 'Freelance',
                'isAffectingProfit' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('Freelance', $content['name']);

        $category = $this->em->getRepository(IncomeCategory::class)->findOneBy(['name' => 'Freelance']);
        self::assertNotNull($category);
        self::assertEquals('income', $category->getType());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  UPDATE
    // ──────────────────────────────────────────────────────────────────────

    public function testUpdateCategory_changeName(): void
    {
        $category = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Rent']);
        self::assertNotNull($category);

        $this->client->request('PUT', self::CATEGORY_URL . '/' . $category->getId(), [
            'json' => [
                'name' => 'Housing',
                'isAffectingProfit' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($category);
        self::assertEquals('Housing', $category->getName());
    }

    public function testUpdateCategory_toggleIsAffectingProfit(): void
    {
        $category = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Rent']);
        self::assertNotNull($category);
        self::assertTrue($category->getIsAffectingProfit());

        $this->client->request('PUT', self::CATEGORY_URL . '/' . $category->getId(), [
            'json' => [
                'name' => 'Rent',
                'isAffectingProfit' => false,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($category);
        self::assertFalse($category->getIsAffectingProfit());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  DELETE
    // ──────────────────────────────────────────────────────────────────────

    public function testDeleteCategory_withoutTransactions_returns204(): void
    {
        // Create a fresh category with no transactions
        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => 'ToBeDeleted',
                'isAffectingProfit' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $category = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'ToBeDeleted']);
        self::assertNotNull($category);
        $categoryId = $category->getId();

        $this->client->request('DELETE', self::CATEGORY_URL . '/' . $categoryId);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $deleted = $this->em->getRepository(Category::class)->find($categoryId);
        self::assertNull($deleted, 'Category must be deleted.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  VALIDATION
    // ──────────────────────────────────────────────────────────────────────

    /**
     * BUG-FOUND: Category entity has no @Assert\NotBlank on $name.
     * Empty name is currently accepted (201). This test documents the current
     * behavior — when validation is added, change to assertResponseStatusCodeSame(422).
     */
    public function testCreateExpenseCategory_emptyName_acceptedButShouldBeRejected(): void
    {
        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => '',
                'isAffectingProfit' => true,
            ],
        ]);
        // BUG-FOUND: Returns 201 instead of 422. Missing @Assert\NotBlank on Category::$name.
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * BUG-FOUND: Duplicate name+type causes a database-level unique constraint violation
     * which surfaces as a 500 error, not a 422 validation error. A UniqueEntity
     * constraint should be added to prevent this.
     */
    public function testCreateExpenseCategory_duplicateNameAndType_returns500(): void
    {
        // 'Rent' already exists as expense category in fixtures
        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => 'Rent',
                'isAffectingProfit' => true,
            ],
        ]);
        // BUG-FOUND: Returns 500 (DB constraint violation) instead of 422.
        // Should add @UniqueEntity(fields=["name", "type"]) to Category entity.
        self::assertResponseStatusCodeSame(500);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SECURITY
    // ──────────────────────────────────────────────────────────────────────

    public function testListCategories_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::CATEGORY_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDGE CASES
    // ──────────────────────────────────────────────────────────────────────

    public function testListCategories_isAffectingProfitDefaults(): void
    {
        $response = $this->client->request('GET', self::CATEGORY_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();

        // Transfer category has isAffectingProfit = false
        $transferCategory = null;
        foreach ($items as $item) {
            if ($item['name'] === 'Transfer' && $item['type'] === 'expense') {
                $transferCategory = $item;
                break;
            }
        }
        self::assertNotNull($transferCategory, 'Expense Transfer category must exist.');
        self::assertFalse($transferCategory['isAffectingProfit'], 'Transfer category must not affect profit.');

        // Food & Drinks has isAffectingProfit = true
        $foodCategory = null;
        foreach ($items as $item) {
            if ($item['name'] === 'Food & Drinks') {
                $foodCategory = $item;
                break;
            }
        }
        self::assertNotNull($foodCategory, 'Food & Drinks category must exist.');
        self::assertTrue($foodCategory['isAffectingProfit'], 'Food & Drinks must affect profit.');
    }

    public function testCreateExpenseCategory_isAffectingProfitDefaultsToTrue(): void
    {
        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => 'NewCategoryDefaultProfit',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $category = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'NewCategoryDefaultProfit']);
        self::assertNotNull($category);
        self::assertTrue($category->getIsAffectingProfit(), 'Default isAffectingProfit must be true.');
    }
}
