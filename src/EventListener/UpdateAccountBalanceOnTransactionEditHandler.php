<?php

namespace App\EventListener;

use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\TransactionInterface;
use App\Service\FixerService;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Psr\Cache\InvalidArgumentException;

final class UpdateAccountBalanceOnTransactionEditHandler implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    public function __construct(
        private FixerService $fixerService
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!is_a($entity, TransactionInterface::class)) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);

            $isAccountChanged = !empty($changeSet['account']);
            $isAmountChanged = !empty($changeSet['amount']) && ((float)$changeSet['amount'][0] !== (float)$changeSet['amount'][1]);

            if ($isAccountChanged) {
                [$oldAccount, $newAccount] = $changeSet['account'];

                if ($isAmountChanged) {
                    [$oldAmount, $newAmount] = $changeSet['amount'];
                    $oldAccount->updateBalanceBy($entity->isExpense() ? $oldAmount : -$oldAmount);
                    $newAccount->updateBalanceBy($entity->isIncome() ? $newAmount : -$newAmount);
                } else {
                    $amount = $entity->getAmount();
                    $oldAccount->updateBalanceBy($entity->isExpense() ? $amount : -$amount);
                    $newAccount->updateBalanceBy($entity->isIncome() ? $amount : -$amount);
                }

                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(Account::class), $oldAccount);
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(Account::class), $newAccount);
            } elseif ($isAmountChanged) {
                [$oldAmount, $newAmount] = $changeSet['amount'];
                $difference = $oldAmount - $newAmount;
                $entity->getAccount()->updateBalanceBy($entity->isExpense() ? $difference : -$difference);
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(Account::class), $entity->getAccount());
            }
        }
    }
}
