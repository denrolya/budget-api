<?php

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
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

class TransferServiceTest extends TestCase
{
    public function testCreateTransactionsWithoutFeeCreatesExpenseAndIncome(): void
    {
        $service = $this->createServiceWithCategories($this->createMock(EntityManagerInterface::class));

        $transfer = $this->createTransfer(
            amount: 100,
            rate: 1.5,
            fee: 0,
        );

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
        self::assertNull($transfer->getFeeExpense());
    }

    public function testCreateTransactionsWithFeeUsesFromAccountWhenFeeAccountMissing(): void
    {
        $service = $this->createServiceWithCategories($this->createMock(EntityManagerInterface::class));

        $transfer = $this->createTransfer(
            amount: 100,
            rate: 2,
            fee: 10,
            feeAccount: null,
        );

        $service->createTransactions($transfer);

        self::assertCount(3, $transfer->getTransactions());

        $feeExpense = $transfer->getFeeExpense();

        self::assertNotNull($feeExpense);
        self::assertSame($transfer->getFrom(), $feeExpense->getAccount());
        self::assertSame(10.0, $feeExpense->getAmount());
        self::assertSame(Category::CATEGORY_TRANSFER_FEE, $feeExpense->getCategory()->getName());
        self::assertSame(['EUR' => 1.0], $feeExpense->getConvertedValues());
    }

    public function testUpdateTransactionsUpdatesExistingTransferTransactions(): void
    {
        $assetsManager = $this->createMock(AssetsManager::class);
        $assetsManager
            ->method('convert')
            ->willReturnCallback(static fn($transaction) => ['EUR' => $transaction->getAmount()]);

        $service = $this->createServiceWithCategories($this->createMock(EntityManagerInterface::class), $assetsManager);

        $sourceAccount = $this->createAccount('Source EUR', 'EUR');
        $targetAccount = $this->createAccount('Target UAH', 'UAH');
        $feeAccount = $this->createAccount('Fee USD', 'USD');

        $transfer = $this->createTransfer(
            amount: 100,
            rate: 2,
            fee: 10,
            from: $sourceAccount,
            to: $targetAccount,
            feeAccount: $sourceAccount,
        );

        $service->createTransactions($transfer);

        $newFrom = $this->createAccount('New From', 'USD');
        $newTo = $this->createAccount('New To', 'EUR');

        $transfer
            ->setFrom($newFrom)
            ->setTo($newTo)
            ->setAmount(250)
            ->setRate(1.2)
            ->setFee(5)
            ->setFeeAccount($feeAccount)
            ->setExecutedAt(new DateTimeImmutable('2024-03-13T10:00:00+00:00'));

        $service->updateTransactions($transfer);

        $fromExpense = $transfer->getFromExpense();
        $toIncome = $transfer->getToIncome();
        $feeExpense = $transfer->getFeeExpense();

        self::assertNotNull($fromExpense);
        self::assertNotNull($toIncome);
        self::assertNotNull($feeExpense);

        self::assertSame($newFrom, $fromExpense->getAccount());
        self::assertSame(250.0, $fromExpense->getAmount());
        self::assertSame(['EUR' => 250.0], $fromExpense->getConvertedValues());

        self::assertSame($newTo, $toIncome->getAccount());
        self::assertSame(300.0, $toIncome->getAmount());
        self::assertSame(['EUR' => 300.0], $toIncome->getConvertedValues());

        self::assertSame($feeAccount, $feeExpense->getAccount());
        self::assertSame(5.0, $feeExpense->getAmount());
        self::assertSame(['EUR' => 5.0], $feeExpense->getConvertedValues());
    }

    public function testUpdateTransactionsRemovesFeeTransactionWhenFeeBecomesZero(): void
    {
        $service = $this->createServiceWithCategories($this->createMock(EntityManagerInterface::class));

        $transfer = $this->createTransfer(
            amount: 100,
            rate: 1,
            fee: 10,
        );

        $service->createTransactions($transfer);

        self::assertNotNull($transfer->getFeeExpense());
        self::assertCount(3, $transfer->getTransactions());

        $transfer->setFee(0);
        $service->updateTransactions($transfer);

        self::assertNull($transfer->getFeeExpense());
        self::assertCount(2, $transfer->getTransactions());
    }

