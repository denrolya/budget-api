<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transfer;
use App\Entity\User;
use App\Service\AssetsManager;
use App\Service\TransferService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class TransferServiceTest extends TestCase
{
    public function testCreateTransactionsWithoutFeeCreatesExpenseAndIncome(): void
    {
        $service = $this->createServiceWithCategories();

        $transfer = $this->createTransfer(amount: 100, rate: 1.5);

        $service->createTransactions($transfer);

        self::assertCount(2, $transfer->getTransactions());

        $fromExpense = $transfer->getFromExpense();
        $toIncome = $transfer->getToIncome();

        self::assertNotNull($fromExpense);
        self::assertNotNull($toIncome);
        self::assertSame($transfer->getFrom(), $fromExpense->getAccount());
        self::assertSame($transfer->getTo(), $toIncome->getAccount());
        self::assertSame(100.0, $fromExpense->getAmount());
        self::assertSame(150.0, $toIncome->getAmount());
        self::assertSame(['EUR' => 1.0], $fromExpense->getConvertedValues());
        self::assertSame(['EUR' => 1.0], $toIncome->getConvertedValues());
        self::assertEmpty($transfer->getFeeExpenses());
    }

    public function testCreateTransactionsWithFee(): void
    {
        $service = $this->createServiceWithCategories();

        $feeAccount = $this->createAccount('Fee Account', 'EUR');
        $transfer = $this->createTransfer(amount: 100, rate: 2);

        $service->createTransactions($transfer, [
            ['amount' => '10', 'account' => $feeAccount],
        ]);

        self::assertCount(3, $transfer->getTransactions());

        $feeExpenses = $transfer->getFeeExpenses();
        self::assertCount(1, $feeExpenses);
        self::assertSame($feeAccount, $feeExpenses[0]->getAccount());
        self::assertSame(10.0, $feeExpenses[0]->getAmount());
        self::assertSame(Category::CATEGORY_TRANSFER_FEE, $feeExpenses[0]->getCategory()->getName());
        self::assertSame(['EUR' => 1.0], $feeExpenses[0]->getConvertedValues());
    }

    public function testCreateTransactionsWithMultipleFees(): void
    {
        $service = $this->createServiceWithCategories();

        $feeAccount1 = $this->createAccount('Fee Account 1', 'EUR');
        $feeAccount2 = $this->createAccount('Fee Account 2', 'USD');
        $transfer = $this->createTransfer(amount: 100, rate: 2);

        $service->createTransactions($transfer, [
            ['amount' => '10', 'account' => $feeAccount1],
            ['amount' => '3.50', 'account' => $feeAccount2],
        ]);

        self::assertCount(4, $transfer->getTransactions());

        $feeExpenses = $transfer->getFeeExpenses();
        self::assertCount(2, $feeExpenses);
        self::assertSame(10.0, $feeExpenses[0]->getAmount());
        self::assertSame($feeAccount1, $feeExpenses[0]->getAccount());
        self::assertSame(3.5, $feeExpenses[1]->getAmount());
        self::assertSame($feeAccount2, $feeExpenses[1]->getAccount());
    }

    public function testUpdateTransactionsUpdatesExistingTransferTransactions(): void
    {
        $assetsManager = $this->createMock(AssetsManager::class);
        $assetsManager
            ->method('convert')
            ->willReturnCallback(static fn ($transaction) => ['EUR' => $transaction->getAmount()]);

        $service = $this->createServiceWithCategories($assetsManager);

        $sourceAccount = $this->createAccount('Source EUR', 'EUR');
        $targetAccount = $this->createAccount('Target UAH', 'UAH');
        $feeAccount = $this->createAccount('Fee USD', 'USD');

        $transfer = $this->createTransfer(
            amount: 100,
            rate: 2,
            from: $sourceAccount,
            to: $targetAccount,
        );

        $service->createTransactions($transfer, [
            ['amount' => '10', 'account' => $sourceAccount],
        ]);

        $newFrom = $this->createAccount('New From', 'USD');
        $newTo = $this->createAccount('New To', 'EUR');

        $transfer
            ->setFrom($newFrom)
            ->setTo($newTo)
            ->setAmount(250)
            ->setRate(1.2)
            ->setExecutedAt(new DateTimeImmutable('2024-03-13T10:00:00+00:00'));

        $service->updateTransactions($transfer, [
            ['amount' => '5', 'account' => $feeAccount],
        ]);

        $fromExpense = $transfer->getFromExpense();
        $toIncome = $transfer->getToIncome();
        $feeExpenses = $transfer->getFeeExpenses();

        self::assertNotNull($fromExpense);
        self::assertNotNull($toIncome);
        self::assertCount(1, $feeExpenses);

        self::assertSame($newFrom, $fromExpense->getAccount());
        self::assertSame(250.0, $fromExpense->getAmount());
        self::assertSame(['EUR' => 250.0], $fromExpense->getConvertedValues());

        self::assertSame($newTo, $toIncome->getAccount());
        self::assertSame(300.0, $toIncome->getAmount());
        self::assertSame(['EUR' => 300.0], $toIncome->getConvertedValues());

        self::assertSame($feeAccount, $feeExpenses[0]->getAccount());
        self::assertSame(5.0, $feeExpenses[0]->getAmount());
        self::assertSame(['EUR' => 5.0], $feeExpenses[0]->getConvertedValues());
    }

    public function testUpdateTransactionsRemovesFeeTransactionsWhenNoFeesProvided(): void
    {
        $service = $this->createServiceWithCategories();

        $transfer = $this->createTransfer(amount: 100, rate: 1);

        $service->createTransactions($transfer, [
            ['amount' => '10', 'account' => $transfer->getFrom()],
        ]);

        self::assertCount(1, $transfer->getFeeExpenses());
        self::assertCount(3, $transfer->getTransactions());

        $service->updateTransactions($transfer, []);

        self::assertEmpty($transfer->getFeeExpenses());
        self::assertCount(2, $transfer->getTransactions());
    }

    public function testUpdateTransactionsAddsFeeTransactionsWhenFeeAddedLater(): void
    {
        $service = $this->createServiceWithCategories();

        $feeAccount = $this->createAccount('Fee Account', 'EUR');
        $transfer = $this->createTransfer(amount: 100, rate: 1);

        $service->createTransactions($transfer);

        self::assertEmpty($transfer->getFeeExpenses());
        self::assertCount(2, $transfer->getTransactions());

        $service->updateTransactions($transfer, [
            ['amount' => '3.5', 'account' => $feeAccount],
        ]);

        $feeExpenses = $transfer->getFeeExpenses();

        self::assertCount(1, $feeExpenses);
        self::assertCount(3, $transfer->getTransactions());
        self::assertSame($feeAccount, $feeExpenses[0]->getAccount());
        self::assertSame(3.5, $feeExpenses[0]->getAmount());
    }

    public function testUpdateTransactionsCanChangeFromOneToMultipleFees(): void
    {
        $service = $this->createServiceWithCategories();

        $feeAccount1 = $this->createAccount('Fee 1', 'EUR');
        $feeAccount2 = $this->createAccount('Fee 2', 'USD');
        $transfer = $this->createTransfer(amount: 100, rate: 1);

        $service->createTransactions($transfer, [
            ['amount' => '10', 'account' => $feeAccount1],
        ]);

        self::assertCount(1, $transfer->getFeeExpenses());

        $service->updateTransactions($transfer, [
            ['amount' => '5', 'account' => $feeAccount1],
            ['amount' => '2', 'account' => $feeAccount2],
        ]);

        $feeExpenses = $transfer->getFeeExpenses();
        self::assertCount(2, $feeExpenses);
        self::assertCount(4, $transfer->getTransactions());
        self::assertSame(5.0, $feeExpenses[0]->getAmount());
        self::assertSame(2.0, $feeExpenses[1]->getAmount());
    }

    public function testCreateTransactionsCachesCategoryLookupsAcrossCalls(): void
    {
        $expenseTransferCategory = $this->createExpenseCategory(Category::CATEGORY_TRANSFER);
        $feeExpenseCategory = $this->createExpenseCategory(Category::CATEGORY_TRANSFER_FEE);
        $incomeTransferCategory = $this->createIncomeCategory(Category::CATEGORY_TRANSFER);

        $expenseCategoryRepository = $this->createMock(EntityRepository::class);
        $expenseCategoryRepository
            ->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($expenseTransferCategory, $feeExpenseCategory) {
                return match ($criteria['name'] ?? null) {
                    Category::CATEGORY_TRANSFER => $expenseTransferCategory,
                    Category::CATEGORY_TRANSFER_FEE => $feeExpenseCategory,
                    default => null,
                };
            });

        $incomeCategoryRepository = $this->createMock(EntityRepository::class);
        $incomeCategoryRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->willReturn($incomeTransferCategory);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static function (string $className) use ($expenseCategoryRepository, $incomeCategoryRepository) {
                return match ($className) {
                    ExpenseCategory::class => $expenseCategoryRepository,
                    IncomeCategory::class => $incomeCategoryRepository,
                    default => null,
                };
            },
        );

        $assetsManager = $this->createMock(AssetsManager::class);
        $assetsManager->expects(self::exactly(4))->method('convert')->willReturn(['EUR' => 1.0]);

        $service = new TransferService($entityManager, $assetsManager);

        $service->createTransactions($this->createTransfer(amount: 10, rate: 1));
        $service->createTransactions($this->createTransfer(amount: 20, rate: 1));
    }

    private function createServiceWithCategories(
        ?AssetsManager $assetsManager = null,
    ): TransferService {
        $expenseTransferCategory = $this->createExpenseCategory(Category::CATEGORY_TRANSFER);
        $feeExpenseCategory = $this->createExpenseCategory(Category::CATEGORY_TRANSFER_FEE);
        $incomeTransferCategory = $this->createIncomeCategory(Category::CATEGORY_TRANSFER);

        $expenseCategoryRepository = $this->createMock(EntityRepository::class);
        $expenseCategoryRepository
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($expenseTransferCategory, $feeExpenseCategory) {
                return match ($criteria['name'] ?? null) {
                    Category::CATEGORY_TRANSFER => $expenseTransferCategory,
                    Category::CATEGORY_TRANSFER_FEE => $feeExpenseCategory,
                    default => null,
                };
            });

        $incomeCategoryRepository = $this->createMock(EntityRepository::class);
        $incomeCategoryRepository->method('findOneBy')->willReturn($incomeTransferCategory);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static function (string $className) use ($expenseCategoryRepository, $incomeCategoryRepository) {
                return match ($className) {
                    ExpenseCategory::class => $expenseCategoryRepository,
                    IncomeCategory::class => $incomeCategoryRepository,
                    default => null,
                };
            },
        );

        if (null === $assetsManager) {
            $assetsManager = $this->createMock(AssetsManager::class);
            $assetsManager->method('convert')->willReturn(['EUR' => 1.0]);
        }

        return new TransferService($entityManager, $assetsManager);
    }

    private function createTransfer(
        float $amount,
        float $rate,
        ?Account $from = null,
        ?Account $to = null,
    ): Transfer {
        $from ??= $this->createAccount('From account', 'EUR');
        $to ??= $this->createAccount('To account', 'UAH');
        $owner = (new User())->setUsername('transfer-test-user');

        return (new Transfer())
            ->setOwner($owner)
            ->setFrom($from)
            ->setTo($to)
            ->setAmount($amount)
            ->setRate($rate)
            ->setExecutedAt(new DateTimeImmutable('2024-03-12T09:35:00+00:00'));
    }

    private function createAccount(string $name, string $currency): Account
    {
        return (new Account())
            ->setName($name)
            ->setCurrency($currency);
    }

    private function createExpenseCategory(string $name): ExpenseCategory
    {
        return (new ExpenseCategory())
            ->setName($name)
            ->setCreatedAt(CarbonImmutable::now())
            ->setUpdatedAt(CarbonImmutable::now());
    }

    private function createIncomeCategory(string $name): IncomeCategory
    {
        return (new IncomeCategory())
            ->setName($name)
            ->setCreatedAt(CarbonImmutable::now())
            ->setUpdatedAt(CarbonImmutable::now());
    }
}
