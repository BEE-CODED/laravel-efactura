<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Facades;

use BeeCoded\EFactura\EfacturaManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \BeeCoded\EFactura\Models\EfacturaToken|null getToken(string $cui)
 * @method static string getAuthorizationUrl(string $cui)
 * @method static \BeeCoded\EFacturaSdk\Services\ApiClients\EFacturaClient client(string $cui)
 * @method static \BeeCoded\EFactura\Models\EfacturaUpload queueUpload(\BeeCoded\EFactura\Contracts\EFacturaUploadableInterface $model, ?array $options = null)
 * @method static \BeeCoded\EFactura\Models\EfacturaUpload queueB2CUpload(\BeeCoded\EFactura\Contracts\EFacturaUploadableInterface $model, ?array $options = null)
 * @method static void processUpload(\BeeCoded\EFactura\Models\EfacturaUpload $upload)
 * @method static \BeeCoded\EFactura\Services\TokenService tokenService()
 * @method static \BeeCoded\EFactura\Services\UploadService uploadService()
 * @method static \BeeCoded\EFactura\Services\DownloadService downloadService()
 * @method static \BeeCoded\EFactura\Services\MessageSyncService messageSyncService()
 *
 * @see \BeeCoded\EFactura\EfacturaManager
 */
class EFactura extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EfacturaManager::class;
    }
}
