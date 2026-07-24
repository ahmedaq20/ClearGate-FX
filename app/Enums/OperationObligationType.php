<?php

namespace App\Enums;

enum OperationObligationType: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
}
