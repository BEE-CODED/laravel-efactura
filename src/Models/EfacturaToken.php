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

    protected $casts = [
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
        return VatNumberValidator::normalize($this->cui);
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
