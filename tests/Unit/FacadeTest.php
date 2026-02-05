<?php

use BeeCoded\EFactura\EfacturaManager;
use BeeCoded\EFactura\Facades\EFactura;

describe('EFactura Facade', function () {
    it('resolves to EfacturaManager', function () {
        $resolved = EFactura::getFacadeRoot();

        expect($resolved)->toBeInstanceOf(EfacturaManager::class);
    });

    it('returns correct facade accessor', function () {
        $reflection = new ReflectionClass(EFactura::class);
        $method = $reflection->getMethod('getFacadeAccessor');
        $method->setAccessible(true);

        $accessor = $method->invoke(null);

        expect($accessor)->toBe(EfacturaManager::class);
    });
});
