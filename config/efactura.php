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
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Specify which queue the e-Factura jobs should be dispatched to.
    | Set to null to use the default queue.
    |
    */
    'queue' => env('EFACTURA_QUEUE', null),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Handling
    |--------------------------------------------------------------------------
    |
    | Configure how rate-limited uploads are handled. The SDK's RateLimiter
    | provides client-side pre-flight checks against ANAF quotas.
    |
    | retry_window_hours: How long a single upload job can keep retrying
    |                     before failing permanently (default: 24 hours).
    | retry_batch_size:   How many failed rate-limited uploads to reset
    |                     per RetryRateLimitedUploads run (default: 250).
    | retry_max_age_days: Don't retry uploads older than this (default: 7 days).
    |
    */
    'rate_limit' => [
        'retry_window_hours' => env('EFACTURA_RATE_LIMIT_RETRY_HOURS', 24),
        'retry_batch_size' => env('EFACTURA_RATE_LIMIT_RETRY_BATCH', 250),
        'retry_max_age_days' => env('EFACTURA_RATE_LIMIT_RETRY_MAX_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Periodic Job Hardening
    |--------------------------------------------------------------------------
    |
    | Protects the periodic batch jobs (CheckUploadStatuses, DownloadResponses,
    | ProcessPendingUploads) from a backlog that accumulates while the queue
    | worker is down and then drains all at once when it recovers.
    |
    | max_staleness_seconds: A batch job that has waited in the queue longer
    |                        than this self-discards instead of running (the
    |                        next scheduled run will re-scan). Set to 0 to
    |                        disable. Default 120 (2x the 1-minute cadence).
    | unique_for_seconds:    Lock TTL for ShouldBeUniqueUntilProcessing — the
    |                        ceiling after which a job stuck unprocessed in the
    |                        queue stops blocking a fresh dispatch. Default 3600.
    |
    */
    'jobs' => [
        'max_staleness_seconds' => env('EFACTURA_JOB_MAX_STALENESS', 120),
        'unique_for_seconds' => env('EFACTURA_JOB_UNIQUE_FOR', 3600),
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
