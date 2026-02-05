<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Console\Commands;

use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Console\Command;

class EfacturaAuthCommand extends Command
{
    protected $signature = 'efactura:auth {cui : The CUI to authorize}';

    protected $description = 'Display OAuth authorization URL for a CUI';

    public function handle(TokenService $tokenService): int
    {
        $cui = VatNumberValidator::stripPrefix($this->argument('cui'));

        // Check if token already exists
        $existingToken = EfacturaToken::forCui($cui)->first();

        if ($existingToken) {
            $this->warn("A token already exists for CUI: {$cui}");
            $this->line('Status: '.($existingToken->is_active ? 'Active' : 'Inactive'));
            $this->line('Expires: '.($existingToken->expires_at?->format('Y-m-d H:i:s') ?? 'Unknown'));

            if (!$this->confirm('Do you want to generate a new authorization URL anyway?')) {
                return self::SUCCESS;
            }
        }

        $url = $tokenService->getAuthorizationUrl($cui);

        $this->newLine();
        $this->info('Authorization URL for CUI: '.$cui);
        $this->newLine();
        $this->line($url);
        $this->newLine();
        $this->comment('Open this URL in a browser to authorize the application.');

        return self::SUCCESS;
    }
}
