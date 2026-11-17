<?php

declare(strict_types=1);

use BeeCoded\EFactura\Tests\Support\DocumentedSymbols;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Arr;

/**
 * Docs-drift guard: every symbol the documentation names must really exist.
 *
 * The sources are README.md and CHANGELOG.md. A test that asserts documentation
 * *contains* a string cannot fail when a symbol is deleted — the docs are both
 * subject and oracle. These tests fail for the right reason: they ask PHP.
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
 * trivially true when nothing is extracted at all. Rename README.md or
 * CHANGELOG.md, or land a regex typo, and the whole file would go green while
 * checking precisely nothing.
 *
 * The floors are deliberately far below current counts — this is a smoke alarm
 * for a dead extractor, not a coverage ratchet to bump on every doc edit.
 */
it('still extracts symbols from every documentation source', function () {
    $sources = DocumentedSymbols::sources();

    expect(array_keys($sources))->toBe(['README.md', 'CHANGELOG.md']);

    foreach ($sources as $label => $text) {
        expect(strlen($text))->toBeGreaterThan(500, "{$label} is empty or unreadable");
    }

    $total = fn (array $perSource): int => array_sum(array_map('count', $perSource));

    expect(count(DocumentedSymbols::realSymbols()))->toBeGreaterThan(50)
        ->and($total(DocumentedSymbols::fullyQualifiedClassNames()))->toBeGreaterThan(20)
        ->and($total(DocumentedSymbols::enumCaseReferences()))->toBeGreaterThan(3)
        ->and($total(DocumentedSymbols::configPaths()))->toBeGreaterThan(5)
        ->and($total(DocumentedSymbols::envVarReferences()))->toBeGreaterThan(15)
        ->and($total(DocumentedSymbols::staticMethodReferences()))->toBeGreaterThan(5)
        ->and($total(DocumentedSymbols::artisanInvocations()))->toBeGreaterThan(10)
        ->and($total(DocumentedSymbols::documentedCadences()))->toBeGreaterThan(10)
        ->and($total(DocumentedSymbols::classConstantReferences()))->toBeGreaterThan(1);
});

