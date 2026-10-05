<?php

namespace App\Domain\Devices\Enums;

/** LIGHT = relay controller for table lamps; CASH = bill acceptor box (one per branch). */
enum DeviceKind: string
{
    case LIGHT = 'LIGHT';
    case CASH = 'CASH';
}
