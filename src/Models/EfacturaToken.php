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

use BeeCoded\EFacturaSdk\Data\Auth\OAuthTokensData;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $cui
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, EfacturaUpload> $uploads
 * @property-read Collection<int, EfacturaMessage> $messages
 *
 * @method static Builder|static active()
 * @method static Builder|static forCui(string $cui)
 */
class EfacturaToken extends Model
{
    protected $fillable = [
        'cui',
        'access_token',
        'refresh_token',
        'expires_at',
        'is_active',
        'last_used_at',
    ];

    /**
     * NOTE: `access_token` and `refresh_token` are encrypted at rest as of v3.0.0.
     *
     * These are live ANAF credentials: whoever holds them can file and read legal
     * tax documents on behalf of the company. Before v3 they sat in plain `text`
     * columns, so a database read, a backup, or a query log was enough to obtain
     * them. `$hidden` (below) never helped with that — it only filters toArray()/
     * toJson(), and has no bearing on what is written to disk.
     *
     * There is deliberately NO decrypt-fallback for plaintext values. A fallback
     * would let a rolled-out cast silently keep reading unmigrated rows, so
     * "encrypted at rest" would become a hope rather than a verifiable fact. A row
     * that predates 2024_01_01_000004 therefore throws DecryptException on read —
     * loudly, at the point of use. Run the migration.
     *
     * The credentials are now only as safe as APP_KEY: rotating it without
     * APP_PREVIOUS_KEYS orphans every token and forces a fresh OAuth flow per
     * company. See the v3 upgrade notes in the README.
     */
    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function uploads(): HasMany
    {
        return $this->hasMany(EfacturaUpload::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EfacturaMessage::class);
    }

    public function getVatNumber(): string
    {
        return VatNumberValidator::stripPrefix($this->cui);
    }

    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return true;
        }

        return $this->expires_at->isPast();
    }

    public function isExpiringSoon(int $bufferSeconds = 300): bool
    {
        if (!$this->expires_at) {
            return true;
        }

        return $this->expires_at->copy()->subSeconds($bufferSeconds)->isPast();
    }

    public function toOAuthTokensData(): OAuthTokensData
    {
        return new OAuthTokensData(
            accessToken: $this->access_token,
            refreshToken: $this->refresh_token,
            expiresAt: $this->expires_at,
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCui(Builder $query, string $cui): Builder
    {
        return $query->where('cui', $cui);
    }
}
