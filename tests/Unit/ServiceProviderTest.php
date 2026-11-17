<?php

use BeeCoded\EFactura\EfacturaManager;
use BeeCoded\EFactura\EfacturaServiceProvider;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\RetryingAnafDetailsClient;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;
use BeeCoded\EFacturaSdk\Contracts\AnafDetailsClientInterface;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

describe('EfacturaServiceProvider', function () {
    describe('Service Registration', function () {
        it('registers TokenService as singleton', function () {
            $service1 = app(TokenService::class);
            $service2 = app(TokenService::class);

            expect($service1)->toBe($service2);
        });

        it('registers UploadService as singleton', function () {
            $service1 = app(UploadService::class);
            $service2 = app(UploadService::class);

            expect($service1)->toBe($service2);
        });

        it('registers DownloadService as singleton', function () {
            $service1 = app(DownloadService::class);
            $service2 = app(DownloadService::class);

            expect($service1)->toBe($service2);
        });

        it('registers MessageSyncService as singleton', function () {
            $service1 = app(MessageSyncService::class);
            $service2 = app(MessageSyncService::class);

            expect($service1)->toBe($service2);
        });

        it('registers EfacturaManager as singleton', function () {
            $manager1 = app(EfacturaManager::class);
            $manager2 = app(EfacturaManager::class);

            expect($manager1)->toBe($manager2);
            expect($manager1)->toBeInstanceOf(EfacturaManager::class);
        });

        it('injects services into EfacturaManager', function () {
            $manager = app(EfacturaManager::class);

            expect($manager->tokenService())->toBeInstanceOf(TokenService::class);
            expect($manager->uploadService())->toBeInstanceOf(UploadService::class);
            expect($manager->downloadService())->toBeInstanceOf(DownloadService::class);
            expect($manager->messageSyncService())->toBeInstanceOf(MessageSyncService::class);
        });
    });

    describe('ANAF Details Decorator', function () {
        it('decorates AnafDetailsClientInterface with the retrying decorator', function () {
            expect(app(AnafDetailsClientInterface::class))
                ->toBeInstanceOf(RetryingAnafDetailsClient::class);
        });

        it('resolves the decorator as a shared singleton', function () {
            expect(app(AnafDetailsClientInterface::class))
                ->toBe(app(AnafDetailsClientInterface::class));
        });
    });

    describe('Configuration', function () {
        // Only keys the TestCase does not set: those prove mergeConfigFrom ran.
        it('merges the package defaults under the keys the app does not set', function () {
            expect(config()->has('efactura.pipeline_tables'))->toBeTrue()
                ->and(config('efactura.anaf_lookup.retry_attempts'))->not->toBeNull();
        });
    });

    describe('the env normaliser', function () {
        beforeEach(function () {
            $this->register = fn () => (new EfacturaServiceProvider(app()))->register();
        });

        it('gives blank storage and lookup values their defaults', function (mixed $value) {
            config(['efactura.storage.disk' => $value, 'efactura.storage.path' => $value, 'efactura.anaf_lookup.retry_attempts' => $value]);

            ($this->register)();

            expect(config('efactura.storage.disk'))->toBe('local')
                ->and(config('efactura.storage.path'))->toBe('efactura')
                ->and(config('efactura.anaf_lookup.retry_attempts'))->toBe(5);
        })->with(['empty' => [''], 'null' => [null], 'spaces' => ['  '], 'true' => [true]]);

        it('keeps explicit values', function () {
            config(['efactura.storage.disk' => 's3', 'efactura.anaf_lookup.retry_attempts' => '3']);

            ($this->register)();

            expect(config('efactura.storage.disk'))->toBe('s3')
                ->and(config('efactura.anaf_lookup.retry_attempts'))->toBe(3);
        });
    });

    describe('Route Registration', function () {
        it('registers the OAuth routes only when enabled', function (bool $enabled) {
            config(['efactura.routes.enabled' => $enabled]);

            Route::setRoutes(new RouteCollection);
            (fn () => $this->registerRoutes())->call(app()->getProvider(EfacturaServiceProvider::class));
            Route::getRoutes()->refreshNameLookups();

            expect(Route::has('efactura.auth'))->toBe($enabled)
                ->and(Route::has('efactura.callback'))->toBe($enabled);
        })->with(['enabled' => [true], 'disabled' => [false]]);

        it('mounts the OAuth routes under efactura for a blank prefix', function (mixed $prefix) {
            config(['efactura.routes.prefix' => $prefix, 'efactura.routes.enabled' => true]);
            (new EfacturaServiceProvider(app()))->register();

            Route::setRoutes(new RouteCollection);
            (fn () => $this->registerRoutes())->call(app()->getProvider(EfacturaServiceProvider::class));
            Route::getRoutes()->refreshNameLookups();

            expect(route('efactura.callback', [], false))->toBe('/efactura/callback')
                ->and(route('efactura.auth', ['cui' => '18547290'], false))->toBe('/efactura/auth/18547290');
        })->with(['empty' => [''], 'null' => [null], 'false' => [false]]);
    });

    describe('Console Commands', function () {
        it('registers efactura:upload command', function () {
            $commands = array_keys(Artisan::all());

            expect($commands)->toContain('efactura:upload');
        });

        it('registers efactura:status command', function () {
            $commands = array_keys(Artisan::all());

            expect($commands)->toContain('efactura:status');
        });

        it('registers efactura:sync command', function () {
            $commands = array_keys(Artisan::all());

            expect($commands)->toContain('efactura:sync');
        });

        it('registers efactura:auth command', function () {
            $commands = array_keys(Artisan::all());

            expect($commands)->toContain('efactura:auth');
        });
    });
});
