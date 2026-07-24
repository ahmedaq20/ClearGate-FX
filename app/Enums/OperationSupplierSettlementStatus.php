<?php

namespace App\Enums;

enum OperationSupplierSettlementStatus: string
{
    case Unsettled = 'unsettled';
    case PartiallySettled = 'partially_settled';
    case Settled = 'settled';
}
