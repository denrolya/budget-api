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
        $this->em?->clear();
        $this->em = null;
        self::ensureKernelShutdown();
        gc_enable();
        gc_collect_cycles();
    }

    /**
     * @group smoke
     * @return void
     */
    public function testGetListWithoutArgumentsSuccess(): void
    {
        $result = $this->repo->getList();

        self::assertCount(137, $result);
    }

    public function testGetListWithBeforeArgument(): void
    {
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $result = $this->repo->getList(before: $before);

        self::assertCount(41, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBefore($before));
        self::assertTrue($result[40]->getExecutedAt()->isBefore($before));

        $before = Carbon::parse('2020-01-31')->endOfDay();
        $result = $this->repo->getList(before: $before);

        self::assertCount(2, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBefore($before));
        self::assertTrue($result[1]->getExecutedAt()->isBefore($before));
    }

    public function testGetListWithBeforeAndAfterArguments(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $result = $this->repo->getList(after: $after, before: $before);

        self::assertCount(39, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBetween($before, $after));
        self::assertTrue($result[19]->getExecutedAt()->isBetween($before, $after));
        self::assertTrue($result[38]->getExecutedAt()->isBetween($before, $after));

        $after = Carbon::parse('2020-01-01')->startOfDay();
        $result = $this->repo->getList(after: $after, before: $before);

        self::assertCount(41, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBetween($before, $after));
        self::assertTrue($result[20]->getExecutedAt()->isBetween($before, $after));
        self::assertTrue($result[40]->getExecutedAt()->isBetween($before, $after));
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

        self::assertCount(109, $result);
        self::assertTrue($result[0]->isExpense());
        self::assertTrue($result[54]->isExpense());
        self::assertTrue($result[108]->isExpense());

        $result = $this->repo->getList(type: 'income');

        self::assertCount(28, $result);
        self::assertTrue($result[0]->isIncome());
        self::assertTrue($result[14]->isIncome());
        self::assertTrue($result[27]->isIncome());
    }

    public function testGetListWithInvalidStringType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->getList(type: 'invalid');
    }

    public function testGetListWithInvalidBooleanType(): void
    {
        $result = $this->repo->getList(type: false);
        self::assertCount(137, $result);
    }

    public function testGetListWithAffectingProfitOnlyArgument(): void
    {
        $result = $this->repo->getList(affectingProfitOnly: true);
        self::assertCount(137, $result);
        self::assertTrue($result[0]->getCategory()->getIsAffectingProfit());
        self::assertTrue($result[68]->getCategory()->getIsAffectingProfit());
        self::assertTrue($result[136]->getCategory()->getIsAffectingProfit());

        $result = $this->repo->getList(affectingProfitOnly: false);

        self::assertCount(138, $result);
        $notAffectingProfit = array_filter($result, static function (Transaction $transaction) {
            return !$transaction->getCategory()->getIsAffectingProfit();
        });
        self::assertCount(138 - 137, $notAffectingProfit);
    }

    public function testGetListWithCategoriesArgument(): void
    {
        $categoryRepo = $this->em->getRepository(Category::class);

        $categories = [
            $categoryRepo->findOneBy(['name' => 'Groceries']),
        ];
        $result = $this->repo->getList(categories: $categories);
        self::assertCount(54, $result);
        self::assertEquals($categories[0]->getName(), $result[0]->getCategory()->getName());
        self::assertEquals($categories[0]->getName(), $result[27]->getCategory()->getName());
        self::assertEquals($categories[0]->getName(), $result[53]->getCategory()->getName());

        $categories[] = $categoryRepo->findOneBy(['name' => 'Salary']);
        $categoryNames = array_map(static function (Category $category) {
            return $category->getName();
        }, $categories);
        $result = $this->repo->getList(categories: $categories);
        self::assertCount(76, $result);
        self::assertContains($result[0]->getCategory()->getName(), $categoryNames);
        self::assertContains($result[38]->getCategory()->getName(), $categoryNames);
        self::assertContains($result[75]->getCategory()->getName(), $categoryNames);

        $categories = array_map(static function (Category $category) {
            return $category->getId();
        }, $categories);
        $result = $this->repo->getList(categories: $categories);
        self::assertCount(76, $result);
        self::assertContains($result[0]->getCategory()->getName(), $categoryNames);
        self::assertContains($result[38]->getCategory()->getName(), $categoryNames);
        self::assertContains($result[75]->getCategory()->getName(), $categoryNames);
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
            $categoryRepo->findOneBy(['name' => 'Groceries']),
        ];
        $result = $this->repo->getList(excludedCategories: $categories);
        self::assertCount(83, $result);
        self::assertNotEquals($categories[0]->getName(), $result[0]->getCategory()->getName());
        self::assertNotEquals($categories[0]->getName(), $result[41]->getCategory()->getName());
        self::assertNotEquals($categories[0]->getName(), $result[82]->getCategory()->getName());

        $categories[] = $categoryRepo->findOneBy(['name' => 'Salary']);
        $categoryNames = array_map(static function (Category $category) {
            return $category->getName();
        }, $categories);
        $result = $this->repo->getList(excludedCategories: $categories);
        self::assertCount(61, $result);
        self::assertNotContains($result[0]->getCategory()->getName(), $categoryNames);
        self::assertNotContains($result[30]->getCategory()->getName(), $categoryNames);
        self::assertNotContains($result[60]->getCategory()->getName(), $categoryNames);

        $categories = array_map(static function (Category $category) {
            return $category->getId();
        }, $categories);
        $result = $this->repo->getList(excludedCategories: $categories);
        self::assertCount(61, $result);
        self::assertNotContains($result[0]->getCategory()->getName(), $categoryNames);
        self::assertNotContains($result[30]->getCategory()->getName(), $categoryNames);
        self::assertNotContains($result[60]->getCategory()->getName(), $categoryNames);
    }

    public function testGetListWithAccountsArgument(): void
    {
        $accountsRepo = $this->em->getRepository(Account::class);

        $accounts = [
            $accountsRepo->findOneBy(['name' => 'UAH Card']),
        ];
        $result = $this->repo->getList(accounts: $accounts);
        self::assertCount(5, $result);
        self::assertEquals($accounts[0]->getId(), $result[0]->getAccount()->getId());
        self::assertEquals($accounts[0]->getId(), $result[2]->getAccount()->getId());
        self::assertEquals($accounts[0]->getId(), $result[4]->getAccount()->getId());

        $accounts[] = $accountsRepo->findOneBy(['name' => 'EUR Cash']);
        $accountIds = array_map(static function (Account $account) {
            return $account->getId();
        }, $accounts);
        $result = $this->repo->getList(accounts: $accounts);
        self::assertCount(137, $result);
        self::assertContains($result[0]->getAccount()->getId(), $accountIds);
        self::assertContains($result[68]->getAccount()->getId(), $accountIds);
        self::assertContains($result[136]->getAccount()->getId(), $accountIds);

        $accounts = array_map(static function (Account $account) {
            return $account->getId();
        }, $accounts);
        $result = $this->repo->getList(accounts: $accounts);
        self::assertCount(137, $result);
        self::assertContains($result[0]->getAccount()->getId(), $accountIds);
        self::assertContains($result[68]->getAccount()->getId(), $accountIds);
        self::assertContains($result[136]->getAccount()->getId(), $accountIds);
    }

    public function testGetListWithInvalidAccountsArgument(): void
    {
        $result = $this->repo->getList(accounts: ['invalid']);
        self::assertCount(0, $result);
    }

    public function testGetListWithOrderArguments(): void
    {
        $result = $this->repo->getList(orderField: 'executedAt', order: 'ASC');
        self::assertCount(137, $result);
        self::assertTrue($result[0]->getExecutedAt()->isBefore($result[136]->getExecutedAt()));

        $result = $this->repo->getList(orderField: 'executedAt', order: 'DESC');
        self::assertCount(137, $result);
        self::assertTrue($result[0]->getExecutedAt()->isAfter($result[136]->getExecutedAt()));

        $result = $this->repo->getList(orderField: 'amount', order: 'ASC');
        self::assertCount(137, $result);
        self::assertTrue($result[0]->getAmount() < $result[136]->getAmount());

        $result = $this->repo->getList(orderField: 'amount', order: 'DESC');
        self::assertCount(137, $result);
        self::assertTrue($result[0]->getAmount() > $result[136]->getAmount());
    }

    public function testGetListWithInvalidOrderFieldArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->getList(orderField: 'invalid', order: 'ASC');
    }

    public function testGetListWithInvalidOrderArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->getList(orderField: 'executedAt', order: 'INVALID');
    }
}
