<?php

use BeeCoded\EFactura\Models\EfacturaToken;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Load the data-only migration that encrypts pre-existing plaintext rows.
 *
 * Required fresh each time: migrations are anonymous class instances, and each
 * test needs its own so state cannot leak between up() and down() calls.
 *
 * Resolved by glob rather than by number: the numeric prefix is deliberately
 * load-bearing (it must sort first — see MigrationOrderTest), so this test must
 * not be a thing that has to be hand-edited whenever that ordering is revisited.
 */
function encryptionMigration(): object
{
    $paths = glob(__DIR__.'/../../database/migrations/*_encrypt_efactura_token_credentials.php') ?: [];

    expect($paths)->toHaveCount(1, 'Expected exactly one token-encryption migration');

    return require $paths[0];
}

/**
 * Run $callback with APP_KEY swapped for an unrelated one, and no
 * APP_PREVIOUS_KEYS to fall back on.
 *
 * Simulates the real hazard: a rollback attempted in an environment whose key is
 * not the key the rows were encrypted under (a rotated key, or a production dump
 * restored somewhere else). The encrypter is a container singleton built from
 * config('app'), so both it and the facade cache have to be dropped.
 *
 * The key MUST be restored afterwards, hence the finally. Testbench's
 * loadMigrationsFrom() registers a `migrate:rollback` to run when the application
 * is destroyed, so a leaked rotated key would make that teardown rollback hit the
 * very abort under test — failing the test from outside its own body, with the
 * exception surfacing after every assertion had already passed.
 */
function withRotatedAppKey(callable $callback): void
{
    $originalKey = config('app.key');

    $swapEncrypter = function (string $key): void {
        config(['app.key' => $key, 'app.previous_keys' => []]);

        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
    };

    $swapEncrypter('base64:'.base64_encode(random_bytes(32)));

    try {
        $callback();
    } finally {
        $swapEncrypter($originalKey);
    }
}

/**
 * Insert a row the way a pre-v3 release did: straight to the column, no cast,
 * no encryption. DB::table() deliberately bypasses the model.
 */
