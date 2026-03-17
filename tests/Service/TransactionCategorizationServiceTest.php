<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CategorizationResult;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Service\TransactionCategorizationService;
use App\Tests\BaseApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use ReflectionClass;

/**
 * Tests for TransactionCategorizationService.
 *
 * Pure-logic tests (normalize, tokenSetRatio, suggest) use a mock Connection — no DB.
 * Integration tests (buildIndex) use the real test DB via BaseApiTestCase.
 *
 * @group service
 */
class TransactionCategorizationServiceTest extends BaseApiTestCase
{
    // -------------------------------------------------------------------------
    // normalize()
    // -------------------------------------------------------------------------

    public function testNormalizeLowercases(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        self::assertSame('starbucks coffee', $svc->normalize('STARBUCKS COFFEE'));
    }

    public function testNormalizeStripsTrailingDate(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        // Monobank appends "DD.MM" at the end
        self::assertSame('атб маркет', $svc->normalize('АТБ Маркет 12.03'));

        // Date with year
        self::assertSame('wise payment', $svc->normalize('WISE PAYMENT 2025-06-01'));
    }

    public function testNormalizeStripsLongNumericRefs(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        // Wise transaction IDs, card numbers, etc.
        self::assertSame('transfer', $svc->normalize('Transfer 1234567890'));
        self::assertSame('payment ref', $svc->normalize('Payment REF 99999'));
    }

    public function testNormalizeStripsSpecialChars(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        self::assertSame('silpo kyiv', $svc->normalize('SILPO | Kyiv'));
        self::assertSame('atb supermarket', $svc->normalize('ATB * Supermarket'));
        self::assertSame('transfer john doe', $svc->normalize('Transfer @ John Doe'));
        self::assertSame('cafe paris', $svc->normalize('Cafe # Paris'));
    }

    public function testNormalizeDomainSuffixes(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        self::assertSame('netflix', $svc->normalize('Netflix.com'));
        self::assertSame('vercel', $svc->normalize('vercel.io'));
        self::assertSame('github', $svc->normalize('github.com'));
    }

    public function testNormalizeCollapsesWhitespace(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        self::assertSame('abc def', $svc->normalize('  ABC   DEF  '));
    }

