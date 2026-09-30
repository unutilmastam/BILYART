<?php

/*
| Single source of role → permission mapping (docs/SECURITY.md §2).
| SUPER_ADMIN gets only platform.* — it never sees tenant photos automatically (spec §41).
*/

$operator = [
    'tables.view', 'sessions.view', 'sessions.create', 'sessions.stop', 'sessions.mark_payment',
];

$manager = array_merge($operator, [
    'photos.view', 'photos.delete', 'reports.view',
    'tables.manage', 'pricing.manage', 'working_hours.manage', 'devices.manage', 'users.manage',
]);

$owner = array_merge($manager, [
    'branches.manage', 'telegram.manage', 'tenant.settings', 'tenant.export',
]);

return [
    'roles' => [
        'SUPER_ADMIN' => [
            'platform.tenants', 'platform.payments', 'platform.audit', 'platform.settings',
            'platform.firmware', 'platform.health',
        ],
        'CLIENT_OWNER' => $owner,
        'CLIENT_MANAGER' => $manager,
        'CLIENT_OPERATOR' => $operator,
    ],
];
