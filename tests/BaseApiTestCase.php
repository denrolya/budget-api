<?php

namespace App\Tests;

use ApiPlatform\Api\IriConverterInterface;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Account;
use App\Entity\Debt;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\User;
use App\Service\AssetsManager;
use App\Service\FixerService;
use Carbon\CarbonInterface;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class BaseApiTestCase extends ApiTestCase
{
    use WithMockFixerTrait, WithMockAssetsManagerTrait;

    protected const EXPENSE_URL = '/api/transactions/expense';
    protected const INCOME_URL = '/api/transactions/income';
    protected const TRANSACTION_URL = '/api/transactions';
    protected const TRANSACTION_BULK_CREATE_URL = '/api/transactions/bulk';
    protected const TRANSACTION_LIST_URL = '/api/v2/transaction';
    protected const DEBT_URL = '/api/debts';

    protected const CATEGORY_EXPENSE_GROCERIES = 'Groceries';
    protected const CATEGORY_INCOME_SALARY = 'Salary';
    protected const CATEGORY_INCOME_COMPENSATION = 'Compensation';

    protected const TEST_USERNAME = 'test_user';

    protected Client $client;

    protected ?EntityManagerInterface $em;

    protected Account $accountCashEUR;

    protected Account $accountCashUAH;

    protected User $testUser;

    private ?string $authToken = null;

    protected function setUp(): void
    {
        $this->reloadClientWithServices();

        $user = $this->em->getRepository(User::class)->findOneBy(['username' => self::TEST_USERNAME]);
        assert($user instanceof User);
        $this->testUser = $user;
        $account = $this->em->getRepository(Account::class)->findOneBy(['name' => 'EUR Cash', 'owner' => $this->testUser]);
        assert($account instanceof Account);
        $this->accountCashEUR = $account;
        $account = $this->em->getRepository(Account::class)->findOneBy(['name' => 'UAH Card', 'owner' => $this->testUser]);
        assert($account instanceof Account);
        $this->accountCashUAH = $account;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em?->clear();
        $this->em = null;
        self::ensureKernelShutdown();
        gc_enable();
        gc_collect_cycles();
    }

    protected function createClientWithCredentials($token = null): Client
    {
        $token = $token ?? $this->getToken();

        return static::createClient([], [
            'headers' => [
                'authorization' => 'Bearer '.$token,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ],
        ]);
    }

    protected function getToken($username = self::TEST_USERNAME): string
    {
        if ($this->authToken) {
            return $this->authToken;
        }

        $container = self::getContainer();
        $user = $container
            ->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        $this->authToken = $container->get(JWTTokenManagerInterface::class)->create($user);

        return $this->authToken;
    }

    /**
     * Returns the API Platform IRI for any API resource entity.
     * Use this instead of plain getId() calls when building request payloads
     * for relation fields (AP3 requires IRIs, not plain integer IDs).
     */
    protected function iri(object $entity): string
    {
        return self::getContainer()->get(IriConverterInterface::class)->getIriFromResource($entity);
    }

    protected function buildURL(string $path, array $queryParams): string
    {
        $url = $path;
        if ($queryParams !== []) {
            $url .= '?'.http_build_query($queryParams);
        }

        return $url;
    }

    protected function reloadClientWithServices(): void
    {
        self::ensureKernelShutdown();

        $this->client = $this->createClientWithCredentials();
        $container = $this->client->getContainer();

        $this->em = $container->get('doctrine')->getManager();

        $this->mockFixerService = $this->createFixerServiceMock();
        $container->set(FixerService::class, $this->mockFixerService);

        if (property_exists($this, 'useAssetsManagerMock') && $this->useAssetsManagerMock) {
            $this->mockAssetsManager = $this->createAssetsManagerMock();
            $container->set(AssetsManager::class, $this->mockAssetsManager);
        }
    }

    protected function createExpense(
        float $amount,
        Account $account,
        ExpenseCategory $category,
        CarbonInterface $executedAt,
        string $note = null,
        array $compensations = [],
        Debt $debt = null,
    ): Expense {
        $expense = new Expense();
        $expense
            ->setAmount($amount)
            ->setExecutedAt($executedAt)
            ->setCategory($category)
            ->setAccount($account)
            ->setNote($note)
            ->setOwner($account->getOwner());

        if ($debt) {
            $expense->setDebt($debt);
        }

        foreach ($compensations as $compensation) {
            $compensationCategory = $this->em->getRepository(IncomeCategory::class)->findOneBy(['name' => 'Compensation']);
            assert($compensationCategory instanceof IncomeCategory);
            $income = new Income();
            $income
                ->setAmount($compensation['amount'])
                ->setExecutedAt($compensation['executedAt'])
                ->setCategory($compensationCategory)
                ->setAccount($compensation['account'])
                ->setNote($compensation['note'])
                ->setOwner($account->getOwner());

            $expense->addCompensation($income);
        }

        $this->em->persist($expense);
        $this->em->flush();

        return $expense;
    }

    protected function createIncome(
        float $amount,
        Account $account,
        IncomeCategory $category,
        CarbonInterface $executedAt,
        string $note = null,
        Debt $debt = null,
    ): Income {
        $income = new Income();
        $income
            ->setAccount($account)
            ->setAmount($amount)
            ->setExecutedAt($executedAt)
            ->setCategory($category)
            ->setOwner($account->getOwner())
            ->setNote($note);

        if ($debt) {
            $income->setDebt($debt);
        }

        $this->em->persist($income);
        $this->em->flush();

        return $income;
    }

}
