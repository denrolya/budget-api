<?php

namespace App\Tests\Bank;

use App\Bank\BankProvider;
use App\Bank\BankProviderInterface;
use App\Bank\BankProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @group bank
 */
class BankProviderRegistryTest extends TestCase
{
    private function makeProvider(BankProvider $bankProvider): BankProviderInterface
    {
        $mock = $this->createMock(BankProviderInterface::class);
        $mock->method('getProvider')->willReturn($bankProvider);

        return $mock;
    }

    public function testGetReturnsCorrectProvider(): void
    {
        $monobank = $this->makeProvider(BankProvider::Monobank);
        $wise     = $this->makeProvider(BankProvider::Wise);

        $registry = new BankProviderRegistry([$monobank, $wise]);

        self::assertSame($monobank, $registry->get(BankProvider::Monobank));
        self::assertSame($wise,     $registry->get(BankProvider::Wise));
    }

    public function testGetThrowsForUnregisteredProvider(): void
    {
        $registry = new BankProviderRegistry([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/monobank/i');

        $registry->get(BankProvider::Monobank);
    }

    public function testAllReturnsAllProviders(): void
    {
        $monobank = $this->makeProvider(BankProvider::Monobank);
        $wise     = $this->makeProvider(BankProvider::Wise);

        $registry = new BankProviderRegistry([$monobank, $wise]);

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertContains($monobank, $all);
        self::assertContains($wise, $all);
    }

    public function testAllOnEmptyRegistryReturnsEmptyArray(): void
    {
        $registry = new BankProviderRegistry([]);

        self::assertSame([], $registry->all());
    }

    public function testLastRegisteredWinsOnDuplicateProvider(): void
    {
        $first  = $this->makeProvider(BankProvider::Monobank);
        $second = $this->makeProvider(BankProvider::Monobank);

        $registry = new BankProviderRegistry([$first, $second]);

        self::assertSame($second, $registry->get(BankProvider::Monobank));
    }
}