function insertPlaintextToken(string $cui, string $access, string $refresh): int
{
    return DB::table('efactura_tokens')->insertGetId([
        'cui' => $cui,
        'access_token' => $access,
        'refresh_token' => $refresh,
        'expires_at' => now()->addHour(),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Read what is physically on disk, not what the model hands back.
 */
function rawTokenRow(int $id): object
{
    $row = DB::table('efactura_tokens')->where('id', $id)->first();

    expect($row)->not->toBeNull();

    return $row;
}

describe('EfacturaToken encryption at rest', function () {
    it('writes ciphertext to the database while reading back plaintext', function () {
        $token = EfacturaToken::create([
            'cui' => '12345678',
            'access_token' => 'plain_access_token',
            'refresh_token' => 'plain_refresh_token',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $raw = rawTokenRow($token->id);

        // What is stored must not be the credential.
        expect($raw->access_token)->not->toBe('plain_access_token');
        expect($raw->refresh_token)->not->toBe('plain_refresh_token');

        // ...and must not merely be obfuscated: it decrypts under APP_KEY.
        expect(Crypt::decryptString($raw->access_token))->toBe('plain_access_token');
        expect(Crypt::decryptString($raw->refresh_token))->toBe('plain_refresh_token');

        // The model surface is unchanged — callers never see ciphertext.
        expect($token->fresh()->access_token)->toBe('plain_access_token');
        expect($token->fresh()->refresh_token)->toBe('plain_refresh_token');
    });

    it('keeps both credentials out of serialized output', function () {
        $token = EfacturaToken::create([
            'cui' => '12345678',
            'access_token' => 'plain_access_token',
            'refresh_token' => 'plain_refresh_token',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        expect($token->toArray())->not->toHaveKey('access_token')
            ->and($token->toArray())->not->toHaveKey('refresh_token');
    });

    it('fails loudly when reading a plaintext row that was never migrated', function () {
        // This is the deliberate design: no decrypt-fallback. A row that predates
        // the migration must not read back as if all were well, because that would
        // hide a migration that never ran.
        $id = insertPlaintextToken('12345678', 'unmigrated_access', 'unmigrated_refresh');

        $token = EfacturaToken::findOrFail($id);

        expect(fn () => $token->access_token)->toThrow(DecryptException::class);
    });
});

describe('encrypt token credentials migration', function () {
    it('encrypts a pre-existing plaintext row', function () {
        $id = insertPlaintextToken('12345678', 'legacy_access', 'legacy_refresh');

        encryptionMigration()->up();

        $raw = rawTokenRow($id);

        expect($raw->access_token)->not->toBe('legacy_access');
        expect($raw->refresh_token)->not->toBe('legacy_refresh');
        expect(Crypt::decryptString($raw->access_token))->toBe('legacy_access');
        expect(Crypt::decryptString($raw->refresh_token))->toBe('legacy_refresh');

        // The model can now read the row it previously could not.
        $token = EfacturaToken::findOrFail($id);
        expect($token->access_token)->toBe('legacy_access');
        expect($token->refresh_token)->toBe('legacy_refresh');
    });

    it('is idempotent — re-running cannot double-encrypt', function () {
        $id = insertPlaintextToken('12345678', 'legacy_access', 'legacy_refresh');

        encryptionMigration()->up();
        $afterFirstRun = rawTokenRow($id)->access_token;

        encryptionMigration()->up();
        $afterSecondRun = rawTokenRow($id)->access_token;

        // Ciphertext is randomised per encryption (fresh IV), so the two runs cannot
        // be compared byte-for-byte. What must hold is that ONE decrypt returns the
        // credential — a double-encrypted value would decrypt to a Crypt payload.
        expect(Crypt::decryptString($afterSecondRun))->toBe('legacy_access');
        expect(Crypt::decryptString(rawTokenRow($id)->refresh_token))->toBe('legacy_refresh');

        // Guard the premise: the row really was already encrypted before run two,
        // so run two genuinely exercised the skip path.
        expect(Crypt::decryptString($afterFirstRun))->toBe('legacy_access');

        expect(EfacturaToken::findOrFail($id)->access_token)->toBe('legacy_access');
    });

    it('leaves rows written by the model untouched', function () {
        $token = EfacturaToken::create([
            'cui' => '12345678',
            'access_token' => 'already_encrypted_access',
            'refresh_token' => 'already_encrypted_refresh',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        encryptionMigration()->up();

        expect(EfacturaToken::findOrFail($token->id)->access_token)->toBe('already_encrypted_access');
        expect(EfacturaToken::findOrFail($token->id)->refresh_token)->toBe('already_encrypted_refresh');
    });

    it('encrypts a mixed table of plaintext and already-encrypted rows', function () {
        $plaintextId = insertPlaintextToken('11111111', 'legacy_access', 'legacy_refresh');
        $encrypted = EfacturaToken::create([
            'cui' => '22222222',
            'access_token' => 'modern_access',
            'refresh_token' => 'modern_refresh',
            'expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        encryptionMigration()->up();

        expect(EfacturaToken::findOrFail($plaintextId)->access_token)->toBe('legacy_access');
        expect(EfacturaToken::findOrFail($encrypted->id)->access_token)->toBe('modern_access');
    });

    it('restores plaintext on down(), so a rollback leaves no unreadable ciphertext', function () {
        $id = insertPlaintextToken('12345678', 'legacy_access', 'legacy_refresh');

        $migration = encryptionMigration();
        $migration->up();
        $migration->down();

        $raw = rawTokenRow($id);

        // A rolled-back v2 model has no cast, so it reads the column verbatim.
        expect($raw->access_token)->toBe('legacy_access');
        expect($raw->refresh_token)->toBe('legacy_refresh');
    });

    it('is idempotent on down() too — plaintext rows survive a repeated rollback', function () {
        $id = insertPlaintextToken('12345678', 'legacy_access', 'legacy_refresh');

        $migration = encryptionMigration();
        $migration->up();
        $migration->down();
        $migration->down();

        expect(rawTokenRow($id)->access_token)->toBe('legacy_access');
    });

    it('aborts the rollback instead of silently keeping ciphertext under a rotated APP_KEY', function () {
        // The hazard: down() decides "already plaintext, skip" by catching
        // DecryptException. A MAC failure under the WRONG key throws that same
        // exception — so a rollback run against a rotated key would skip every
        // row, report success, and leave ciphertext behind. A downgraded v2 model
        // has no cast and would then send that ciphertext to ANAF as a Bearer
        // token. The rollback must refuse rather than lie.
        $id = insertPlaintextToken('12345678', 'legacy_access', 'legacy_refresh');

        encryptionMigration()->up();

        withRotatedAppKey(function () use ($id) {
            expect(fn () => encryptionMigration()->down())->toThrow(RuntimeException::class);

            // The row must still hold ciphertext: nothing was silently "restored",
            // and nothing was destroyed either — it stays recoverable under the
            // right key, which is what makes aborting the correct call.
            expect(rawTokenRow($id)->access_token)->not->toBe('legacy_access');
        });

        // Premise guard: the abort must be caused by the KEY, not by something
        // that would have made down() fail regardless. Under the original key the
        // same rollback succeeds and returns the credential.
        encryptionMigration()->down();

        expect(rawTokenRow($id)->access_token)->toBe('legacy_access');
    });

    it('round-trips a realistic multi-kilobyte JWT within the text column', function () {
        // ANAF hands out JWTs, not short opaque strings. Encryption inflates a value
        // ~1.8x; this proves a realistic credential still fits and survives the trip.
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.'.str_repeat('A', 2048).'.'.str_repeat('B', 342);
        $id = insertPlaintextToken('12345678', $jwt, $jwt);

        encryptionMigration()->up();

        expect(strlen(rawTokenRow($id)->access_token))->toBeLessThan(65535);
        expect(EfacturaToken::findOrFail($id)->access_token)->toBe($jwt);
    });
});

describe('rollback atomicity across a mixed-key table', function () {
    /**
     * down() converts row by row. Laravel only wraps a migration in a transaction
     * when the schema grammar supports it — which is Postgres and SQL Server only
     * (Grammar::$transactions is false; PostgresGrammar and SqlServerGrammar
     * override it). On MySQL and SQLite, the realistic production pair here, each
     * write lands on its own.
     *
     * So a table holding rows under two different keys — the exact mixed state the
     * model's own docblock warns about — decrypts the readable rows to plaintext,
     * COMMITS them, then aborts on the unreadable one. The result is live ANAF
     * credentials sitting in plaintext at rest, with the migration still recorded
     * as run and the rollback wedged.
     *
     * The existing abort test uses a single row, so it always fails on row 1 and
     * never opens this window. This one needs two.
     */
    it('leaves no row decrypted when a later row cannot be decrypted', function () {
        $readable = insertPlaintextToken('11111111', 'readable_access', 'readable_refresh');
        $foreign = insertPlaintextToken('22222222', 'foreign_access', 'foreign_refresh');

        // Both rows encrypted under the current key.
        encryptionMigration()->up();

        // Re-seal only the SECOND row under a different key, as an APP_KEY
        // rotation between two migration runs would.
        withRotatedAppKey(function () use ($foreign) {
            DB::table('efactura_tokens')->where('id', $foreign)->update([
                'access_token' => Crypt::encryptString('foreign_access'),
                'refresh_token' => Crypt::encryptString('foreign_refresh'),
            ]);
        });

        $sealedBefore = rawTokenRow($readable)->access_token;

        // chunkById orders by id, so the readable row is converted first and the
        // foreign row blows up after it.
        expect(fn () => encryptionMigration()->down())->toThrow(RuntimeException::class);

        // The readable row must NOT have been left as plaintext on disk.
        expect(rawTokenRow($readable)->access_token)
            ->not->toBe('readable_access')
            ->and(rawTokenRow($readable)->access_token)->toBe($sealedBefore);

        // Drop the foreign-keyed row before teardown. Testbench's
        // loadMigrationsFrom() registers a migrate:rollback on teardown, which
        // would re-run down(), hit this undecryptable row and abort — failing the
        // test after it had already passed.
        DB::table('efactura_tokens')->where('id', $foreign)->delete();
    });
});
