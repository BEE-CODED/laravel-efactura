<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Traits;

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasEfacturaUpload
{
    /**
     * Get the e-factura upload record for this model.
     */
    public function efacturaUpload(): MorphOne
    {
        return $this->morphOne(EfacturaUpload::class, 'uploadable');
    }

    /**
     * Check if this model has been uploaded to e-Factura.
     *
     * Uses exists() query when relationship is not loaded to avoid N+1 in loops.
     */
    public function isUploadedToEfactura(): bool
    {
        if ($this->relationLoaded('efacturaUpload')) {
            return $this->efacturaUpload !== null;
        }

        return $this->efacturaUpload()->exists();
    }

    /**
     * Get the e-Factura upload status.
     *
     * Returns null if no upload exists. Eager load 'efacturaUpload' for batch operations.
     */
    public function getEfacturaStatus(): ?UploadStatus
    {
        if ($this->relationLoaded('efacturaUpload')) {
            return $this->efacturaUpload?->status;
        }

        return $this->efacturaUpload()->value('status');
    }

    /**
     * Check if e-Factura processing is complete (success or failure).
     */
    public function isEfacturaProcessed(): bool
    {
        $status = $this->getEfacturaStatus();

        return in_array($status, [
            UploadStatus::Completed,
            UploadStatus::Failed,
        ]);
    }

    /**
     * Get the e-Factura response file path (ZIP from ANAF).
     *
     * Eager load 'efacturaUpload' for batch operations.
     */
    public function getEfacturaResponsePath(): ?string
    {
        if ($this->relationLoaded('efacturaUpload')) {
            return $this->efacturaUpload?->response_path;
        }

        return $this->efacturaUpload()->value('response_path');
    }

    /**
     * Get the uploaded XML file path.
     *
     * Eager load 'efacturaUpload' for batch operations.
     */
    public function getEfacturaXmlPath(): ?string
    {
        if ($this->relationLoaded('efacturaUpload')) {
            return $this->efacturaUpload?->xml_path;
        }

        return $this->efacturaUpload()->value('xml_path');
    }

    /**
     * Get e-Factura errors if any.
     *
     * Eager load 'efacturaUpload' for batch operations.
     */
    public function getEfacturaErrors(): ?array
    {
        if ($this->relationLoaded('efacturaUpload')) {
            return $this->efacturaUpload?->errors;
        }

        return $this->efacturaUpload()->value('errors');
    }

    /**
     * Scope: Exclude already uploaded models.
     */
    public function scopeNotUploadedToEfactura(Builder $query): Builder
    {
        return $query->whereDoesntHave('efacturaUpload');
    }

    /**
     * Scope: Get models with specific e-Factura status.
     */
    public function scopeWithEfacturaStatus(Builder $query, UploadStatus $status): Builder
    {
        return $query->whereHas('efacturaUpload', fn ($q) => $q->where('status', $status));
    }
}
