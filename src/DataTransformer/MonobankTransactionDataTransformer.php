<?php

namespace App\DataTransformer;

use ApiPlatform\Core\DataTransformer\DataTransformerInterface;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\MonobankService;

final class MonobankTransactionDataTransformer implements DataTransformerInterface
{
    public function __construct(private MonobankService $monobankService)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function transform($object, string $to, array $context = []): TransactionInterface
    {
        return $this->monobankService->convertStatementItemToDraftTransaction(
            $object->accountId,
            $object->statementItem
        );
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTransformation($data, string $to, array $context = []): bool
    {
        if($data instanceof Transaction) {
            return false;
        }

        return Transaction::class === $to;
    }
}
