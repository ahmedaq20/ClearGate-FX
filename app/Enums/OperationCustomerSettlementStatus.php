<?php

namespace App\Enums;

enum OperationCustomerSettlementStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
}
