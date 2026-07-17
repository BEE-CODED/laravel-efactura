<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts the OAuth credentials already sitting in efactura_tokens.
 *
 * As of v3.0.0 EfacturaToken casts `access_token` and `refresh_token` to
 * `encrypted`. The cast only governs values written THROUGH the model, so every
 * row stored by a pre-3.0 release is still plaintext — and the cast has no
 * fallback for that, by design. Without this migration those rows throw
 * DecryptException on first read and e-Factura stops working for that company.
 * That is the intended failure mode: it is discovered immediately rather than
 * leaving credentials silently readable in the database.
 *
 * No schema change is needed. Both columns are already `text` (65,535 bytes in
 * MySQL); Crypt inflates a value roughly 1.8x asymptotically (more for short
 * strings, which carry a fixed ~200-byte IV/MAC/JSON envelope), leaving a
 * plaintext ceiling of ~36,700 bytes. ANAF's JWTs are one to two kilobytes.
 *
 * RUNS FIRST, DELIBERATELY. This is numbered ahead of the other v3 migrations
 * because it is the one that RESTORES service: the v3 code is already deployed and
 * already expects encrypted tokens by the time it runs. The others can abort — the
 * unique-index migration is designed to, on pre-3.0 duplicates — and an abort ahead
 * of this one would strand it, leaving every company throwing DecryptException. It
 * touches a different table than they do and has no interdependency with either, so
 * the order is free; only this order is safe. See MigrationOrderTest.
 *
 * IDEMPOTENT: a value that is structurally a Crypt payload is already ciphertext and
 * is skipped, so re-running cannot double-encrypt. That is what makes this safe to
 * re-run after a partial failure, and safe on a table that mixes migrated and
 * unmigrated rows (e.g. a company that re-authorised after the deploy).
 *
 * The distinction is reliable in practice: a Crypt payload must be base64-encoded
 * JSON carrying iv/value/mac. No ANAF credential satisfies that by accident.
 *
 * That structural test is used INSTEAD OF a trial decrypt, and the difference
 * matters: "is this ciphertext?" is a question about the value, while "does it
 * decrypt?" also depends on the key. A trial decrypt conflates them, so a wrong-key
 * MAC failure — a rotated APP_KEY, or a production dump restored elsewhere — is
 * indistinguishable from plaintext. up() would double-encrypt such a row and down()
 * would silently skip it, reporting a successful rollback while leaving ciphertext
 * for a cast-less v2 model to send to ANAF as a Bearer token. down() therefore
 * ABORTS on an envelope it cannot open rather than guessing.
 *
 * Deliberately uses the query builder rather than EfacturaToken: the model's
 * `encrypted` cast would encrypt on write and decrypt on read, so it could neither
 * see the plaintext it must convert nor write the ciphertext this produces.
 */
