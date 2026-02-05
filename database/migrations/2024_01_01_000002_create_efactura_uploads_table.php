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
        Schema::create('efactura_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EfacturaToken::class)->constrained()->cascadeOnDelete();
            $table->morphs('uploadable');
            $table->string('status');
            $table->string('upload_index')->nullable();
            $table->string('download_id')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('response_path')->nullable();
            $table->json('errors')->nullable();

            // Upload options
            $table->string('standard')->default('UBL');
            $table->boolean('is_extern')->default(false);
            $table->boolean('is_self_billed')->default(false);
            $table->boolean('is_b2c')->default(false);

            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('upload_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efactura_uploads');
    }
};
