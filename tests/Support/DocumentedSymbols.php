<?php

declare(strict_types=1);

namespace BeeCoded\EFactura\Tests\Support;

use BeeCoded\EFacturaSdk\EFacturaServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Extracts the symbols our documentation claims exist, so DocsDriftTest can
 * check each one against PHP reality.
 *
 * Why this exists: the MCP suite asserts documentation *contains* a string
 * (`expect(content).toContain("fromTokens")`). That is circular — the docs are
 * both the subject and the oracle, so a doc naming a deleted method stays green
 * forever. Only a check against the source can fail for the right reason.
 *
 * Scope is deliberately limited to forms whose meaning is unambiguous. Two are
 * excluded on purpose:
 *
 *  - Bare `method()` mentions, and the `$var->method()` form. Their owning class
 *    is not machine-recoverable; guessing an owner produces noise, not signal.
 *  - Bare class names in prose. `EFactura` is both a facade and the product
 *    name; matching prose words produces noise, not signal.
 */
final class DocumentedSymbols
{
    /**
     * Namespaces documented but deliberately NOT verified here.
     *
     * `App\` is the reader's own application in listener examples — fictional
     * by design.
     */
    private const FOREIGN_NAMESPACE_PREFIXES = ['App\\'];

    /**
     * Symbols the docs name precisely BECAUSE they no longer exist.
     *
     * The v2->v3 migration guide quotes the exact fatal error an upgrader hits
     * ("Call to undefined method ...UploadService::resetForRateLimit()"), so that
     * documentation is correct only while the symbol is absent. Asserting it
     * exists would invert the guard and demand we resurrect the method.
     *
     * These are asserted ABSENT instead: if one is ever reintroduced, a guide
     * still telling users it was removed becomes a lie, and this fails.
     *
     * @var list<array{class: class-string|string, method: string}>
     */
    private const DOCUMENTED_AS_REMOVED = [
        ['class' => 'BeeCoded\\EFactura\\Services\\UploadService', 'method' => 'resetForRateLimit'],
    ];

    /**
     * @return list<array{class: string, method: string}>
     */
    public static function documentedAsRemoved(): array
    {
        return self::DOCUMENTED_AS_REMOVED;
    }

    /**
     * True when a documented Class::method reference is one we expect to be gone.
     */
    public static function isDocumentedAsRemoved(string $fqcn, string $method): bool
    {
        foreach (self::DOCUMENTED_AS_REMOVED as $removed) {
            if ($removed['class'] === $fqcn && $removed['method'] === $method) {
                return true;
            }
        }

        return false;
    }

    public static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every documentation source, keyed by a human label for test failure output.
     *
     * @return array<string, string> label => markdown text
     */
    public static function sources(): array
    {
        $root = self::packageRoot();
        $sources = ['README.md' => (string) file_get_contents($root.'/README.md')];

        foreach (glob($root.'/mcp/src/content/*.ts') ?: [] as $path) {
            // The MCP content files are TypeScript template literals holding
            // markdown, so every backslash and backtick arrives escaped:
            // `BeeCoded\\EFactura\\Events\\TokenStored` on disk is one
            // backslash in the rendered doc. Undo the escaping first — that
            // also removes the trailing `\` of an escaped closing backtick,
            // which would otherwise glue itself onto the symbol name.
            $label = 'mcp/src/content/'.basename($path);
            $sources[$label] = self::unescapeTemplateLiteral((string) file_get_contents($path));
        }

        return $sources;
    }

    /**
     * Resolve TypeScript template-literal escapes to the characters they denote.
     *
     * Processes left to right, so `\\` collapses to `\` and \` collapses to a
     * backtick without either interfering with the other.
     */
    public static function unescapeTemplateLiteral(string $text): string
    {
        return (string) preg_replace('/\\\\(.)/s', '$1', $text);
    }

