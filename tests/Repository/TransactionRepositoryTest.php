<?php

namespace App\Tests\Repository;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Carbon\Carbon;
use Doctrine\ORM\Query\QueryException;
use Doctrine\Persistence\ObjectManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransactionRepositoryTest extends KernelTestCase
{
    private ObjectManager|null $em;

    private TransactionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->repo = $this->em->getRepository(Transaction::class);
    }

    // teardown clear em
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
        $this->em = null;
    }

    /**
     * @group smoke
     * @return void
     */
    public function testGetListWithoutArgumentsSuccess(): void
    {
        $result = $this->repo->getList();

        self::assertCount(9430, $result);
    }

    public function testGetListWithBeforeArgument(): void
    {
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $result = $this->repo->getList(before: $before);

        self::assertCount(3890, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBefore($before));
        self::assertTrue($result[1500]->getExecutedAt()->isBefore($before));
        self::assertTrue($result[3889]->getExecutedAt()->isBefore($before));

        $before = Carbon::parse('2020-01-31')->endOfDay();
        $result = $this->repo->getList(before: $before);

        self::assertCount(2177, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBefore($before));
        self::assertTrue($result[1300]->getExecutedAt()->isBefore($before));
        self::assertTrue($result[2176]->getExecutedAt()->isBefore($before));
    }

    public function testGetListWithBeforeAndAfterArguments(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $result = $this->repo->getList(after: $after, before: $before);

        self::assertCount(111, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBetween($before, $after));
        self::assertTrue($result[55]->getExecutedAt()->isBetween($before, $after));
        self::assertTrue($result[110]->getExecutedAt()->isBetween($before, $after));

        $after = Carbon::parse('2020-01-01')->startOfDay();
        $result = $this->repo->getList(after: $after, before: $before);

        self::assertCount(1850, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBetween($before, $after));
        self::assertTrue($result[550]->getExecutedAt()->isBetween($before, $after));
        self::assertTrue($result[1849]->getExecutedAt()->isBetween($before, $after));
    }

    public function testGetListWithBeforeAndAfterSwappedArguments(): void
    {
        $after = Carbon::parse('2021-01-31')->startOfDay();
        $before = Carbon::parse('2021-01-01')->endOfDay();
        $result = $this->repo->getList(after: $after, before: $before);

        self::assertCount(0, $result);
    }

    public function testGetListWithType(): void
    {
        $result = $this->repo->getList(type: 'expense');

        self::assertCount(9200, $result);
        self::assertTrue($result[0]->isExpense());
        self::assertTrue($result[4500]->isExpense());
        self::assertTrue($result[9196]->isExpense());

        $result = $this->repo->getList(type: 'income');

        self::assertCount(230, $result);
        self::assertTrue($result[0]->isIncome());
        self::assertTrue($result[100]->isIncome());
        self::assertTrue($result[229]->isIncome());
    }

    public function testGetListWithInvalidStringType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->getList(type: 'invalid');
    }

    public function testGetListWithInvalidBooleanType(): void
    {
        $result = $this->repo->getList(type: false);
        self::assertCount(9430, $result);
    }

    public function testGetListWithAffectingProfitOnlyArgument(): void
    {
        $result = $this->repo->getList(affectingProfitOnly: true);
        self::assertCount(9430, $result);
        self::assertTrue($result[0]->getCategory()->getIsAffectingProfit());
        self::assertTrue($result[3000]->getCategory()->getIsAffectingProfit());
        self::assertTrue($result[9426]->getCategory()->getIsAffectingProfit());

        $result = $this->repo->getList(affectingProfitOnly: false);

        self::assertCount(10950, $result);
        $notAffectingProfit = array_filter($result, static function (Transaction $transaction) {
            return !$transaction->getCategory()->getIsAffectingProfit();
        });
        self::assertCount(10950 - 9430, $notAffectingProfit);
    }

    public function testGetListWithCategoriesArgument(): void
    {
        $categoryRepo = $this->em->getRepository(Category::class);

        $categories = [
            $categoryRepo->findOneBy(['name' => 'Food & Drinks']),
        ];
        $result = $this->repo->getList(categories: $categories);
        self::assertCount(374, $result);
        self::assertEquals($categories[0]->getName(), $result[0]->getCategory()->getName());
        self::assertEquals($categories[0]->getName(), $result[150]->getCategory()->getName());
        self::assertEquals($categories[0]->getName(), $result[370]->getCategory()->getName());

        $categories[] = $categoryRepo->findOneBy(['name' => 'Salary']);
        $categoryNames = array_map(static function (Category $category) {
            return $category->getName();
        }, $categories);
        $result = $this->repo->getList(categories: $categories);
        self::assertCount(390, $result);
        self::assertContains($result[0]->getCategory()->getName(), $categoryNames);
        self::assertContains($result[155]->getCategory()->getName(), $categoryNames);
        self::assertContains($result[387]->getCategory()->getName(), $categoryNames);

        $categories = array_map(static function (Category $category) {
            return $category->getId();
        }, $categories);
        $result = $this->repo->getList(categories: $categories);
        self::assertCount(390, $result);
        self::assertContains($result[0]->getCategory()->getName(), $categoryNames);
        self::assertContains($result[155]->getCategory()->getName(), $categoryNames);
        self::assertContains($result[387]->getCategory()->getName(), $categoryNames);
    }

    public function testGetListWithInvalidCategoriesArgument(): void
    {
        $result = $this->repo->getList(categories: ['invalid']);
        self::assertCount(0, $result);
    }

    public function testGetListWithExcludedCategoriesArgument(): void
    {
        $categoryRepo = $this->em->getRepository(Category::class);

        $categories = [
            $categoryRepo->findOneBy(['name' => 'Food & Drinks']),
        ];
        $result = $this->repo->getList(excludedCategories: $categories);
        self::assertCount(9056, $result);
        self::assertNotEquals($categories[0]->getName(), $result[0]->getCategory()->getName());
        self::assertNotEquals($categories[0]->getName(), $result[4642]->getCategory()->getName());
        self::assertNotEquals($categories[0]->getName(), $result[9050]->getCategory()->getName());

        $categories[] = $categoryRepo->findOneBy(['name' => 'Salary']);
        $categoryNames = array_map(static function (Category $category) {
            return $category->getName();
        }, $categories);
        $result = $this->repo->getList(excludedCategories: $categories);
        self::assertCount(9040, $result);
        self::assertNotContains($result[0]->getCategory()->getName(), $categoryNames);
        self::assertNotContains($result[4777]->getCategory()->getName(), $categoryNames);
        self::assertNotContains($result[9030]->getCategory()->getName(), $categoryNames);

        $categories = array_map(static function (Category $category) {
            return $category->getId();
        }, $categories);
        $result = $this->repo->getList(excludedCategories: $categories);
        self::assertCount(9040, $result);
        self::assertNotContains($result[0]->getCategory()->getName(), $categoryNames);
        self::assertNotContains($result[4755]->getCategory()->getName(), $categoryNames);
        self::assertNotContains($result[9038]->getCategory()->getName(), $categoryNames);
    }

    public function testGetListWithAccountsArgument(): void
    {
        $accountsRepo = $this->em->getRepository(Account::class);

        $accounts = [
            $accountsRepo->findOneBy(['id' => 10]),
        ];
        $result = $this->repo->getList(accounts: $accounts);
        self::assertCount(4923, $result);
        self::assertEquals($accounts[0]->getId(), $result[0]->getAccount()->getId());
        self::assertEquals($accounts[0]->getId(), $result[1500]->getAccount()->getId());
        self::assertEquals($accounts[0]->getId(), $result[4919]->getAccount()->getId());

        $accounts[] = $accountsRepo->findOneBy(['id' => 4]);
        $accountIds = array_map(static function (Account $account) {
            return $account->getId();
        }, $accounts);
        $result = $this->repo->getList(accounts: $accounts);
        self::assertCount(7755, $result);
        self::assertContains($result[0]->getAccount()->getId(), $accountIds);
        self::assertContains($result[4650]->getAccount()->getId(), $accountIds);
        self::assertContains($result[7751]->getAccount()->getId(), $accountIds);

        $accounts = array_map(static function (Account $account) {
            return $account->getId();
        }, $accounts);
        $result = $this->repo->getList(accounts: $accounts);
        self::assertCount(7755, $result);
        self::assertContains($result[0]->getAccount()->getId(), $accountIds);
        self::assertContains($result[4650]->getAccount()->getId(), $accountIds);
        self::assertContains($result[7751]->getAccount()->getId(), $accountIds);
    }

    public function testGetListWithInvalidAccountsArgument(): void
    {
        $result = $this->repo->getList(accounts: ['invalid']);
        self::assertCount(0, $result);
    }

    public function testGetListWithOrderArguments(): void
    {
        $result = $this->repo->getList(orderField: 'executedAt', order: 'ASC');
        self::assertCount(9430, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBefore($result[9426]->getExecutedAt()));

        $result = $this->repo->getList(orderField: 'executedAt', order: 'DESC');
        self::assertCount(9430, $result);
        self::assertTrue($result[0]->getExecutedAt()->isAfter($result[9426]->getExecutedAt()));

        $result = $this->repo->getList(orderField: 'amount', order: 'ASC');
        self::assertCount(9430, $result);
        self::assertTrue($result[0]->getAmount() < $result[9426]->getAmount());

        $result = $this->repo->getList(orderField: 'amount', order: 'DESC');
        self::assertCount(9430, $result);
        self::assertTrue($result[0]->getAmount() > $result[9426]->getAmount());
    }

    public function testGetListWithInvalidOrderFieldArguments(): void
    {
        $this->expectException(QueryException::class);
        $this->repo->getList(orderField: 'invalid', order: 'ASC');
    }

    public function testGetListWithInvalidOrderArgument(): void
    {
        $this->expectException(QueryException::class);
        $this->repo->getList(orderField: 'executedAt', order: 'INVALID');
    }
}
