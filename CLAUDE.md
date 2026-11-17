# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel wrapper package for `bee-coded/laravel-efactura-sdk`, providing token storage, job scheduling, model integration, and easy configuration for Romania's ANAF e-Factura system.

## Development Guidelines

### Commands

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/pest

# Run single test
./vendor/bin/pest --filter "test name"

# Run tests on PostgreSQL (the database name must end in _test; TestCase refuses any other)
DB_CONNECTION=pgsql DB_DATABASE=efactura_wrapper_test ./vendor/bin/pest

# Run tests with coverage
./vendor/bin/pest --coverage-html coverage

# Code style (if using Laravel Pint)
./vendor/bin/pint

# Static analysis (if using PHPStan)
./vendor/bin/phpstan analyse
```

### Code Conventions

- Use `->foreignIdFor()` instead of `->foreignId()` in migrations when referencing model classes
- Follow PSR-12 coding standards
- New files declare `declare(strict_types=1);` (older files predate the rule)
- Services use constructor injection (`private readonly` in new code)

### Architecture Patterns

#### Business Logic Separation
- **Services**: ALL business logic belongs in Service classes
- **Models**: ONLY relationships, scopes, accessors, and simple state checks like `isValid()`, `isCompleted()`
- ❌ NEVER put API calls, data transformations, or complex logic in Models
- ⚠️ **Token credentials are encrypted by a cast (v3.0.0+).** `Builder::update()` delegates to
  `toBase()->update()`, so **casts never run** — `EfacturaToken::where(...)->update(['access_token' => $x])`
  writes **plaintext** into an encrypted column, and nothing can read it back. Load the model and
  save it, so the cast applies. The same is true in reverse: never call `Crypt` on these attributes.

### e-Factura API Context

This package wraps the `bee-coded/laravel-efactura-sdk` which interacts with ANAF's e-Factura system:
- Uses OAuth2 for authentication
- Accepts UBL 2.1 XML format invoices
- Has separate endpoints for test/production environments
- Requires digital certificates for production