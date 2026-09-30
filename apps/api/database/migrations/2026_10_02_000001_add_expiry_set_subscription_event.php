<?php

use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;

/** Super Admin can set the expiry date directly (spec §3 "set subscription expiration"). */
return new class extends Migration
{
    private const BASE = ['CREATED', 'ACTIVATED', 'EXTENDED', 'SUSPENDED', 'RESUMED', 'DEACTIVATED', 'EXPIRED', 'LIMIT_CHANGED'];

    public function up(): void
    {
        Constraints::dropCheck('subscription_events', 'sub_events_type_chk');
        Constraints::check('subscription_events', 'sub_events_type_chk', Constraints::in('type', [...self::BASE, 'EXPIRY_SET']));
    }

    public function down(): void
    {
        Constraints::dropCheck('subscription_events', 'sub_events_type_chk');
        Constraints::check('subscription_events', 'sub_events_type_chk', Constraints::in('type', self::BASE));
    }
};
