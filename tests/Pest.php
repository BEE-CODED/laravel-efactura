<?php

use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Start the refresh cooldown TokenService records after ANAF refuses a grant, stored the
 * way Redis hands it back: the expiry as a numeric string.
 */
function coolDownGrant(EfacturaToken $token, int $seconds): void
{
    Cache::put(
        "efactura:token_refresh_refused:{$token->id}:".sha1((string) $token->refresh_token),
        (string) now()->addSeconds($seconds)->getTimestamp(),
        $seconds,
    );
}

/**
 * A readable response archive as ANAF's descarcare returns it: the given members, or a
 * single error report, zipped.
 *
 * @param  array<string, string>  $members
 */
function responseZip(array $members = ['raport.xml' => '<Raport/>']): string
{
    $path = tempnam(sys_get_temp_dir(), 'efactura-zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($members as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * A stareMesaj answer as ANAF sends it: an XML header carrying the state and download id as
 * attributes, or only an error.
 */
function anafStatus(?string $stare, ?string $downloadId = null, ?string $error = null): PromiseInterface
{
    $attributes = ($stare === null ? '' : ' stare="'.$stare.'"').($downloadId === null ? '' : ' id_descarcare="'.$downloadId.'"');
    $body = $error === null ? '/>' : '><Errors errorMessage="'.htmlspecialchars($error, ENT_XML1 | ENT_QUOTES).'"/></header>';

    return Http::response(
        '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1"'.$attributes.$body,
        200,
        ['Content-Type' => 'application/xml'],
    );
}