return new class extends Migration
{
    /**
     * Rows held per chunk. This table carries one row per connected company —
     * tens of rows, not millions — so this exists to bound memory, not to scale.
     */
    private const CHUNK_SIZE = 100;

    public function up(): void
    {
        $this->atomically(fn () => $this->convert(fn (string $value): ?string => $this->encryptIfPlaintext($value)));
    }

    /**
     * Restore plaintext.
     *
     * Rolling this back means rolling back to pre-3.0 code, whose model has no
     * `encrypted` cast and would read the column verbatim. Leaving ciphertext in
     * place would hand that code an unusable credential, so a rollback has to be a
     * real round trip.
     */
    public function down(): void
    {
        $this->atomically(fn () => $this->convert(fn (string $value): ?string => $this->decryptIfEncrypted($value)));
    }

    /**
     * Run $work atomically, whatever the driver.
     *
     * Laravel only wraps a migration in a transaction when the schema grammar
     * says it can (Grammar::$transactions is false; only Postgres and SQL Server
     * override it), so on MySQL and SQLite — the realistic production pair — these
     * per-row writes would otherwise each stand alone. A table holding rows sealed
     * under two different keys would then decrypt the readable ones to plaintext,
     * COMMIT them, and abort on the first unreadable one: live ANAF credentials
     * left in plaintext at rest, migration still recorded, rollback wedged.
     *
     * This migration performs no DDL, so an explicit transaction is safe even on
     * MySQL, where DDL would otherwise force an implicit commit. The table holds
     * one row per connected company, so the transaction stays short.
     */
    private function atomically(callable $work): void
    {
        DB::transaction($work);
    }

    /**
     * Walk every row, applying $transform to both credential columns.
     *
     * chunkById() is ordered by the primary key, which this migration never
     * touches, so the pagination stays stable while rows are rewritten underneath.
     *
     * @param  callable(string): ?string  $transform  Returns null when the value needs no change.
     */
    private function convert(callable $transform): void
    {
        DB::table('efactura_tokens')
            ->select('id', 'access_token', 'refresh_token')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($transform) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach (['access_token', 'refresh_token'] as $column) {
                        $value = $row->{$column};

                        // The columns are NOT NULL, but published migrations can be
                        // edited by the installing application — don't assume.
                        if (!is_string($value)) {
                            continue;
                        }

                        $converted = $transform($value);

                        if ($converted !== null) {
                            $changes[$column] = $converted;
                        }
                    }

                    if ($changes === []) {
                        continue;
                    }

                    // Touching credentials only: updated_at is left alone so this
                    // does not masquerade as a business-level change to the token.
                    DB::table('efactura_tokens')->where('id', $row->id)->update($changes);
                }
            });
    }

    /**
     * @return string|null Ciphertext, or null when the value is already encrypted.
     */
    private function encryptIfPlaintext(string $value): ?string
    {
        // Structure alone decides this — no decrypt attempt. "Already encrypted"
        // is a fact about the value's shape, not about whether THIS key opens it.
        // Deciding it by catching DecryptException would re-encrypt a row that is
        // merely encrypted under a different key, silently double-wrapping it.
        if ($this->looksLikeCryptEnvelope($value)) {
            return null;
        }

        return Crypt::encryptString($value);
    }

    /**
     * @return string|null Plaintext, or null when the value is already plaintext.
     *
     * @throws RuntimeException When a value that IS ciphertext cannot be decrypted.
     */
    private function decryptIfEncrypted(string $value): ?string
    {
        if (!$this->looksLikeCryptEnvelope($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            // The value is structurally a Crypt payload, so "leave it, it must
            // already be plaintext" is not available as an explanation: the only
            // reason it will not open is that APP_KEY is not the key it was sealed
            // with. Skipping would report a successful rollback while leaving
            // ciphertext in a column a downgraded v2 model reads verbatim — and v2
            // would then send that ciphertext to ANAF as a Bearer token.
            throw new RuntimeException(
                'Cannot roll back the e-Factura token encryption: a stored credential is '
                .'encrypted, but APP_KEY cannot decrypt it.'.PHP_EOL.PHP_EOL
                .'This means the current APP_KEY is not the key these tokens were encrypted '
                .'with — typically a rotated key, or a production database restored into an '
                .'environment with a different key.'.PHP_EOL.PHP_EOL
                .'The rollback has been aborted rather than reporting success and leaving '
                .'unreadable ciphertext behind: a downgraded v2 release reads this column '
                .'verbatim and would send the ciphertext to ANAF as a Bearer token.'.PHP_EOL.PHP_EOL
                .'Fix by one of:'.PHP_EOL
                .'  - restore the original APP_KEY, or set APP_PREVIOUS_KEYS to it, then re-run;'.PHP_EOL
                .'  - or, if the original key is gone, the tokens are unrecoverable: delete the '
                .'affected efactura_tokens rows and have each company redo the OAuth flow '
                .'(php artisan efactura:auth {cui}).',
                previous: $e,
            );
        }
    }

    /**
     * Is this value structurally a Laravel Crypt payload?
     *
     * Mirrors what Encrypter::encrypt() produces and what its own validPayload()
     * checks: base64 of a JSON object carrying iv/value/mac. This is exactly the
     * discriminator this migration's header describes, and no ANAF credential
     * satisfies it by accident.
     *
     * Deliberately structural and key-independent — that is the entire point. It
     * separates "is this ciphertext?" (a question about the value) from "can we
     * open it?" (a question about the key). Conflating the two is what let a
     * wrong-key MAC failure masquerade as "already plaintext".
     *
     * Hand-rolled rather than calling Encrypter::appearsEncrypted(): that helper
     * is a late addition to the 11.x line, and this package supports
     * illuminate/* ^11.0 — where it does not exist.
     */
    private function looksLikeCryptEnvelope(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
};
