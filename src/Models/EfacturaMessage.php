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

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $efactura_token_id
 * @property string $message_id
 * @property string $type
 * @property string|null $download_id
 * @property string|null $cif_emitent
 * @property string|null $cif_beneficiar
 * @property array<string, mixed>|null $details
 * @property string|null $xml_path
 * @property bool $is_downloaded
 * @property Carbon|null $message_date
 * @property int $download_attempts
 * @property Carbon|null $next_download_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read EfacturaToken|null $token
 *
 * @method static Builder|static notDownloaded()
 * @method static Builder|static received()
 * @method static Builder|static sent()
 * @method static Builder|static forToken(EfacturaToken $token)
 * @method static Builder|static needsDownload()
 */
class EfacturaMessage extends Model
{
    protected $fillable = [
        'efactura_token_id',
        'message_id',
        'type',
        'download_id',
        'cif_emitent',
        'cif_beneficiar',
        'details',
        'xml_path',
        'is_downloaded',
        'message_date',
        'download_attempts',
        'next_download_at',
    ];

    protected $casts = [
        'details' => 'array',
        'is_downloaded' => 'boolean',
        'message_date' => 'datetime',
        'download_attempts' => 'integer',
        'next_download_at' => 'datetime',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(EfacturaToken::class, 'efactura_token_id');
    }

    public function isSent(): bool
    {
        return $this->type === 'sent';
    }

    public function isReceived(): bool
    {
        return $this->type === 'received';
    }

    public function isError(): bool
    {
        return $this->type === 'error';
    }

    public function scopeNotDownloaded(Builder $query): Builder
    {
        return $query->where('is_downloaded', false);
    }

    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('type', 'received');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('type', 'sent');
    }

    public function scopeForToken(Builder $query, EfacturaToken $token): Builder
    {
        return $query->where('efactura_token_id', $token->id);
    }

    /**
     * Received invoices not yet downloaded whose next try is due (next_download_at null or
     * past), only within the 60 days ANAF keeps the archive.
     */
    public function scopeNeedsDownload(Builder $query): Builder
    {
        return $query->where('type', 'received')
            ->where('is_downloaded', false)
            ->whereNotNull('download_id')
            ->where(fn (Builder $due) => $due->whereNull('next_download_at')->orWhere('next_download_at', '<=', now()))
            ->whereRaw('COALESCE(message_date, created_at) >= ?', [now()->subSeconds(60 * 86400)]);
    }
}
