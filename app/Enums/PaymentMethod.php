<?php

namespace App\Enums;

enum PaymentMethod: string
{
    use HasOptions;

    case Cash = 'cash';
    case FuelCard = 'fuel_card';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case CompanyAccount = 'company_account';
    case EWallet = 'e_wallet';

    public function label(): string
    {
        return match ($this) {
            self::EWallet => 'E-Wallet',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }
}
