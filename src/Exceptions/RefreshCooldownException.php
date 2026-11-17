<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Exceptions;

use BeeCoded\EFacturaSdk\Exceptions\AuthenticationException;

/**
 * The company's grant is paused, so the call was refused without asking ANAF: nothing
 * was sent. TokenService pauses a grant for 15 minutes after the token endpoint refused
 * its refresh (400/401) or a request was refused with 401, and for 60 s after the token
 * endpoint failed otherwise;
 * a re-authorisation lifts it at once. An AuthenticationException (401) like any other
 * grant failure; uploads wait it out without spending a try.
 */
class RefreshCooldownException extends AuthenticationException {}
