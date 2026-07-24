<?php

namespace App\Enums;

enum OperationObligationStatus: string
{
    case Open = 'open';
    case PartiallySettled = 'partially_settled';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
}
