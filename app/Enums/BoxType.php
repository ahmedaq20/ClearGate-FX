<?php

namespace App\Enums;

enum BoxType: string
{
    case Turkish = 'turkish';
    case LocalBankWallet = 'local_bank_wallet';
    case UsdtWallet = 'usdt_wallet';
}
