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

    public function testListCategoriesReturnsCorrectShape(): void
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

    public function testListCategoriesContainsExpenseAndIncomeTypes(): void
    {
        $response = $this->client->request('GET', self::CATEGORY_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        $types = array_unique(array_column($items, 'type'));
        self::assertContains('expense', $types, 'List must include expense categories.');
        self::assertContains('income', $types, 'List must include income categories.');
    }

    public function testListCategoriesParentAndRootPresent(): void
    {
        $response = $this->client->request('GET', self::CATEGORY_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();

        // Find a subcategory (Groceries has parent = Food & Drinks)
        $groceries = null;
        foreach ($items as $item) {
            if ('Groceries' === $item['name']) {
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

    public function testCreateExpenseCategoryReturnsCreatedCategory(): void
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
        $category = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Entertainment']);
        self::assertNotNull($category);
        self::assertEquals('expense', $category->getType());
    }

    public function testCreateExpenseCategoryWithParentCreatesSubcategory(): void
    {
        $foodCategory = $this->entityManager()->getRepository(Category::class)->findOneBy(['name' => 'Food & Drinks']);
        self::assertNotNull($foodCategory);

        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => 'Snacks',
                'isAffectingProfit' => true,
                'parent' => $this->iri($foodCategory),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $category = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Snacks']);
        \assert($category instanceof ExpenseCategory);
        self::assertTrue($category->hasParent());
        $parent = $category->getParent();
        \assert(null !== $parent);
        self::assertEquals($foodCategory->getId(), $parent->getId());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — income category
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateIncomeCategoryReturnsCreatedCategory(): void
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

        $category = $this->entityManager()->getRepository(IncomeCategory::class)->findOneBy(['name' => 'Freelance']);
        self::assertNotNull($category);
        self::assertEquals('income', $category->getType());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  UPDATE
    // ──────────────────────────────────────────────────────────────────────

    public function testUpdateCategoryChangeName(): void
    {
        $category = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Rent']);
        self::assertNotNull($category);

        $this->client->request('PUT', self::CATEGORY_URL . '/' . $category->getId(), [
            'json' => [
                'name' => 'Housing',
                'isAffectingProfit' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->entityManager()->refresh($category);
        self::assertEquals('Housing', $category->getName());
    }

    public function testUpdateCategoryToggleIsAffectingProfit(): void
    {
        $category = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Rent']);
        self::assertNotNull($category);
        self::assertTrue($category->getIsAffectingProfit());

        $this->client->request('PUT', self::CATEGORY_URL . '/' . $category->getId(), [
            'json' => [
                'name' => 'Rent',
                'isAffectingProfit' => false,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->entityManager()->refresh($category);
        self::assertFalse($category->getIsAffectingProfit());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  DELETE
    // ──────────────────────────────────────────────────────────────────────

    public function testDeleteCategoryWithoutTransactionsReturns204(): void
    {
        // Create a fresh category with no transactions
        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => 'ToBeDeleted',
                'isAffectingProfit' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $category = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'ToBeDeleted']);
        self::assertNotNull($category);
        $categoryId = $category->getId();

        $this->client->request('DELETE', self::CATEGORY_URL . '/' . $categoryId);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager()->clear();
        $deleted = $this->entityManager()->getRepository(Category::class)->find($categoryId);
        self::assertNull($deleted, 'Category must be deleted.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  VALIDATION
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateExpenseCategoryEmptyNameReturns422(): void
    {
        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => '',
                'isAffectingProfit' => true,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * BUG-FOUND: Duplicate name+type causes a database-level unique constraint violation
     * which surfaces as a 500 error, not a 422 validation error. A UniqueEntity
     * constraint should be added to prevent this.
     */
    public function testCreateExpenseCategoryDuplicateNameAndTypeReturns500(): void
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

    public function testListCategoriesWithoutAuthReturns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::CATEGORY_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDGE CASES
    // ──────────────────────────────────────────────────────────────────────

    public function testListCategoriesIsAffectingProfitDefaults(): void
    {
        $response = $this->client->request('GET', self::CATEGORY_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();

        // Transfer category has isAffectingProfit = false
        $transferCategory = null;
        foreach ($items as $item) {
            if ('Transfer' === $item['name'] && 'expense' === $item['type']) {
                $transferCategory = $item;
                break;
            }
        }
        self::assertNotNull($transferCategory, 'Expense Transfer category must exist.');
        self::assertFalse($transferCategory['isAffectingProfit'], 'Transfer category must not affect profit.');

        // Food & Drinks has isAffectingProfit = true
        $foodCategory = null;
        foreach ($items as $item) {
            if ('Food & Drinks' === $item['name']) {
                $foodCategory = $item;
                break;
            }
        }
        self::assertNotNull($foodCategory, 'Food & Drinks category must exist.');
        self::assertTrue($foodCategory['isAffectingProfit'], 'Food & Drinks must affect profit.');
    }

    public function testCreateExpenseCategoryIsAffectingProfitDefaultsToTrue(): void
    {
        $this->client->request('POST', self::EXPENSE_CATEGORY_URL, [
            'json' => [
                'name' => 'NewCategoryDefaultProfit',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $category = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'NewCategoryDefaultProfit']);
        self::assertNotNull($category);
        self::assertTrue($category->getIsAffectingProfit(), 'Default isAffectingProfit must be true.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  ISOLATION — cross-user data must not be visible
    // ──────────────────────────────────────────────────────────────────────

    public function testListCategories_withOtherUserData_returnsOnlyOwnData(): void
    {
        $otherUser = $this->createOtherUser('category_list');

        $otherCategory = new ExpenseCategory('Other User Category');
        $otherCategory->setIsAffectingProfit(true)->setOwner($otherUser);
        $this->entityManager()->persist($otherCategory);
        $this->entityManager()->flush();

        $response = $this->client->request('GET', self::CATEGORY_URL);
        self::assertResponseIsSuccessful();

        $identifiers = array_column($response->toArray(), 'id');
        self::assertNotContains(
            $otherCategory->getId(),
            $identifiers,
            'Other user\'s category must not appear in the authenticated user\'s list.',
        );
    }
}
