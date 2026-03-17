<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Bank\BankProvider;
use App\Bank\BankWebhookRegistrationService;
use App\Command\BankWebhooksRefreshCommand;
use App\Entity\BankIntegration;
use App\Repository\BankIntegrationRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group bank
 */
class BankWebhooksRefreshCommandTest extends TestCase
{
    private function integration(int $id, BankProvider $provider, bool $active = true): BankIntegration
    {
        $integration = new BankIntegration();
        $integration->setProvider($provider)->setIsActive($active);

        $ref = new ReflectionClass($integration);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($integration, $id);

        return $integration;
    }

    public function testFailsWhenIntegrationNotFound(): void
    {
        $repo = $this->createMock(BankIntegrationRepository::class);
        $repo->method('find')->with(999)->willReturn(null);

        $service = $this->createMock(BankWebhookRegistrationService::class);

        $command = new BankWebhooksRefreshCommand($repo, $service);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--integration' => 999]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testDryRunSkipsUnsupportedProviders(): void
    {
        $mono = $this->integration(1, BankProvider::Monobank);
        $wise = $this->integration(2, BankProvider::Wise);

        $repo = $this->createMock(BankIntegrationRepository::class);
        $repo->method('findBy')->with(['isActive' => true], ['id' => 'ASC'])->willReturn([$mono, $wise]);

        $service = $this->createMock(BankWebhookRegistrationService::class);
        $service
            ->method('supports')
            ->willReturnCallback(static fn (BankIntegration $integration) => BankProvider::Monobank === $integration->getProvider());

        $command = new BankWebhooksRefreshCommand($repo, $service);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('DRY', $tester->getDisplay());
        self::assertStringContainsString('SKIP', $tester->getDisplay());
    }

    public function testReturnsFailureWhenAnyRegistrationFails(): void
    {
        $mono = $this->integration(1, BankProvider::Monobank);

        $repo = $this->createMock(BankIntegrationRepository::class);
        $repo->method('findBy')->with(['isActive' => true], ['id' => 'ASC'])->willReturn([$mono]);

        $service = $this->createMock(BankWebhookRegistrationService::class);
        $service->method('supports')->willReturn(true);
        $service->method('register')->willThrowException(new RuntimeException('provider down'));

        $command = new BankWebhooksRefreshCommand($repo, $service);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('FAIL', $tester->getDisplay());
    }
}
