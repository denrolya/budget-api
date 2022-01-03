<?php

namespace App\Entity;

use Carbon\CarbonInterface;
use DateTimeInterface;

interface TransactionInterface
{
    public const INCOME = 'income';
    public const REVENUE = 'revenue';
    public const EXPENSE = 'expense';

    public function getId(): ?int;

    public function getCategory(): ?Category;

    public function getRootCategory(): Category;

    public function setCategory(Category $category): TransactionInterface;

    public function getAccount(): ?Account;

    public function getCurrency(): string;

    public function setAccount(Account $account): TransactionInterface;

    public function getAmount(): ?float;

    public function setAmount(float $amount): TransactionInterface;

    public function getConvertedValue(string $currencyCode): float;

    public function getValue(): float;

    public function getNote(): ?string;

    public function setNote(string $note): TransactionInterface;

    public function getExecutedAt(): CarbonInterface|DateTimeInterface;

    public function setExecutedAt(DateTimeInterface $executedAt): self;

    public function getCanceledAt(): CarbonInterface|DateTimeInterface;

    public function setCanceledAt(DateTimeInterface $canceledAt): TransactionInterface;

    public function cancel(): TransactionInterface;

    public function isExpense(): bool;

    public function isIncome(): bool;

    public function isDebt(): bool;

    public function getType(): string;

    public function updateAccountBalance(): void;

    public function restoreAccountBalance(): void;
}
