<?php

namespace App\Domain\Branches\Enums;

/** How customers of a branch pay: at the cashier (staff marks it) or into the bill acceptor before the game starts. */
enum PaymentMode: string
{
    case CASHIER = 'CASHIER';
    case BILL_ACCEPTOR = 'BILL_ACCEPTOR';
}
