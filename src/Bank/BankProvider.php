<?php

namespace App\Bank;

enum BankProvider: string
{
    case Wise = 'wise';
    case Monobank = 'monobank';

    public function label(): string
    {
        return match ($this) {
            self::Wise => 'Wise',
            self::Monobank => 'Monobank',
        };
    }
}
