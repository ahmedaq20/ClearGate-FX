<?php

namespace App\Enums;

enum OperationCommissionPayer: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';
}
