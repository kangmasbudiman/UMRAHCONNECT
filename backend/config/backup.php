<?php

return [
    'path' => env('BACKUP_PATH', storage_path('app/backups')),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
];
