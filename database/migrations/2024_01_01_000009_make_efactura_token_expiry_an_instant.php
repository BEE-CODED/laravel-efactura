<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL only: efactura_tokens.expires_at becomes `timestamp with time zone`.
 *
 * A naive `timestamp` compared with a bound string is read in the session time zone, so a
 * session zone other than the app's moved every token's expiry. The stored naive values are
 * app-timezone wall time (Eloquent formats dates in the app time zone), hence AT TIME ZONE
 * the app timezone. Guarded by the column's current type, so it is a no-op when already
 * converted (or converted by the host) and on every other database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convert('timestamp', 'timestamp(0) with time zone');
    }

    public function down(): void
    {
        $this->convert('timestamptz', 'timestamp(0) without time zone');
    }

    private function convert(string $from, string $to): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || Schema::getColumnType('efactura_tokens', 'expires_at') !== $from) {
            return;
        }

        DB::statement(sprintf(
            'alter table %s alter column expires_at type %s using expires_at at time zone %s',
            DB::getQueryGrammar()->wrapTable('efactura_tokens'),
            $to,
            DB::escape(config('app.timezone')),
        ));
    }
};
