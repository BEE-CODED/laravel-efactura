<?php

declare(strict_types=1);

use BeeCoded\EFactura\Tests\Support\DocumentedSymbols;
use Illuminate\Support\Arr;

/**
 * Docs-drift guard: every symbol the documentation names must really exist.
 *
 * This complements the MCP suite rather than duplicating it. Those tests assert
 * documentation *contains* a string, which cannot fail when a symbol is deleted
 * — the docs are both subject and oracle. These tests fail for the right reason:
 * they ask PHP.
 *
 * Honest scope note: this catches symbols that vanish or get renamed. It cannot
 * catch documentation that is merely *wrong* (correct symbol, misleading prose).
 *
 * @see DocumentedSymbols for what is deliberately out of scope and why.
 */
/**
 * The canary that makes every other test in this file mean something.
 *
 * Every assertion here is of the form "nothing extracted was broken", which is
 * trivially true when nothing is extracted at all. Rename README.md, move the
 * MCP content, or land a regex typo, and the whole file would go green while
 * checking precisely nothing.
 *
 * The floors are deliberately far below current counts — this is a smoke alarm
 * for a dead extractor, not a coverage ratchet to bump on every doc edit.
 */
it('still extracts symbols from every documentation source', function () {
    $sources = DocumentedSymbols::sources();

    expect($sources)->toHaveCount(6);

    foreach ($sources as $label => $text) {
        expect(strlen($text))->toBeGreaterThan(500, "{$label} is empty or unreadable");
    }

    $total = fn (array $perSource): int => array_sum(array_map('count', $perSource));

    expect(count(DocumentedSymbols::realSymbols()))->toBeGreaterThan(50)
        ->and($total(DocumentedSymbols::fullyQualifiedClassNames()))->toBeGreaterThan(30)
        ->and($total(DocumentedSymbols::enumCaseReferences()))->toBeGreaterThan(3)
        ->and($total(DocumentedSymbols::configPaths()))->toBeGreaterThan(15)
        ->and($total(DocumentedSymbols::envVarReferences()))->toBeGreaterThan(15)
        ->and($total(DocumentedSymbols::staticMethodReferences()))->toBeGreaterThan(5)
        ->and($total(DocumentedSymbols::artisanInvocations()))->toBeGreaterThan(10);
});

it('names only classes, interfaces, traits and enums that exist', function () {
    $real = DocumentedSymbols::realSymbols();
    $missing = [];

    foreach (DocumentedSymbols::fullyQualifiedClassNames() as $label => $names) {
        foreach ($names as $name) {
            if (!DocumentedSymbols::resolves($name, $real)) {
                $missing[] = "{$label}: {$name}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references symbols that do not exist: '.implode(', ', $missing));
});

it('names only enum cases that exist', function () {
    $enums = DocumentedSymbols::enumsByShortName();
    $missing = [];

    foreach (DocumentedSymbols::enumCaseReferences() as $label => $refs) {
        foreach ($refs as $ref) {
            [$short, $case] = explode('::', $ref);

            if (!defined($enums[$short].'::'.$case)) {
                $missing[] = "{$label}: {$ref}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references enum cases that do not exist: '.implode(', ', $missing));
});

it('names only config keys that exist', function () {
    $config = DocumentedSymbols::realConfig();
    $missing = [];

    foreach (DocumentedSymbols::configPaths() as $label => $paths) {
        foreach ($paths as $path) {
            if (!Arr::has($config, $path)) {
                $missing[] = "{$label}: efactura.{$path}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references config keys that do not exist: '.implode(', ', $missing));
});

it('names only env vars the config actually reads', function () {
    $real = DocumentedSymbols::realEnvVars();
    $missing = [];

    foreach (DocumentedSymbols::envVarReferences() as $label => $vars) {
        foreach ($vars as $var) {
            if (!in_array($var, $real, true)) {
                $missing[] = "{$label}: {$var}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references env vars no config reads: '.implode(', ', $missing));
});

it('names only Class::method() references that resolve', function () {
    $missing = [];

    foreach (DocumentedSymbols::staticMethodReferences() as $label => $refs) {
        foreach ($refs as $ref) {
            if (DocumentedSymbols::isDocumentedAsRemoved($ref['class'], $ref['method'])) {
                continue;
            }

            if (!DocumentedSymbols::methodResolves($ref['class'], $ref['method'])) {
                $missing[] = "{$label}: {$ref['ref']}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation calls methods that do not exist: '.implode(', ', $missing));
});

it('names only Class::$property references that resolve', function () {
    $missing = [];

    foreach (DocumentedSymbols::staticPropertyReferences() as $label => $refs) {
        foreach ($refs as $ref) {
            if (!property_exists($ref['class'], $ref['property'])) {
                $missing[] = "{$label}: {$ref['ref']}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references properties that do not exist: '.implode(', ', $missing));
});

it('keeps symbols the migration guide says were removed actually removed', function () {
    foreach (DocumentedSymbols::documentedAsRemoved() as $removed) {
        expect(method_exists($removed['class'], $removed['method']))->toBeFalse(
            "{$removed['class']}::{$removed['method']}() exists again, but the migration guide tells users it was removed."
        );
    }
})->skip(
    DocumentedSymbols::documentedAsRemoved() === [],
    'Nothing is currently documented as removed.'
);

it('shows only artisan commands and options that exist', function () {
    $commands = app(Illuminate\Contracts\Console\Kernel::class)->all();
    $problems = [];

    foreach (DocumentedSymbols::artisanInvocations() as $label => $calls) {
        foreach ($calls as $call) {
            $name = $call['command'];

            if (!isset($commands[$name])) {
                $problems[] = "{$label}: command {$name} is not registered";

                continue;
            }

            $definition = $commands[$name]->getDefinition();

            foreach ($call['options'] as $option) {
                if (!$definition->hasOption($option)) {
                    $problems[] = "{$label}: {$name} has no --{$option} option";
                }
            }
        }
    }

    expect($problems)->toBe([], 'Documentation shows artisan usage that does not work: '.implode(', ', $problems));
});
