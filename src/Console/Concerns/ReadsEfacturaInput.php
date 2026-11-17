<?php

declare(strict_types=1);

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Console\Concerns;

use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * The --cui and id options of the efactura:* commands. A value that names nothing prints
 * why and gives null, and the command then exits 1.
 *
 * @mixin Command
 */
trait ReadsEfacturaInput
{
    /**
     * An option's value: null when it is absent, '' when it is given bare (--cui), so a bare
     * flag is refused instead of read as absent.
     */
    private function given(string $name): ?string
    {
        $value = $this->option($name);

        return $value === null ? ($this->input->hasParameterOption("--{$name}") ? '' : null) : (string) $value;
    }

    /**
     * The active tokens the command works for, paused ones included so it can report them:
     * all of them without --cui, except a row stored under a non-canonical CUI, which is
     * skipped with a line; else the one company --cui names.
     *
     * @return Collection<int, EfacturaToken>|null Null when --cui names no active company.
     */
    private function tokensFor(): ?Collection
    {
        $raw = $this->given('cui');

        if ($raw === null) {
            // A legacy row no per-CUI path reaches (TokenService::usableTokens() skips it too).
            return EfacturaToken::active()->get()->reject(function (EfacturaToken $token): bool {
                if (VatNumberValidator::canonical($token->cui) === $token->cui) {
                    return false;
                }

                $this->line("{$token->cui}: skipped (non-canonical CUI).");

                return true;
            })->values();
        }

        $cui = VatNumberValidator::canonical($raw);

        if ($cui === null) {
            $this->error("Invalid CUI: '{$raw}'.");

            return null;
        }

        $tokens = EfacturaToken::active()->forCui($cui)->get();

        if ($tokens->isEmpty()) {
            $this->error("No active token found for CUI: {$cui}");

            return null;
        }

        return $tokens;
    }

    /**
     * The upload an id option names, of the token's company when one is given.
     */
    private function uploadFor(string $id, ?EfacturaToken $token = null): ?EfacturaUpload
    {
        // PostgreSQL refuses a non-numeric bigint and MySQL cuts it to its leading digits.
        return ctype_digit($id) && strlen($id) <= 18
            ? EfacturaUpload::whereKey($id)->when($token, fn ($query, EfacturaToken $token) => $query->forToken($token))->first()
            : null;
    }
}
