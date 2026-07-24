<?php

namespace App\Enums;

enum OperationCustomerDirection: string
{
    case CustomerPaysIntermediary = 'customer_pays_intermediary';
    case IntermediaryPaysCustomer = 'intermediary_pays_customer';
}
