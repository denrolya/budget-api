<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\StatisticsManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/v2/account', name: 'api_v2_account_')]
class AccountController extends AbstractFOSRestController
{
    private $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

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

    #[Route('/set-monobank-hook', name: 'set_monobank_hook', methods: 'GET')]
    public function setMonobankHook(Request $request): View
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.monobank.ua/personal/webhook', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Token' => $_ENV['MONOBANK_API_KEY'],
                ],
                'json' => [
                    "webHookUrl" => $request->getSchemeAndHttpHost() . '/api/monobank/transactions',
                ],
            ])->getContent();
        } catch (ClientExceptionInterface $e) {
            return $this->view($e, Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (RedirectionExceptionInterface $e) {
            return $this->view($e, Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (ServerExceptionInterface $e) {
            return $this->view($e, Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (TransportExceptionInterface $e) {
            return $this->view($e, Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->view($response);
    }
}
