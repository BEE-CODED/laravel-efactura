<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable e-Factura
    |--------------------------------------------------------------------------
    |
    | Master switch to enable or disable all e-Factura functionality.
    |
    */
    'enabled' => env('EFACTURA_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific features of the e-Factura integration.
    |
    */
    'features' => [
        'upload_invoices' => env('EFACTURA_UPLOAD_ENABLED', true),
        'download_received' => env('EFACTURA_DOWNLOAD_RECEIVED', false),
        'sync_messages' => env('EFACTURA_SYNC_MESSAGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Storage
    |--------------------------------------------------------------------------
    |
    | Configure where XML and ZIP files are stored.
    |
    */
    'storage' => [
        'disk' => env('EFACTURA_STORAGE_DISK', 'local'),
        'path' => env('EFACTURA_STORAGE_PATH', 'efactura'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Schedules
    |--------------------------------------------------------------------------
    |
    | Cron expressions for scheduled jobs. Set to null to disable a job.
    |
    */
    'schedule' => [
        'upload_invoices' => env('EFACTURA_SCHEDULE_UPLOAD', '*/5 * * * *'),
        'check_statuses' => env('EFACTURA_SCHEDULE_STATUS', '*/10 * * * *'),
        'download_responses' => env('EFACTURA_SCHEDULE_RESPONSES', '*/15 * * * *'),
        'download_received' => env('EFACTURA_SCHEDULE_RECEIVED', '0 */4 * * *'),
        'sync_messages' => env('EFACTURA_SCHEDULE_SYNC', '0 * * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth Routes
    |--------------------------------------------------------------------------
    |
    | Configure the OAuth callback routes provided by this package.
    |
    */
    'routes' => [
        'enabled' => env('EFACTURA_ROUTES_ENABLED', true),
        'prefix' => env('EFACTURA_ROUTES_PREFIX', 'efactura'),
        'middleware' => ['web'],
        'success_redirect' => env('EFACTURA_SUCCESS_REDIRECT', '/'),
        'error_redirect' => env('EFACTURA_ERROR_REDIRECT', '/'),
    ],
];
