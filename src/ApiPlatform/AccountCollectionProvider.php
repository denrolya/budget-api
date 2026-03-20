<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Account;
use App\Entity\BankCardAccount;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decorates the default Doctrine collection provider for Account
 * to enrich each account with its draft transaction count in a single batch query.
 *
 * @implements ProviderInterface<Account>
 */
final readonly class AccountCollectionProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Account> $collectionProvider
     */
    public function __construct(
        #[Autowire(service: CollectionProvider::class)]
        private ProviderInterface $collectionProvider,
        private TransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @return iterable<Account>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?iterable
    {
        /** @var iterable<Account>|null $accounts */
        $accounts = $this->collectionProvider->provide($operation, $uriVariables, $context);

        if (null === $accounts) {
            return null;
        }

        $accountIdentifiers = [];
        foreach ($accounts as $account) {
            $accountId = $account->getId();
            if (null !== $accountId) {
                $accountIdentifiers[] = $accountId;
            }

            // Force proxy initialization here so that a BankIntegration filtered out by
            // OwnableFilter (e.g. stale FK pointing to another user's integration) surfaces
            // as null rather than an EntityNotFoundException thrown during serialization.
            if ($account instanceof BankCardAccount) {
                // Doctrine proxy initialization throws EntityNotFoundException at runtime
                // when OwnableFilter blocks the underlying SELECT (stale FK to another
                // user's BankIntegration). PHPStan cannot model this, but it is real.
                try {
                    $account->getBankIntegration()?->getIsActive();
                } catch (EntityNotFoundException) {
                    $account->setBankIntegration(null);
                }
            }
        }

        if ([] === $accountIdentifiers) {
            return $accounts;
        }

        $draftCounts = $this->transactionRepository->countDraftsByAccountIdentifiers($accountIdentifiers);

        foreach ($accounts as $account) {
            $accountId = $account->getId();
            if (null !== $accountId) {
                $account->setDraftCount($draftCounts[$accountId] ?? 0);
            }
        }

        return $accounts;
    }
}