    /**
     * Fully-qualified class names this package's docs claim, per source.
     *
     * @return array<string, list<string>> label => FQCNs
     */
    public static function fullyQualifiedClassNames(): array
    {
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\bBeeCoded\\\\[A-Za-z0-9_\\\\]+/', $text, $matches);

            $names = array_values(array_unique($matches[0]));
            $names = array_filter($names, fn (string $n): bool => !self::isForeign($n));

            // A trailing backslash means we matched a namespace written as
            // `BeeCoded\EFactura\Jobs\` — normalise it away.
            $names = array_map(fn (string $n): string => rtrim($n, '\\'), $names);

            $found[$label] = array_values(array_unique($names));
        }

        return $found;
    }

    private static function isForeign(string $fqcn): bool
    {
        foreach (self::FOREIGN_NAMESPACE_PREFIXES as $prefix) {
            if (str_starts_with($fqcn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every class, interface, trait and enum that really exists in this package
     * and in the SDK it wraps.
     *
     * Built by walking the source tree rather than by autoloading, so a
     * documented symbol that no longer has a file is a miss even if some
     * stale class_alias still resolves it.
     *
     * @return list<string>
     */
    public static function realSymbols(): array
    {
        return array_merge(
            self::symbolsUnder(self::packageRoot().'/src', 'BeeCoded\\EFactura\\'),
            self::symbolsUnder(self::sdkSourceDir(), 'BeeCoded\\EFacturaSdk\\'),
        );
    }

    /**
     * Locate the SDK's src/ through reflection rather than a hardcoded vendor
     * path, so this keeps working when the SDK is symlinked in via a path repo.
     */
    public static function sdkSourceDir(): string
    {
        $provider = new ReflectionClass(EFacturaServiceProvider::class);

        return dirname((string) $provider->getFileName());
    }

    /**
     * @return list<string>
     */
    private static function symbolsUnder(string $srcDir, string $namespacePrefix): array
    {
        if (!is_dir($srcDir)) {
            return [];
        }

        $symbols = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($srcDir) + 1);
            $symbols[] = $namespacePrefix.str_replace('/', '\\', substr($relative, 0, -4));
        }

        sort($symbols);

        return $symbols;
    }

    /**
     * True when $fqcn names a real symbol, or a namespace that really contains
     * symbols. Docs legitimately reference namespaces ("All jobs live in the
     * `BeeCoded\EFactura\Jobs` namespace"), which are not classes.
     *
     * @param  list<string>  $real
     */
    public static function resolves(string $fqcn, array $real): bool
    {
        if (in_array($fqcn, $real, true)) {
            return true;
        }

        foreach ($real as $symbol) {
            if (str_starts_with($symbol, $fqcn.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `Enum::Case` references, excluding static method calls (`Enum::from(...)`)
     * and `::class`.
     *
     * @return array<string, list<string>> label => "Enum::Case"
     */
    public static function enumCaseReferences(): array
    {
        $enums = self::enumsByShortName();
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, PREG_SET_ORDER);

            $refs = [];
            foreach ($matches as [$whole, $short, $member]) {
                if (!isset($enums[$short]) || !self::looksLikeEnumCase($member)) {
                    continue;
                }
                $refs[] = $whole;
            }

            $found[$label] = array_values(array_unique($refs));
        }

        return $found;
    }

    /**
     * Is this member name an enum case rather than a static method?
     *
     * Convention decides it: cases are PascalCase (`FailureReason::Validation`),
     * methods are camelCase (`FailureReason::retryable()`). Trailing parens
     * cannot decide it — docs write `` `UploadStatus::Pending` (queued) ``, where
     * the paren opens a parenthetical, not an argument list.
     */
    private static function looksLikeEnumCase(string $member): bool
    {
        return $member !== 'class' && preg_match('/^[A-Z]/', $member) === 1;
    }

    /**
     * Short enum name => FQCN, for enums this package declares.
     *
     * @return array<string, class-string>
     */
    public static function enumsByShortName(): array
    {
        $enums = [];

        foreach (glob(self::packageRoot().'/src/Enums/*.php') ?: [] as $path) {
            $short = basename($path, '.php');
            $enums[$short] = 'BeeCoded\\EFactura\\Enums\\'.$short;
        }

        return $enums;
    }

    /**
     * Short class name => FQCN, for names that are unambiguous.
     *
     * Ambiguous short names are dropped rather than guessed: the SDK declares
     * both Data\Invoice\AddressData and Data\Company\AddressData, so a bare
     * `AddressData::` cannot be attributed to either.
     *
     * @return array<string, string>
     */
    public static function unambiguousShortNames(): array
    {
        $byShort = [];

        foreach (self::realSymbols() as $fqcn) {
            $short = substr((string) strrchr($fqcn, '\\'), 1);
            $byShort[$short][] = $fqcn;
        }

        $unambiguous = [];
        foreach ($byShort as $short => $candidates) {
            if (count($candidates) === 1) {
                $unambiguous[$short] = $candidates[0];
            }
        }

        return $unambiguous;
    }

    /**
     * `Class::method(` references, resolved to the class they name.
     *
     * Only short names we can attribute are returned, which naturally drops
     * third-party (`Carbon::now()`) and placeholder (`YourModel::`) classes.
     *
     * @return array<string, list<array{class: string, method: string, ref: string}>>
     */
    public static function staticMethodReferences(): array
    {
        $known = self::unambiguousShortNames();
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $text, $matches, PREG_SET_ORDER);

            $enums = self::enumsByShortName();

            $refs = [];
            foreach ($matches as [, $short, $method]) {
                if (!isset($known[$short])) {
                    continue;
                }

                // An enum case followed by a parenthetical is not a method call.
                if (isset($enums[$short]) && self::looksLikeEnumCase($method)) {
                    continue;
                }

                $refs[$short.'::'.$method] = [
                    'class' => $known[$short],
                    'method' => $method,
                    'ref' => $short.'::'.$method.'()',
                ];
            }

            $found[$label] = array_values($refs);
        }

        return $found;
    }

    /**
     * `Class::$property` references, resolved to the class they name.
     *
     * @return array<string, list<array{class: string, property: string, ref: string}>>
     */
    public static function staticPropertyReferences(): array
    {
        $known = self::unambiguousShortNames();
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::\$([a-zA-Z_][A-Za-z0-9_]*)/', $text, $matches, PREG_SET_ORDER);

            $refs = [];
            foreach ($matches as [, $short, $property]) {
                if (!isset($known[$short])) {
                    continue;
                }

                $refs[$short.'::$'.$property] = [
                    'class' => $known[$short],
                    'property' => $property,
                    'ref' => $short.'::$'.$property,
                ];
            }

            $found[$label] = array_values($refs);
        }

        return $found;
    }

    /**
     * Does $method exist on $fqcn, accounting for how Laravel really resolves it?
     *
     * Four indirections matter, and all four appear in our docs:
     *  - Inheritance: method_exists already covers inherited and protected
     *    methods, plus enum builtins like from()/tryFrom().
     *  - Eloquent scopes: docs write the call site `forCui()`, the model declares
     *    `scopeForCui()`.
     *  - Facades: `EFactura::queueUpload()` is not declared on the facade at all;
     *    it proxies to whatever getFacadeAccessor() resolves to.
     *  - Eloquent's __callStatic: `EfacturaToken::where()` is declared on neither
     *    the model nor its parents — Model forwards unknown calls to the query
     *    builder, so the builders are part of a model's real static surface.
     */
    public static function methodResolves(string $fqcn, string $method): bool
    {
        if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
            return false;
        }

        if (method_exists($fqcn, $method) || method_exists($fqcn, 'scope'.ucfirst($method))) {
            return true;
        }

        if (self::forwardsToQueryBuilder($fqcn, $method)) {
            return true;
        }

        $target = self::facadeTarget($fqcn);

        return $target !== null && method_exists($target, $method);
    }

    /**
     * Would an Eloquent model forward this static call to a query builder?
     */
    private static function forwardsToQueryBuilder(string $fqcn, string $method): bool
    {
        if (!is_subclass_of($fqcn, Model::class)) {
            return false;
        }

        return method_exists(Builder::class, $method)
            || method_exists(\Illuminate\Database\Query\Builder::class, $method);
    }

    /**
     * The class a facade proxies to, or null when $fqcn is not a facade.
     */
    public static function facadeTarget(string $fqcn): ?string
    {
        if (!is_subclass_of($fqcn, Facade::class)) {
            return null;
        }

        // getFacadeAccessor() is protected; since PHP 8.1 reflection can invoke
        // it without setAccessible(), which is now a deprecated no-op.
        $resolved = (new \ReflectionMethod($fqcn, 'getFacadeAccessor'))->invoke(null);

        if (!is_string($resolved)) {
            return null;
        }

        if (class_exists($resolved) || interface_exists($resolved)) {
            return $resolved;
        }

        // A container alias rather than a class name.
        return app()->bound($resolved) ? app($resolved)::class : null;
    }

    /**
     * Config paths the docs claim, normalised to be relative to config/efactura.php.
     *
     * Handles the three forms in use: `config('efactura.queue')`, a backticked
     * `efactura.jobs.max_staleness_seconds`, and a backticked bare
     * `jobs.max_staleness_seconds`. The bare form is only accepted when its
     * first segment is a real top-level group, so ordinary dotted prose does
     * not masquerade as a config key.
     *
     * @return array<string, list<string>> label => dotted path
     */
    public static function configPaths(): array
    {
        $groups = array_keys(self::realConfig());
        $groupAlternation = implode('|', array_map('preg_quote', $groups));
        $found = [];

        foreach (self::sources() as $label => $text) {
            $paths = [];

            // config('efactura.x.y') — explicit, any path.
            preg_match_all("/config\(['\"]efactura\.([A-Za-z0-9_.]+)['\"]\)/", $text, $m);
            $paths = array_merge($paths, $m[1]);

            // `efactura.x.y` in backticks — explicit, any path.
            preg_match_all('/`efactura\.([A-Za-z0-9_.]+)`/', $text, $m);
            $paths = array_merge($paths, $m[1]);

            // `jobs.x` in backticks — only for real top-level groups.
            if ($groupAlternation !== '') {
                preg_match_all("/`(({$groupAlternation})\.[A-Za-z0-9_.]+)`/", $text, $m);
                $paths = array_merge($paths, $m[1]);
            }

            $paths = array_map(fn (string $p): string => rtrim($p, '.'), $paths);

            $found[$label] = array_values(array_unique($paths));
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    public static function realConfig(): array
    {
        return require self::packageRoot().'/config/efactura.php';
    }

    /**
     * EFACTURA_* env vars the docs claim.
     *
     * @return array<string, list<string>> label => env var name
     */
    public static function envVarReferences(): array
    {
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/\bEFACTURA_[A-Z0-9_]+\b/', $text, $matches);
            $found[$label] = array_values(array_unique($matches[0]));
        }

        return $found;
    }

    /**
     * EFACTURA_* env vars actually read by this package's config or the SDK's.
     *
     * The wrapper's docs legitimately mention SDK-owned vars (EFACTURA_CLIENT_ID
     * lives in the SDK's config), so both files are authoritative here.
     *
     * @return list<string>
     */
    public static function realEnvVars(): array
    {
        $configs = [
            self::packageRoot().'/config/efactura.php',
            self::sdkSourceDir().'/../config/efactura-sdk.php',
        ];

        $vars = [];
        foreach ($configs as $path) {
            if (!is_file($path)) {
                continue;
            }
            preg_match_all("/env\(\s*['\"](EFACTURA_[A-Z0-9_]+)['\"]/", (string) file_get_contents($path), $m);
            $vars = array_merge($vars, $m[1]);
        }

        return array_values(array_unique($vars));
    }

    /**
     * Artisan invocations the docs show, as [command, [options]].
     *
     * @return array<string, list<array{command: string, options: list<string>}>>
     */
    public static function artisanInvocations(): array
    {
        $found = [];

        foreach (self::sources() as $label => $text) {
            preg_match_all('/php artisan (efactura:[a-z-]+)([^\n`]*)/', $text, $matches, PREG_SET_ORDER);

            $calls = [];
            foreach ($matches as [, $command, $rest]) {
                // Strip any =value so --cui=12345678 checks as --cui.
                preg_match_all('/--([a-z][a-z-]*)/', $rest, $optionMatches);

                $calls[] = [
                    'command' => $command,
                    'options' => array_values(array_unique($optionMatches[1])),
                ];
            }

            $found[$label] = $calls;
        }

        return $found;
    }
}
