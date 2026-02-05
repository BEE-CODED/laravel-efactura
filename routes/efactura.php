<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use BeeCoded\EFactura\Http\Controllers\OAuthCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('auth/{cui}', [OAuthCallbackController::class, 'redirect'])
    ->name('efactura.auth');

Route::get('callback', [OAuthCallbackController::class, 'callback'])
    ->name('efactura.callback');
