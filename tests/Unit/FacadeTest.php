<?php

use BeeCoded\EFactura\EfacturaManager;
use BeeCoded\EFactura\Facades\EFactura;

describe('EFactura Facade', function () {
    it('resolves to EfacturaManager', function () {
        $resolved = EFactura::getFacadeRoot();

        expect($resolved)->toBeInstanceOf(EfacturaManager::class);
    });
});
