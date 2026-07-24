<?php

namespace App\Enums;

enum OperationObligationReason: string
{
    case CustomerPrincipal = 'customer_principal';
    case SupplierSettlement = 'supplier_settlement';
    case CustomerRefund = 'customer_refund';
    case SupplierRefund = 'supplier_refund';
    case Commission = 'commission';
}
