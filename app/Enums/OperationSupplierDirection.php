<?php

namespace App\Enums;

enum OperationSupplierDirection: string
{
    case SupplierPaysIntermediary = 'supplier_pays_intermediary';
    case IntermediaryPaysSupplier = 'intermediary_pays_supplier';
}
