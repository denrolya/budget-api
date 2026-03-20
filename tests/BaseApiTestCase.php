<?php

declare(strict_types=1);

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
    use WithMockAssetsManagerTrait;
    use WithMockFixerTrait;

    protected const EXPENSE_URL = '/api/transactions/expense';
    protected const INCOME_URL = '/api/transactions/income';
    protected const TRANSACTION_URL = '/api/transactions';
    protected const TRANSACTION_BULK_CREATE_URL = '/api/transactions/bulk';
    protected const TRANSACTION_LIST_URL = '/api/v2/transactions';
    protected const DEBT_URL = '/api/debts';

    protected const CATEGORY_EXPENSE_GROCERIES = 'Groceries';
    protected const CATEGORY_INCOME_SALARY = 'Salary';
    protected const CATEGORY_INCOME_COMPENSATION = 'Compensation';

    protected const TEST_USERNAME = 'test_user';

    protected Client $client;

    private ?EntityManagerInterface $entityManager = null;

    protected Account $accountCashEUR;

    protected Account $accountCashUAH;

    protected User $testUser;

    /** @var array<string, string> */
    private array $authTokens = [];

    protected function setUp(): void
    {
        $this->reloadClientWithServices();

        $entityManager = $this->entityManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['username' => self::TEST_USERNAME]);
        \assert($user instanceof User);
        $this->testUser = $user;
        $account = $entityManager->getRepository(Account::class)->findOneBy(['name' => 'EUR Cash', 'owner' => $this->testUser]);
        \assert($account instanceof Account);
        $this->accountCashEUR = $account;
        $account = $entityManager->getRepository(Account::class)->findOneBy(['name' => 'UAH Card', 'owner' => $this->testUser]);
        \assert($account instanceof Account);
        $this->accountCashUAH = $account;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager?->clear();
        $this->entityManager = null;
        self::ensureKernelShutdown();
        gc_enable();
        gc_collect_cycles();
    }

    protected function entityManager(): EntityManagerInterface
    {
        \assert(null !== $this->entityManager, 'EntityManager is not initialized. Call reloadClientWithServices() first.');

        return $this->entityManager;
    }

    protected function createClientWithCredentials($token = null): Client
    {
        $token = $token ?? $this->getToken();

        return static::createClient([], [
            'headers' => [
                'authorization' => 'Bearer ' . $token,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ],
        ]);
    }

    protected function getToken(string $username = self::TEST_USERNAME): string
    {
        if (isset($this->authTokens[$username])) {
            return $this->authTokens[$username];
        }

        $container = self::getContainer();
        $user = $container
            ->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        \assert($user instanceof User);
        $this->authTokens[$username] = $container->get(JWTTokenManagerInterface::class)->create($user);

        return $this->authTokens[$username];
    }

    protected function createOtherUser(string $usernameSuffix): User
    {
        $user = new User();
        $user->setUsername('other_' . $usernameSuffix)->setPassword('pw')->setRoles(['ROLE_USER']);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    protected function getClientForUser(User $user): Client
    {
        $container = self::getContainer();
        \assert($container !== null);
        $token = $container->get(JWTTokenManagerInterface::class)->create($user);

        return static::createClient([], [
            'headers' => [
                'authorization' => 'Bearer ' . $token,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ],
        ]);
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

    /**
     * Returns the test container, asserting it is not null.
     */
    protected function container(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        $container = $this->client->getContainer();
        \assert(null !== $container);

        return $container;
    }

    protected function buildURL(string $path, array $queryParams): string
    {
        $url = $path;
        if ([] !== $queryParams) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    protected function reloadClientWithServices(): void
    {
        self::ensureKernelShutdown();

        $this->client = $this->createClientWithCredentials();
        $container = $this->client->getContainer();
        \assert(null !== $container);

        $objectManager = $container->get('doctrine')->getManager();
        \assert($objectManager instanceof EntityManagerInterface);
        $this->entityManager = $objectManager;

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
        ?string $note = null,
        array $compensations = [],
        ?Debt $debt = null,
    ): Expense {
        $entityManager = $this->entityManager();
        $expense = new Expense();
        $owner = $account->getOwner();
        \assert(null !== $owner);
        $expense
            ->setAmount($amount)
            ->setExecutedAt($executedAt)
            ->setCategory($category)
            ->setAccount($account)
            ->setNote($note)
            ->setOwner($owner);

        if ($debt) {
            $expense->setDebt($debt);
        }

        foreach ($compensations as $compensation) {
            $compensationCategory = $entityManager->getRepository(IncomeCategory::class)->findOneBy(['name' => 'Compensation']);
            \assert($compensationCategory instanceof IncomeCategory);
            $compensationOwner = $account->getOwner();
            \assert(null !== $compensationOwner);
            $income = new Income();
            $income
                ->setAmount($compensation['amount'])
                ->setExecutedAt($compensation['executedAt'])
                ->setCategory($compensationCategory)
                ->setAccount($compensation['account'])
                ->setNote($compensation['note'])
                ->setOwner($compensationOwner);

            $expense->addCompensation($income);
        }

        $entityManager->persist($expense);
        $entityManager->flush();

        return $expense;
    }

    protected function createIncome(
        float $amount,
        Account $account,
        IncomeCategory $category,
        CarbonInterface $executedAt,
        ?string $note = null,
        ?Debt $debt = null,
    ): Income {
        $entityManager = $this->entityManager();
        $income = new Income();
        $owner = $account->getOwner();
        \assert(null !== $owner);
        $income
            ->setAccount($account)
            ->setAmount($amount)
            ->setExecutedAt($executedAt)
            ->setCategory($category)
            ->setOwner($owner)
            ->setNote($note);

        if ($debt) {
            $income->setDebt($debt);
        }

        $entityManager->persist($income);
        $entityManager->flush();

        return $income;
    }
}
