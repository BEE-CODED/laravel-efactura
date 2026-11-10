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
 * ANAF refused to refresh this grant within the cooldown, so the call was refused
 * without asking ANAF: nothing was sent. An AuthenticationException (401) like any
 * other refresh failure; uploads wait it out without spending a transient attempt.
 */
class RefreshCooldownException extends AuthenticationException {}
