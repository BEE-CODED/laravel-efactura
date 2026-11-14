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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * efactura_messages is unique per (efactura_token_id, message_id) instead of message_id, so a
 * message listed to two connected companies is stored once for each of them.
 *
 * RE-ENTRANT; cannot abort on data: every existing message_id is already unique. DDL
 * auto-commits on MySQL (each index change is its own ALTER), so each step is guarded on its
 * own state. down() fails once two tokens hold the same message_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasIndex('efactura_messages', ['efactura_token_id', 'message_id'], 'unique')) {
            Schema::table('efactura_messages', function (Blueprint $table) {
                $table->unique(['efactura_token_id', 'message_id']);
            });
        }

        if (Schema::hasIndex('efactura_messages', ['message_id'], 'unique')) {
            Schema::table('efactura_messages', function (Blueprint $table) {
                $table->dropUnique(['message_id']);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasIndex('efactura_messages', ['message_id'], 'unique')) {
            Schema::table('efactura_messages', function (Blueprint $table) {
                $table->unique('message_id');
            });
        }

        if (Schema::hasIndex('efactura_messages', ['efactura_token_id', 'message_id'], 'unique')) {
            Schema::table('efactura_messages', function (Blueprint $table) {
                $table->dropUnique(['efactura_token_id', 'message_id']);
            });
        }
    }
};
