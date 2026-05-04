<?php

namespace App\Enums;

enum PaymentType: string
{
    case Deposit       = 'deposit';
    case FinalPayment  = 'final_payment';
    case Refund        = 'refund';
}