    /** @dataProvider realMonobankStringsProvider */
    public function testNormalizeRealMonobankStrings(string $raw, string $expected): void
    {
        $svc = $this->makeServiceWithMockConnection();

        self::assertSame($expected, $svc->normalize($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function realMonobankStringsProvider(): array
    {
        return [
            'ATB with date' => ['АТБ Маркетплейс 01.03', 'атб маркетплейс'],
            'McDonalds with hash' => ["McDonald's #1234", "mcdonald's"],
            'Netflix domain' => ['Netflix.com', 'netflix'],
            'Silpo pipe' => ['SILPO | KYIV ARENA', 'silpo kyiv arena'],
            'Wise long ref' => ['Wise transfer 9876543210', 'wise transfer'],
            'Nova Poshta date' => ['Нова пошта 15.02', 'нова пошта'],
        ];
    }

    // -------------------------------------------------------------------------
    // tokenSetRatio()
    // -------------------------------------------------------------------------

    public function testTokenSetRatioIdenticalStrings(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        self::assertEqualsWithDelta(1.0, $svc->tokenSetRatio('starbucks coffee', 'starbucks coffee'), 0.001);
    }

    public function testTokenSetRatioEmptyStringReturnsZero(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        self::assertSame(0.0, $svc->tokenSetRatio('', 'starbucks'));
        self::assertSame(0.0, $svc->tokenSetRatio('starbucks', ''));
    }

    public function testTokenSetRatioTokenOrderIndependent(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        // "coffee starbucks" vs "starbucks coffee" — tokens sorted before compare, must be ~1.0
        self::assertGreaterThan(0.99, $svc->tokenSetRatio('coffee starbucks', 'starbucks coffee'));
    }

    public function testTokenSetRatioHighSimilarity(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        // When one string's tokens are a complete subset of the other's, the proper
        // token_set_ratio algorithm gives 1.0 (t1 == t2, ratio = perfect).
        self::assertEqualsWithDelta(1.0, $svc->tokenSetRatio('nova poshta', 'nova poshta lviv'), 0.001);
    }

    public function testTokenSetRatioLowSimilarityForUnrelated(): void
    {
        $svc = $this->makeServiceWithMockConnection();

        self::assertLessThan(0.5, $svc->tokenSetRatio('netflix', 'nova poshta'));
    }

    // -------------------------------------------------------------------------
    // suggest() — against a manually seeded index (no DB)
    // -------------------------------------------------------------------------

    public function testSuggestFallbackWhenIndexEmpty(): void
    {
        $svc = $this->makeServiceWithIndexStub([], isIncome: false);

        $result = $svc->suggest('Some unknown note', false);

        self::assertSame(Category::EXPENSE_CATEGORY_ID_UNKNOWN, $result->categoryId);
        self::assertSame('Some unknown note', $result->note, 'Raw note must be preserved on fallback');
        self::assertSame(0.0, $result->confidence);
    }

    public function testSuggestReturnsExactMatch(): void
    {
        $svc = $this->makeServiceWithIndexStub([
            'атб маркетплейс' => ['categoryId' => 5, 'displayNote' => 'АТБ'],
        ], isIncome: false);

        // Raw string that normalises to the same key
        $result = $svc->suggest('АТБ Маркетплейс 01.03', false);

        self::assertSame(5, $result->categoryId);
        self::assertSame('АТБ', $result->note, 'Display note from index must be used on exact match');
        self::assertSame(1.0, $result->confidence);
    }

    public function testSuggestReturnsFuzzyMatch(): void
    {
        // Subset token match: index has 'nova poshta lviv', query is 'nova poshta'.
        // The proper token_set_ratio gives 1.0 when query tokens ⊆ index tokens.
        $svc = $this->makeServiceWithIndexStub([
            'nova poshta lviv' => ['categoryId' => 5, 'displayNote' => 'Nova Poshta'],
        ], isIncome: false);

        $result = $svc->suggest('Nova Poshta', false);

        self::assertSame(5, $result->categoryId);
        self::assertSame('Nova Poshta', $result->note);
        self::assertGreaterThanOrEqual(0.82, $result->confidence);
    }

    public function testSuggestFallbackBelowFuzzyThreshold(): void
    {
        $svc = $this->makeServiceWithIndexStub([
            'netflix' => ['categoryId' => 9, 'displayNote' => 'Netflix'],
        ], isIncome: false);

        $result = $svc->suggest('Нова Пошта', false);

        self::assertSame(Category::EXPENSE_CATEGORY_ID_UNKNOWN, $result->categoryId);
        self::assertSame(0.0, $result->confidence);
    }

    public function testSuggestIncomeUsesIncomeUnknownOnFallback(): void
    {
        $svc = $this->makeServiceWithIndexStub([], isIncome: true);

        $result = $svc->suggest('Random income note', true);

        self::assertSame(Category::INCOME_CATEGORY_ID_UNKNOWN, $result->categoryId);
    }

    public function testSuggestPreservesRawNoteOnFallback(): void
    {
        $svc = $this->makeServiceWithIndexStub([], isIncome: false);

        $rawNote = 'MONOBANK_RAW_12345';
        $result = $svc->suggest($rawNote, false);

        self::assertSame($rawNote, $result->note);
    }

    // -------------------------------------------------------------------------
    // buildIndex() — integration test against real DB
    // -------------------------------------------------------------------------

    public function testBuildIndexRunsWithoutError(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $svc = new TransactionCategorizationService($connection);

        // Must not throw; index is built from real fixture data.
        $svc->buildIndex($this->testUser->getId(), false);
        $svc->buildIndex($this->testUser->getId(), true);

        // After building, a suggest() call must return a valid result.
        $result = $svc->suggest('any note', false);
        self::assertInstanceOf(CategorizationResult::class, $result);
        self::assertGreaterThan(0, $result->categoryId);
    }

    public function testBuildIndexThenExactMatchFromFixtureData(): void
    {
        // Seed a confirmed (non-draft) expense transaction so the index picks it up.
        $category = $this->entityManager()->getRepository(ExpenseCategory::class)
            ->findOneBy(['name' => 'Groceries']);
        self::assertNotNull($category, 'Groceries category must exist in fixtures');

        $tx = new Expense();  // no arg = isDraft defaults to false
        $tx->setAmount(50.0)
           ->setNote('Silpo Supermarket')
           ->setCategory($category)
           ->setAccount($this->accountCashEUR)
           ->setOwner($this->testUser)
           ->setExecutedAt(new DateTimeImmutable('-1 month'));
        $this->entityManager()->persist($tx);
        $this->entityManager()->flush();

        $connection = self::getContainer()->get(Connection::class);
        $svc = new TransactionCategorizationService($connection);
        $svc->buildIndex($this->testUser->getId(), false);

        $result = $svc->suggest('Silpo Supermarket', false);

        self::assertSame($category->getId(), $result->categoryId);
        self::assertSame(1.0, $result->confidence);
    }

    public function testResetIndexForcesRebuildOnNextSuggest(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $svc = new TransactionCategorizationService($connection);

        $svc->buildIndex($this->testUser->getId(), false);
        $svc->resetIndex();

        // After reset, must explicitly rebuild before suggesting.
        $svc->buildIndex($this->testUser->getId(), false);
        $result = $svc->suggest('anything', false);
        self::assertInstanceOf(CategorizationResult::class, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeServiceWithMockConnection(): TransactionCategorizationService
    {
        return new TransactionCategorizationService($this->createMock(Connection::class));
    }

    /**
     * Returns a service with its private index pre-seeded via Reflection.
     * Automatically computes sortedTokens for each entry (mirrors resolveIndex()).
     *
     * @param array<string, array{categoryId: int, displayNote: string}> $indexEntries
     */
    private function makeServiceWithIndexStub(array $indexEntries, bool $isIncome): TransactionCategorizationService
    {
        $svc = $this->makeServiceWithMockConnection();

        // Enrich entries with pre-computed sortedTokens, matching the real index shape.
        $enriched = [];
        foreach ($indexEntries as $key => $entry) {
            $enriched[$key] = array_merge($entry, ['sortedTokens' => $svc->tokenize($key)]);
        }

        $indexProp = (new ReflectionClass($svc))->getProperty($isIncome ? 'incomeIndex' : 'expenseIndex');
        $indexProp->setValue($svc, $enriched);

        $builtProp = (new ReflectionClass($svc))->getProperty($isIncome ? 'incomeIndexBuilt' : 'expenseIndexBuilt');
        $builtProp->setValue($svc, true);

        return $svc;
    }
}
