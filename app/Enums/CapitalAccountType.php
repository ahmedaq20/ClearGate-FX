<?php

namespace App\Enums;

enum CapitalAccountType: string
{
    case Owner = 'owner';
    case Company = 'company';
    case Investor = 'investor';
    case Partner = 'partner';
}
