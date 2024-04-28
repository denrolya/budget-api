<?php

namespace App\EventListener;

use App\Entity\Income;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;
use App\Service\FixerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Psr\Cache\InvalidArgumentException;

final class UpdateTransactionWithCompensationsValueHandler implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    private UnitOfWork $uow;

    public function __construct(
        private FixerService $fixerService,
        private EntityManagerInterface $em,
        private AssetsManager $assetsManager,
    ) {
        $this->uow = $em->getUnitOfWork();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function updateTransactionWithCompensationsValue(TransactionInterface $transaction): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->recalculateTransactionValue($transaction);

        if ($transaction->isIncome() && $transaction->getOriginalExpense()) {
            $this->recalculateTransactionValue($transaction->getOriginalExpense());
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function recalculateTransactionValue(Transaction $transaction): void
    {
        $transactionValue = $this->fixerService->convert(
            amount: $transaction->getAmount(),
            fromCurrency: $transaction->getCurrency(),
            executionDate: $transaction->getExecutedAt()
        );

        if ($transaction->isExpense() && $transaction->hasCompensations()) {
            foreach ($transaction->getCompensations() as $compensation) {
                if (empty($compensation->getConvertedValues())) {
                    $compensationValues = $this->fixerService->convert(
                        amount: $compensation->getAmount(),
                        fromCurrency: $compensation->getCurrency(),
                        executionDate: $compensation->getExecutedAt()
                    );

                    $compensation->setConvertedValues($compensationValues);
                    if (!empty($this->uow->getEntityChangeSet($compensation))) {
                        $this->uow->recomputeSingleEntityChangeSet(
                            $this->em->getClassMetadata(Income::class),
                            $compensation
                        );
                    }
                }
                $currencies = array_keys($transactionValue);
                foreach ($currencies as $currency) {
                    $transactionValue[$currency] -= $compensation->getConvertedValue($currency);
                }
            }
        }

        $transaction->setConvertedValues($transactionValue);
        if (!empty($this->uow->getEntityChangeSet($transaction))) {
            $this->uow->recomputeSingleEntityChangeSet(
                $this->em->getClassMetadata(get_class($transaction)),
                $transaction
            );
        }
    }
}
