<?php

use BeeCoded\EFactura\EfacturaManager;
use BeeCoded\EFactura\Services\DownloadService;
use BeeCoded\EFactura\Services\MessageSyncService;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFactura\Services\UploadService;

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

    describe('Configuration', function () {
        it('merges default config', function () {
            expect(config('efactura.enabled'))->not->toBeNull();
            expect(config('efactura.features'))->toBeArray();
            expect(config('efactura.storage'))->toBeArray();
        });

        it('has default feature flags', function () {
            expect(config('efactura.features.upload_invoices'))->toBeBool();
            expect(config('efactura.features.download_received'))->toBeBool();
            expect(config('efactura.features.sync_messages'))->toBeBool();
        });

        it('has storage configuration', function () {
            expect(config('efactura.storage.disk'))->toBeString();
            expect(config('efactura.storage.path'))->toBeString();
        });
    });

    describe('Route Registration', function () {
        it('registers routes when enabled', function () {
            config(['efactura.routes.enabled' => true]);

            $routes = collect(app('router')->getRoutes()->getRoutes());

            $efacturaRoutes = $routes->filter(function ($route) {
                return str_contains($route->uri(), 'efactura') ||
                       str_contains($route->getName() ?? '', 'efactura');
            });

            expect($efacturaRoutes)->not->toBeEmpty();
        });
    });

    describe('Console Commands', function () {
        it('registers efactura:upload command', function () {
            $commands = array_keys(\Artisan::all());

            expect($commands)->toContain('efactura:upload');
        });

        it('registers efactura:status command', function () {
            $commands = array_keys(\Artisan::all());

            expect($commands)->toContain('efactura:status');
        });

        it('registers efactura:sync command', function () {
            $commands = array_keys(\Artisan::all());

            expect($commands)->toContain('efactura:sync');
        });

        it('registers efactura:auth command', function () {
            $commands = array_keys(\Artisan::all());

            expect($commands)->toContain('efactura:auth');
        });
    });
});
