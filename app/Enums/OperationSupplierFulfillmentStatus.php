<?php

namespace App\Enums;

enum OperationSupplierFulfillmentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
}
