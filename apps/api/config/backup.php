<?php

return [
    // base64 of 32 random bytes. Generate once: php -r "echo base64_encode(random_bytes(32));"
    // Keep a copy OUTSIDE the server (password manager): without it backups cannot be restored.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
    // Directory on the private local disk.
    'path' => 'backups',
    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 14),
    'keep_weekly' => (int) env('BACKUP_KEEP_WEEKLY', 8),
    'keep_photos' => (int) env('BACKUP_KEEP_PHOTOS', 4),
];
