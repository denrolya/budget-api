<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\StatisticsManager;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;

#[Route('/api/v2/account', name: 'api_v2_account_')]
class AccountController extends AbstractFOSRestController
{
    #[Rest\View(serializerGroups: ['account:collection:read'])]
    #[Route('', name: 'collection_read', methods: ['get'])]
    public function collection(ManagerRegistry $doctrine): View
    {
        return $this->view(
            $doctrine->getRepository(Account::class)->findAll()
        );
    }

    #[Rest\View(serializerGroups: ['account:item:read'])]
    #[Route('/{id<\d+>}', name: 'item_read', methods: ['get'])]
    public function item(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, Account $account): View
    {
        $accountTransactions = $doctrine->getRepository(Transaction::class)->getList(
            null,
            null,
            null,
            null,
            [$account]
        );

        $account->setTopExpenseCategories(
            $statisticsManager->generateCategoryTreeWithValues(
                null,
                array_filter($accountTransactions, static function (TransactionInterface $transaction) {
                    return $transaction->isExpense();
                }),
                ExpenseCategory::class
            )
        );

        $account->setTopIncomeCategories(
            $statisticsManager->generateCategoryTreeWithValues(
                null,
                array_filter($accountTransactions, static function (TransactionInterface $transaction) {
                    return $transaction->isIncome();
                }),
                IncomeCategory::class
            )
        );

        return $this->view($account);
    }
}
