<?php

namespace App\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\User;
use App\Service\FixerService;
use Carbon\CarbonInterface;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Spatie\Snapshots\MatchesSnapshots;

class BaseApiTestCase extends ApiTestCase
{
    use WithMockFixerTrait, MatchesSnapshots;

    protected const TEST_USERNAME = 'drolya';

    protected Client $client;

    protected ?EntityManagerInterface $em;

    private ?string $authToken = null;

    protected function setUp(): void
    {
        $this->reloadClientWithServices();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
        $this->em = null;
        gc_collect_cycles();
    }

    protected function createClientWithCredentials($token = null): Client
    {
        $token = $token ?: $this->getToken();

        return static::createClient([], ['headers' => ['authorization' => 'Bearer '.$token]]);
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

    protected function buildURL(string $path, array $queryParams): string
    {
        $url = $path;
        if (!empty($queryParams)) {
            $url .= '?'.http_build_query($queryParams);
        }

        return $url;
    }

    protected function reloadClientWithServices(): void
    {
        self::ensureKernelShutdown();
        $this->client = $this->createClientWithCredentials();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
        $this->mockFixerService = $this->createFixerServiceMock();
        $this->client->getContainer()->set(FixerService::class, $this->mockFixerService);
    }

    protected function createExpense(
        float $amount,
        Account $account,
        ExpenseCategory $category,
        CarbonInterface $executedAt,
        ?string $note = null,
        array $compensations = [],
    ): Expense {
        $expense = new Expense();
        $expense
            ->setAmount($amount)
            ->setExecutedAt($executedAt)
            ->setCategory($category)
            ->setAccount($account)
            ->setNote($note)
            ->setOwner($account->getOwner());

        foreach ($compensations as $compensation) {
            $income = new Income();
            $income
                ->setAmount($compensation['amount'])
                ->setExecutedAt($compensation['executedAt'])
                ->setCategory($this->em->getRepository(IncomeCategory::class)->findOneByName('Compensation'))
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
        ?string $note = null
    ): Income {
        $income = new Income();
        $income
            ->setAccount($account)
            ->setAmount($amount)
            ->setExecutedAt($executedAt)
            ->setCategory($category)
            ->setOwner($account->getOwner())
            ->setNote($note);

        $this->em->persist($income);
        $this->em->flush();

        return $income;
    }

}
