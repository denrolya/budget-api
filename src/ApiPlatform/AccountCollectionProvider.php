<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Account;
use App\Repository\TransactionRepository;
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
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable|null
    {
        /** @var iterable<Account>|null $accounts */
        $accounts = $this->collectionProvider->provide($operation, $uriVariables, $context);

        if ($accounts === null) {
            return null;
        }

        $accountIdentifiers = [];
        foreach ($accounts as $account) {
            $accountId = $account->getId();
            if ($accountId !== null) {
                $accountIdentifiers[] = $accountId;
            }
        }

        if ($accountIdentifiers === []) {
            return $accounts;
        }

        $draftCounts = $this->transactionRepository->countDraftsByAccountIdentifiers($accountIdentifiers);

        foreach ($accounts as $account) {
            $accountId = $account->getId();
            if ($accountId !== null) {
                $account->setDraftCount($draftCounts[$accountId] ?? 0);
            }
        }

        return $accounts;
    }
}