it('names only classes, interfaces, traits and enums that exist', function () {
    $real = DocumentedSymbols::realSymbols();
    $missing = [];

    foreach (DocumentedSymbols::fullyQualifiedClassNames() as $label => $names) {
        foreach ($names as $name) {
            if (!DocumentedSymbols::resolves($name, $real) && !DocumentedSymbols::isDocumentedAsRemoved($label, 'class', $name)) {
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
            if (!Arr::has($config, $path) && !DocumentedSymbols::isDocumentedAsRemoved($label, 'config', $path)) {
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
            if (!in_array($var, $real, true) && !DocumentedSymbols::isDocumentedAsRemoved($label, 'env', $var)) {
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
            if (DocumentedSymbols::isDocumentedAsRemoved($label, 'method', $ref['class'].'::'.$ref['method'])) {
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

it('names only Class::CONSTANT references that resolve', function () {
    $missing = [];

    foreach (DocumentedSymbols::classConstantReferences() as $label => $refs) {
        foreach ($refs as $ref) {
            if (!(new ReflectionClass($ref['class']))->hasConstant($ref['constant'])
                && !DocumentedSymbols::isDocumentedAsRemoved($label, 'constant', $ref['class'].'::'.$ref['constant'])) {
                $missing[] = "{$label}: {$ref['ref']}";
            }
        }
    }

    expect($missing)->toBe([], 'Documentation references constants that do not exist: '.implode(', ', $missing));
});

/**
 * The package schedules nothing, so the recommended cadences exist only in the docs,
 * and they used to disagree (CheckUploadStatuses every 5 minutes in one place, 10 in
 * another). One table is the truth; every schedule call and cadence cell must match it.
 */
it('states one recommended cadence per scheduled job, the same everywhere', function () {
    $wrong = [];

    foreach (DocumentedSymbols::documentedCadences() as $label => $refs) {
        foreach ($refs as $ref) {
            if ($ref['cadence'] !== DocumentedSymbols::RECOMMENDED_CADENCES[$ref['job']]) {
                $wrong[] = "{$label}: {$ref['ref']}";
            }
        }
    }

    expect($wrong)->toBe([], 'Documented cadences disagree with the recommended schedule: '.implode(', ', $wrong));
});

it('keeps a complete cadence table in the README', function (string $label) {
    $tabled = collect(DocumentedSymbols::documentedCadences()[$label])
        ->filter(fn (array $ref): bool => str_starts_with($ref['ref'], '|'))
        ->pluck('job')
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($tabled)->toBe(collect(DocumentedSymbols::RECOMMENDED_CADENCES)->keys()->sort()->values()->all());
})->with(['README.md']);

it('accepts a removed symbol only in CHANGELOG.md', function () {
    foreach (DocumentedSymbols::documentedAsRemoved() as $kind => $symbols) {
        foreach ($symbols as $symbol) {
            expect(DocumentedSymbols::isDocumentedAsRemoved('CHANGELOG.md', $kind, $symbol))->toBeTrue("{$kind} {$symbol}")
                ->and(DocumentedSymbols::isDocumentedAsRemoved('README.md', $kind, $symbol))->toBeFalse("{$kind} {$symbol}");
        }
    }
})->skip(
    array_merge(...array_values(DocumentedSymbols::documentedAsRemoved())) === [],
    'Nothing is currently documented as removed.'
);

it('keeps symbols the CHANGELOG says were removed actually removed', function () {
    $removed = DocumentedSymbols::documentedAsRemoved();
    $config = DocumentedSymbols::realConfig();
    $env = DocumentedSymbols::realEnvVars();
    $commands = app(Kernel::class)->all();
    $back = [];

    foreach ($removed['method'] as $entry) {
        [$class, $method] = explode('::', $entry);
        if (method_exists($class, $method)) {
            $back[] = "method {$entry}";
        }
    }

    foreach ($removed['class'] as $class) {
        if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
            $back[] = "class {$class}";
        }
    }

    foreach ($removed['constant'] as $constant) {
        if (defined($constant)) {
            $back[] = "constant {$constant}";
        }
    }

    foreach ($removed['config'] as $path) {
        if (Arr::has($config, $path)) {
            $back[] = "config {$path}";
        }
    }

    foreach ($removed['env'] as $var) {
        if (in_array($var, $env, true)) {
            $back[] = "env {$var}";
        }
    }

    foreach ($removed['option'] as $entry) {
        [$name, $option] = array_pad(explode(' --', $entry, 2), 2, null);
        $command = $commands[$name] ?? null;
        if ($command !== null && ($option === null || $command->getDefinition()->hasOption($option))) {
            $back[] = "option {$entry}";
        }
    }

    expect($back)->toBe([], 'The CHANGELOG says these were removed, but they exist again: '.implode(', ', $back));
})->skip(
    array_merge(...array_values(DocumentedSymbols::documentedAsRemoved())) === [],
    'Nothing is currently documented as removed.'
);

it('shows only artisan commands and options that exist', function () {
    $commands = app(Kernel::class)->all();
    $problems = [];

    foreach (DocumentedSymbols::artisanInvocations() as $label => $calls) {
        foreach ($calls as $call) {
            $name = $call['command'];

            if (!isset($commands[$name])) {
                if (DocumentedSymbols::isDocumentedAsRemoved($label, 'option', $name)) {
                    continue;
                }

                $problems[] = "{$label}: command {$name} is not registered";

                continue;
            }

            $definition = $commands[$name]->getDefinition();

            foreach ($call['options'] as $option) {
                if (!$definition->hasOption($option) && !DocumentedSymbols::isDocumentedAsRemoved($label, 'option', "{$name} --{$option}")) {
                    $problems[] = "{$label}: {$name} has no --{$option} option";
                }
            }
        }
    }

    expect($problems)->toBe([], 'Documentation shows artisan usage that does not work: '.implode(', ', $problems));
});

/**
 * The reverse direction: every event, job and command the package ships has a place in the README.
 * A new one that lands without a heading, a table row or a `php artisan` line fails here.
 */
it('documents every event, job and command in the README', function () {
    $readme = DocumentedSymbols::sources()['README.md'];
    $root = DocumentedSymbols::packageRoot();
    $undocumented = [];

    $classes = [
        ...array_map(fn (string $path): string => 'BeeCoded\\EFactura\\Events\\'.basename($path, '.php'), glob($root.'/src/Events/*.php') ?: []),
        ...array_map(fn (string $path): string => 'BeeCoded\\EFactura\\Jobs\\'.basename($path, '.php'), glob($root.'/src/Jobs/*.php') ?: []),
    ];

    foreach ($classes as $class) {
        // An abstract base (a later CompanyBatchJob) is not something an app dispatches or listens for.
        if (!(new ReflectionClass($class))->isInstantiable()) {
            continue;
        }

        $name = preg_quote(class_basename($class), '/');

        if (preg_match("/^#{2,4} .*\\b{$name}\\b/m", $readme) !== 1 && preg_match("/^\\|\\s*`{$name}`\\s*\\|/m", $readme) !== 1) {
            $undocumented[] = $class;
        }
    }

    $shown = array_column(DocumentedSymbols::artisanInvocations()['README.md'], 'command');

    foreach (array_keys(app(Kernel::class)->all()) as $command) {
        if (str_starts_with($command, 'efactura:') && !in_array($command, $shown, true)) {
            $undocumented[] = $command;
        }
    }

    expect($undocumented)->toBe([], 'The README has no heading, table row or `php artisan` line for: '.implode(', ', $undocumented));
});
