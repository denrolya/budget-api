<?php

namespace App\Tests\Bank;

use App\Bank\BankProvider;
use App\Bank\BankProviderInterface;
use App\Bank\BankProviderRegistry;
use App\Bank\BankWebhookRegistrationService;
use App\Bank\Provider\MonobankProvider;
use App\Entity\BankIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @group bank
 */
class BankWebhookRegistrationServiceTest extends TestCase
{
    private function integration(BankProvider $provider): BankIntegration
    {
        $integration = new BankIntegration();
        $integration
            ->setProvider($provider)
            ->setCredentials([])
            ->setIsActive(true);

        return $integration;
    }

    public function testSupportsReturnsTrueForWebhookProvider(): void
    {
        $provider = $this->createMock(MonobankProvider::class);
        $provider->method('getProvider')->willReturn(BankProvider::Monobank);

        $service = new BankWebhookRegistrationService(
            new BankProviderRegistry([$provider]),
            $this->createMock(UrlGeneratorInterface::class),
            'https://api.example.com',
        );

        self::assertTrue($service->supports($this->integration(BankProvider::Monobank)));
    }

    public function testSupportsReturnsFalseForNonWebhookProvider(): void
    {
        $provider = new class implements BankProviderInterface {
            public function getProvider(): BankProvider
            {
                return BankProvider::Wise;
            }

            public function fetchAccounts(array $credentials): array
            {
                return [];
            }

            public function fetchExchangeRates(array $credentials): ?array
            {
                return null;
            }
        };

        $service = new BankWebhookRegistrationService(
            new BankProviderRegistry([$provider]),
            $this->createMock(UrlGeneratorInterface::class),
            'https://api.example.com',
        );

        self::assertFalse($service->supports($this->integration(BankProvider::Wise)));
    }

    public function testRegisterUsesConfiguredWebhookBaseUrl(): void
    {
        $provider = $this->createMock(MonobankProvider::class);
        $provider->method('getProvider')->willReturn(BankProvider::Monobank);
        $provider
            ->expects(self::once())
            ->method('registerWebhook')
            ->with([], 'https://api.example.com/api/webhooks/monobank');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('api_webhooks_receive', ['provider' => 'monobank'])
            ->willReturn('/api/webhooks/monobank');

        $service = new BankWebhookRegistrationService(
            new BankProviderRegistry([$provider]),
            $urlGenerator,
            'https://api.example.com',
        );

        $url = $service->register($this->integration(BankProvider::Monobank));

        self::assertSame('https://api.example.com/api/webhooks/monobank', $url);
    }

    public function testRegisterFromLocalhostRequestThrowsLogicException(): void
    {
        $provider = $this->createMock(MonobankProvider::class);
        $provider->method('getProvider')->willReturn(BankProvider::Monobank);

        $service = new BankWebhookRegistrationService(
            new BankProviderRegistry([$provider]),
            $this->createMock(UrlGeneratorInterface::class),
            '',
        );

        $request = Request::create('http://localhost/api/bank-integrations/1/register-webhook');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/localhost/i');

        $service->register($this->integration(BankProvider::Monobank), $request);
    }

    public function testRegisterFromCliWithoutBaseUrlThrowsLogicException(): void
    {
        $provider = $this->createMock(MonobankProvider::class);
        $provider->method('getProvider')->willReturn(BankProvider::Monobank);

        $service = new BankWebhookRegistrationService(
            new BankProviderRegistry([$provider]),
            $this->createMock(UrlGeneratorInterface::class),
            '',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/WEBHOOK_BASE_URL/i');

        $service->register($this->integration(BankProvider::Monobank));
    }
}
