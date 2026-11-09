<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Console\Commands;

use BeeCoded\EFactura\Models\EfacturaUpload;
use BeeCoded\EFactura\Services\UploadService;
use Illuminate\Console\Command;

/**
 * The human resolution path for uploads whose delivery to ANAF is unknown.
 *
 * An upload is parked as Failed/indeterminate when its worker died around the POST
 * (job timeout, SIGKILL), when the transport failed ambiguously (5xx, connection
 * loss), when ANAF's upload answer said nothing usable, or when its status check gave
 * no verdict. Getting it wrong is a legal problem in both directions: re-sending
 * double-files the invoice, writing it off leaves a legally-required filing undone.
 *
 * A row parked by a status check keeps the index_incarcare ANAF issued, and ANAF
 * issues one only for a document it took in: such a row can be resolved as filed,
 * never as not filed. A row parked before ANAF answered has no index, and ANAF's
 * message listing exposes no invoice number to match on — only id / cif /
 * data_creare / tip / detalii / id_solicitare.
 *
 * This is deliberately NOT automated: matching would mean downloading and parsing
 * every candidate ZIP in the window, spending ANAF quota to guess at a legal question.
 * So an operator checks, and this command applies their decision safely.
 *
 * Usage:
 *   php artisan efactura:reconcile                       # list what needs checking
 *   php artisan efactura:reconcile --filed=12 --index=5001130255
 *   php artisan efactura:reconcile --not-filed=12
 */
class EfacturaReconcileCommand extends Command
{
    protected $signature = 'efactura:reconcile
                            {--filed= : Upload ID confirmed as FILED at ANAF (requires --index)}
                            {--index= : The index_incarcare ANAF assigned to the filed document}
                            {--not-filed= : Upload ID confirmed as NOT filed at ANAF (re-queues it; refused when it has an upload index)}';

    protected $description = 'List and resolve e-Factura uploads whose delivery to ANAF is indeterminate';

    public function handle(UploadService $uploadService): int
    {
        if ($this->option('filed')) {
            return $this->resolveAsFiled($uploadService);
        }

        if ($this->option('not-filed')) {
            return $this->resolveAsNotFiled($uploadService);
        }

        return $this->listPending($uploadService);
    }

    private function listPending(UploadService $uploadService): int
    {
        $uploads = $uploadService->awaitingReconciliation();

        if ($uploads->isEmpty()) {
            $this->info('No uploads are awaiting reconciliation.');

            return self::SUCCESS;
        }

        $this->warn("{$uploads->count()} upload(s) have an UNKNOWN delivery status at ANAF.");
        $this->newLine();

        $this->table(
            ['ID', 'CUI', 'Model', 'Model ID', 'Upload index', 'Uploaded at', 'Parked at', 'XML'],
            $uploads->map(fn (EfacturaUpload $upload) => [
                $upload->id,
                $upload->token->cui,
                class_basename($upload->uploadable_type),
                $upload->uploadable_id,
                $upload->upload_index ?? '—',
                $upload->uploaded_at?->format('Y-m-d H:i:s') ?? '—',
                $upload->updated_at->format('Y-m-d H:i:s'),
                $upload->xml_path ?? '—',
            ])->all()
        );

        $this->newLine();
        $this->line('An upload WITH an upload index was taken in by ANAF under that index: look it up');
        $this->line('in SPV, then resolve it as filed with that index. It cannot be resolved as not filed.');
        $this->line('For an upload WITHOUT one, check ANAF (SPV / listaMesajeFactura) around the parked');
        $this->line('time for a "FACTURA TRIMISA" message matching the invoice, then resolve it:');
        $this->newLine();
        $this->line('  Already filed:  php artisan efactura:reconcile --filed=<id> --index=<index_incarcare>');
        $this->line('  Not filed:      php artisan efactura:reconcile --not-filed=<id>   (only without an upload index)');
        $this->newLine();
        $this->comment('These uploads are never re-submitted automatically. Resolving as');
        $this->comment('"not filed" without checking risks DOUBLE-FILING a legal invoice.');

        return self::SUCCESS;
    }

    private function resolveAsFiled(UploadService $uploadService): int
    {
        $index = $this->option('index');

        if (!$index) {
            $this->error('--index is required with --filed: supply the index_incarcare ANAF assigned.');

            return self::FAILURE;
        }

        $upload = $this->findUpload((string) $this->option('filed'));

        if (!$upload) {
            return self::FAILURE;
        }

        $index = trim((string) $index);

        try {
            $resolved = $uploadService->resolveIndeterminateAsFiled($upload, $index);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (!$resolved) {
            $this->error("Upload {$upload->id} is not awaiting reconciliation (status: {$upload->status->value}).");

            return self::FAILURE;
        }

        $this->info("Upload {$upload->id} marked as filed at ANAF with index {$index}.");
        $this->line('It has rejoined the normal status-check pipeline.');

        return self::SUCCESS;
    }

    private function resolveAsNotFiled(UploadService $uploadService): int
    {
        $upload = $this->findUpload((string) $this->option('not-filed'));

        if (!$upload) {
            return self::FAILURE;
        }

        try {
            $resolved = $uploadService->resolveIndeterminateAsNotFiled($upload);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            // Resolving as filed returns it to Processing, which is also how its status is checked again.
            $this->line('Resolve it as filed with that index, which also has its ANAF status checked again:');
            $this->line("  php artisan efactura:reconcile --filed={$upload->id} --index={$upload->upload_index}");
            $this->line("  php artisan efactura:status --upload={$upload->id}");

            return self::FAILURE;
        }

        if (!$resolved) {
            $this->error("Upload {$upload->id} is not awaiting reconciliation (status: {$upload->status->value}).");

            return self::FAILURE;
        }

        $this->info("Upload {$upload->id} re-queued for upload.");

        return self::SUCCESS;
    }

    private function findUpload(string $id): ?EfacturaUpload
    {
        $upload = EfacturaUpload::find($id);

        if (!$upload) {
            $this->error("Upload {$id} not found.");
        }

        return $upload;
    }
}
