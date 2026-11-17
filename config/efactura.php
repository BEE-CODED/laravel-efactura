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
    | Stops the package's jobs and artisan commands. Token storage (TokenService),
    | the OAuth routes (see routes.enabled), the services and the raw client are
    | unaffected.
    |
    */
    'enabled' => env('EFACTURA_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Pipeline Tables
    |--------------------------------------------------------------------------
    |
    | Set to false when you use only tokens, OAuth and the raw client and run
    | your own upload pipeline. The package then loads and publishes only the
    | token migrations, and its upload/message services, jobs and commands
    | throw PipelineTablesDisabledException.
    |
    */
    'pipeline_tables' => env('EFACTURA_PIPELINE_TABLES', true),

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
    | ANAF Company Lookup
    |--------------------------------------------------------------------------
    |
    | Synchronous retry policy for the public ANAF company-lookup endpoint
    | (ANAF: 1 request/second; the SDK counts over 2 seconds). On the SDK's own
    | limiter refusal (a RateLimitExceededException without fromProvider; ANAF's
    | 429 is re-thrown at once) the decorator retries up to `retry_attempts`
    | TOTAL attempts (1 initial + N-1 retries), sleeping the exception's
    | retryAfterSeconds (>= 1s) between attempts, then re-throws.
    |
    */
    'anaf_lookup' => [
        'retry_attempts' => env('EFACTURA_ANAF_LOOKUP_RETRY_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Storage
    |--------------------------------------------------------------------------
    |
    | Configure where XML and ZIP files are stored.
    |
    | Stored rows keep paths without the disk. Before changing storage.disk, copy
    | the files to the new disk under the same paths; otherwise response
    | verification for those uploads falls back to limits-only and their cleanup
    | deletes nothing. Changing storage.path affects only new files.
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
    | middleware: Applied to both routes. The default requires a logged-in user,
    |             but `auth` alone lets ANY logged-in user start an authorization
    |             for ANY CUI. Add your own check that the user may act for that
    |             company (for example a `can:` middleware on the `cui` route
    |             parameter; the stack also runs on the callback, where that gate
    |             receives a null CUI and must allow it, see the README's OAuth
    |             Flow), or disable these routes and run the flow from your own
    |             controller. The callback also needs the logged-in browser,
    |             including after the `efactura:auth` command.
    |
    */
    'routes' => [
        'enabled' => env('EFACTURA_ROUTES_ENABLED', true),
        'prefix' => env('EFACTURA_ROUTES_PREFIX', 'efactura'),
        'middleware' => ['web', 'auth'],
        'success_redirect' => env('EFACTURA_SUCCESS_REDIRECT', '/'),
        'error_redirect' => env('EFACTURA_ERROR_REDIRECT', '/'),
    ],
];
