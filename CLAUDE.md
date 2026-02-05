# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel wrapper package for `bee-coded/laravel-efactura-sdk`, providing token storage, job scheduling, model integration, and easy configuration for Romania's ANAF e-Factura system.

## Development Guidelines

### Package Structure (Laravel Package Convention)
```
src/
├── EfacturaServiceProvider.php  # Laravel service provider
├── EfacturaManager.php          # Main manager class
├── Facades/                     # Laravel facades
├── Services/                    # Business logic (token, upload, download, sync)
├── Models/                      # Eloquent models (Token, Upload, Message)
├── Jobs/                        # Queue jobs for async processing
├── Events/                      # Domain events
├── Console/Commands/            # Artisan commands
├── Http/Controllers/            # OAuth callback controller
├── Contracts/                   # Interfaces
├── Enums/                       # PHP enums
└── Traits/                      # Model traits (HasEfacturaUpload)
config/
    efactura.php                 # Package configuration
database/
    migrations/                  # Database migrations
routes/
    efactura.php                 # OAuth routes
tests/
    Feature/
    Unit/
```

### Commands

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Run single test
./vendor/bin/phpunit --filter TestMethodName

# Run tests with coverage
./vendor/bin/phpunit --coverage-html coverage

# Code style (if using Laravel Pint)
./vendor/bin/pint

# Static analysis (if using PHPStan)
./vendor/bin/phpstan analyse
```

### Code Conventions

- Use `->foreignIdFor()` instead of `->foreignId()` in migrations when referencing model classes
- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`

### Architecture Patterns

#### Business Logic Separation
- **Services**: ALL business logic belongs in Service classes
- **Models**: ONLY relationships, scopes, accessors, and simple state checks like `isValid()`, `isCompleted()`
- ❌ NEVER put API calls, data transformations, or complex logic in Models

#### Dependency Injection
Services ALWAYS use constructor injection:
```php
class UploadService
{
    public function __construct(
        private readonly EFacturaClient $client,
        private readonly TokenService $tokenService,
    ) {}
}
```

#### Service Pattern
```php
// ✅ Correct - business logic in service
class TokenService
{
    public function getTokensForCif(string $cif): ?OAuthTokensData
    {
        $token = EfacturaToken::where('cif', $cif)->first();
        if (!$token) return null;

        return new OAuthTokensData(
            accessToken: Crypt::decryptString($token->access_token),
            refreshToken: Crypt::decryptString($token->refresh_token),
            expiresAt: $token->expires_at,
        );
    }
}

// ❌ Wrong - business logic in model
class EfacturaToken extends Model
{
    public function getDecryptedTokens(): OAuthTokensData { /* NO */ }
}
```

#### Model Pattern
```php
class EfacturaToken extends Model
{
    use HasFactory;

    protected $fillable = ['cif', 'access_token', 'refresh_token', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    // ✅ Simple state check - OK in model
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    // ❌ Business logic - belongs in service
    public function refresh(): void { /* NO - put in TokenService */ }
}
```

#### Enums
Use PHP 8.1+ backed enums:
```php
enum UploadStatus: string
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Processed = 'processed';
    case Failed = 'failed';
}
```

#### Exception Pattern
```php
class EFacturaException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $context = []
    ) {
        parent::__construct($message, $code, $previous);
    }
}
```

### e-Factura API Context

This package wraps the `bee-coded/laravel-efactura-sdk` which interacts with ANAF's e-Factura system:
- Uses OAuth2 for authentication
- Accepts UBL 2.1 XML format invoices
- Has separate endpoints for test/production environments
- Requires digital certificates for production