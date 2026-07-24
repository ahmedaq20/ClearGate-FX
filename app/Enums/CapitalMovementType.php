<?php

namespace App\Enums;

enum CapitalMovementType: string
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case Expense = 'expense';
    case BoxTransfer = 'box_transfer';
    case InitialDeposit = 'initial_deposit';
    case TopUp = 'top_up';
    case Withdrawal = 'withdrawal';
    case Allocation = 'allocation';
    case Deallocation = 'deallocation';
}
