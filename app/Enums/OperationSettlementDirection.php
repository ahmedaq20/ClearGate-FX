<?php

namespace App\Enums;

enum OperationSettlementDirection: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
}
