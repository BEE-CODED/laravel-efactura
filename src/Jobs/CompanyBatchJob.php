<?php

declare(strict_types=1);

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Jobs;

use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Services\TokenService;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A periodic job that works for one company, or, without a CUI, dispatches one job per
 * company with due work: each company gets its own timeout and retries, so one slow or
 * broken company neither uses up the others' budget nor makes the queue redo them.
 *
 * Without a CUI it dispatches one job per usable company (TokenService::usableTokens():
 * active, not paused, canonical CUI) that has a row in due(). With a CUI that is not a
 * canonical CUI or CNP it fails; with one whose company is unknown, deactivated or paused
 * it logs a warning and does nothing. Otherwise it calls the subclass's public
 * run(EfacturaToken $token, ...), whose other parameters the container injects.
 *
 * Queued at most once per company until it starts (the unique id is the canonical CUI)
 * and never run twice at once for one company.
 */
abstract class CompanyBatchJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [60, 180, 300];

    public int $maxExceptions = 3;

    public int $uniqueFor = 3600;

    /**
     * @param  string|null  $cui  The company's CUI, in any spelling canonical() reads; null means every company.
     */
    final public function __construct(public ?string $cui = null)
    {
        // Never throws: the scheduler builds its jobs on every artisan boot. An invalid CUI fails when the job runs.
        $this->cui = $cui === null ? null : (VatNumberValidator::canonical($cui) ?? $cui);
        $this->onQueue(config('efactura.queue'));
    }

    /**
     * The features.* switch that turns this job off.
     */
    abstract protected function feature(): string;

    /**
     * The rows a company needs a job for, or null when every usable company does. It must
     * select what the service run selects, or a job is dispatched with nothing to do.
     */
    abstract protected function due(): ?Builder;

    public function uniqueId(): string
    {
        return $this->cui ?? 'all';
    }

    /**
     * A run longer than the schedule interval must not overlap the next one on another
     * worker: both would spend the same rows' daily ANAF quota.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->dontRelease()->expireAfter($this->timeout + 30)];
    }

    final public function handle(TokenService $tokens, Container $container): void
    {
        PipelineTablesDisabledException::throwIfDisabled();

        if (!config('efactura.enabled') || !config('efactura.features.'.$this->feature())) {
            return;
        }

        if ($this->cui === null) {
            $usable = $tokens->usableTokens();
            $due = $this->due();

            if ($due !== null) {
                $usable = $usable->whereIn('id', $due->distinct()->pluck('efactura_token_id')->all());
            }

            foreach ($usable as $token) {
                dispatch(new static($token->cui));
            }

            return;
        }

        if (VatNumberValidator::canonical($this->cui) === null) {
            $this->fail(new \InvalidArgumentException("Invalid CUI: '{$this->cui}'"));

            return;
        }

        $token = $tokens->usableToken($this->cui);

        if ($token === null) {
            Log::warning('EFactura: no usable token for CUI', ['cui' => $this->cui, 'job' => class_basename(static::class)]);

            return;
        }

        $container->call([$this, 'run'], ['token' => $token]);
    }
}