    public function testUpdateTransactionsAddsFeeTransactionWhenFeeAddedLater(): void
    {
        $service = $this->createServiceWithCategories($this->createMock(EntityManagerInterface::class));

        $feeAccount = $this->createAccount('Fee Account', 'EUR');

        $transfer = $this->createTransfer(
            amount: 100,
            rate: 1,
            fee: 0,
        );

        $service->createTransactions($transfer);

        self::assertNull($transfer->getFeeExpense());
        self::assertCount(2, $transfer->getTransactions());

        $transfer->setFee(3.5)->setFeeAccount($feeAccount);
        $service->updateTransactions($transfer);

        $feeExpense = $transfer->getFeeExpense();

        self::assertNotNull($feeExpense);
        self::assertCount(3, $transfer->getTransactions());
        self::assertSame($feeAccount, $feeExpense->getAccount());
        self::assertSame(3.5, $feeExpense->getAmount());
    }

    public function testCreateTransactionsCachesCategoryLookupsAcrossCalls(): void
    {
        $expenseTransferCategory = $this->createExpenseCategory(Category::CATEGORY_TRANSFER);
        $feeExpenseCategory = $this->createExpenseCategory(Category::CATEGORY_TRANSFER_FEE);
        $incomeTransferCategory = $this->createIncomeCategory(Category::CATEGORY_TRANSFER);

        $expenseRepo = $this->createMock(ObjectRepository::class);
        $expenseRepo
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($expenseTransferCategory, $feeExpenseCategory) {
                if (($criteria['name'] ?? null) === Category::CATEGORY_TRANSFER) {
                    return $expenseTransferCategory;
                }

                if (($criteria['name'] ?? null) === Category::CATEGORY_TRANSFER_FEE) {
                    return $feeExpenseCategory;
                }

                return null;
            });

        $incomeRepo = $this->createMock(ObjectRepository::class);
        $incomeRepo->method('findOneBy')->willReturn($incomeTransferCategory);

        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects(self::exactly(3))
            ->method('getRepository')
            ->willReturnCallback(static function (string $entityClass) use ($expenseRepo, $incomeRepo) {
                if ($entityClass === ExpenseCategory::class) {
                    return $expenseRepo;
                }

                if ($entityClass === IncomeCategory::class) {
                    return $incomeRepo;
                }

                throw new \InvalidArgumentException('Unexpected repository request: '.$entityClass);
            });

        $assetsManager = $this->createMock(AssetsManager::class);
        $assetsManager->expects(self::exactly(4))->method('convert')->willReturn(['EUR' => 1.0]);

        $service = new TransferService($em, $assetsManager);

        $service->createTransactions($this->createTransfer(amount: 10, rate: 1, fee: 0));
        $service->createTransactions($this->createTransfer(amount: 20, rate: 1, fee: 0));
    }

    private function createServiceWithCategories(
        EntityManagerInterface $em,
        ?AssetsManager $assetsManager = null,
    ): TransferService {
        $expenseTransferCategory = $this->createExpenseCategory(Category::CATEGORY_TRANSFER);
        $feeExpenseCategory = $this->createExpenseCategory(Category::CATEGORY_TRANSFER_FEE);
        $incomeTransferCategory = $this->createIncomeCategory(Category::CATEGORY_TRANSFER);

        $expenseRepo = $this->createMock(ObjectRepository::class);
        $expenseRepo
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($expenseTransferCategory, $feeExpenseCategory) {
                if (($criteria['name'] ?? null) === Category::CATEGORY_TRANSFER) {
                    return $expenseTransferCategory;
                }

                if (($criteria['name'] ?? null) === Category::CATEGORY_TRANSFER_FEE) {
                    return $feeExpenseCategory;
                }

                return null;
            });

        $incomeRepo = $this->createMock(ObjectRepository::class);
        $incomeRepo->method('findOneBy')->willReturn($incomeTransferCategory);

        $em
            ->method('getRepository')
            ->willReturnCallback(static function (string $entityClass) use ($expenseRepo, $incomeRepo) {
                return match ($entityClass) {
                    ExpenseCategory::class => $expenseRepo,
                    IncomeCategory::class => $incomeRepo,
                    default => throw new \InvalidArgumentException('Unexpected repository request: '.$entityClass),
                };
            });

        if ($assetsManager === null) {
            $assetsManager = $this->createMock(AssetsManager::class);
            $assetsManager->method('convert')->willReturn(['EUR' => 1.0]);
        }

        return new TransferService($em, $assetsManager);
    }

    private function createTransfer(
        float $amount,
        float $rate,
        float $fee,
        ?Account $from = null,
        ?Account $to = null,
        ?Account $feeAccount = null,
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
            ->setFee($fee)
            ->setFeeAccount($feeAccount)
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
