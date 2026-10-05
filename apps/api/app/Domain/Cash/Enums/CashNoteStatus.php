<?php

namespace App\Domain\Cash\Enums;

/**
 * CREDITED: counted towards a paying session. UNASSIGNED: money is in the box but no session was
 * collecting (late bill after a reconnect, extra bill after full payment) — staff must resolve it.
 * RESOLVED: staff handled an unassigned bill (gave time / returned it) and wrote why.
 */
enum CashNoteStatus: string
{
    case CREDITED = 'CREDITED';
    case UNASSIGNED = 'UNASSIGNED';
    case RESOLVED = 'RESOLVED';
}
