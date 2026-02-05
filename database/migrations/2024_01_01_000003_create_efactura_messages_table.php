<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use BeeCoded\EFactura\Models\EfacturaToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efactura_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EfacturaToken::class)->constrained()->cascadeOnDelete();
            $table->string('message_id')->unique();
            $table->string('type');
            $table->string('download_id')->nullable();
            $table->string('cif_emitent')->nullable();
            $table->string('cif_beneficiar')->nullable();
            $table->json('details')->nullable();
            $table->string('xml_path')->nullable();
            $table->boolean('is_downloaded')->default(false);
            $table->timestamp('message_date')->nullable();
            $table->timestamps();

            $table->index(['efactura_token_id', 'type']);
            $table->index('is_downloaded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efactura_messages');
    }
};
