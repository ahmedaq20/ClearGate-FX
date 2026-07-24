<?php

namespace App\Enums;

enum OperationCounterpartyRole: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
}
