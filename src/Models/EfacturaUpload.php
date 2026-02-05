<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Models;

use BeeCoded\EFactura\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $efactura_token_id
 * @property string $uploadable_type
 * @property int $uploadable_id
 * @property UploadStatus $status
 * @property string|null $upload_index
 * @property string|null $download_id
 * @property string|null $xml_path
 * @property string|null $response_path
 * @property array<string, mixed>|null $errors
 * @property string $standard
 * @property bool $is_extern
 * @property bool $is_self_billed
 * @property bool $is_b2c
 * @property Carbon|null $uploaded_at
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read EfacturaToken|null $token
 * @property-read Model $uploadable
 *
 * @method static Builder|static pending()
 * @method static Builder|static processing()
 * @method static Builder|static needsStatusCheck()
 * @method static Builder|static needsResponseDownload()
 * @method static Builder|static forToken(EfacturaToken $token)
 */
class EfacturaUpload extends Model
{
    protected $fillable = [
        'efactura_token_id',
        'uploadable_type',
        'uploadable_id',
        'status',
        'upload_index',
        'download_id',
        'xml_path',
        'response_path',
        'errors',
        'standard',
        'is_extern',
        'is_self_billed',
        'is_b2c',
        'uploaded_at',
        'processed_at',
    ];

    protected $casts = [
        'status' => UploadStatus::class,
        'errors' => 'array',
        'is_extern' => 'boolean',
        'is_self_billed' => 'boolean',
        'is_b2c' => 'boolean',
        'uploaded_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(EfacturaToken::class, 'efactura_token_id');
    }

    public function uploadable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === UploadStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, [UploadStatus::Uploading, UploadStatus::Processing]);
    }

    public function isCompleted(): bool
    {
        return $this->status === UploadStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === UploadStatus::Failed;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', UploadStatus::Pending);
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->whereIn('status', [UploadStatus::Uploading, UploadStatus::Processing]);
    }

    public function scopeNeedsStatusCheck(Builder $query): Builder
    {
        return $query->where('status', UploadStatus::Processing)
            ->whereNotNull('upload_index');
    }

    public function scopeNeedsResponseDownload(Builder $query): Builder
    {
        return $query->where('status', UploadStatus::Completed)
            ->whereNotNull('download_id')
            ->whereNull('response_path');
    }

    public function scopeForToken(Builder $query, EfacturaToken $token): Builder
    {
        return $query->where('efactura_token_id', $token->id);
    }
}
