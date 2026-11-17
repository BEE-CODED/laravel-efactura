<?php

/*
 * This file is part of the Laravel e-Factura package.
 *
 * (c) BEE-CODED <dev.ttl@beecoded.ro>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BeeCoded\EFactura\Services;

use BeeCoded\EFactura\Exceptions\PipelineTablesDisabledException;
use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFacturaSdk\Data\Invoice\PaginatedMessagesParamsData;
use BeeCoded\EFacturaSdk\Data\Response\MessageDetailsData;
use BeeCoded\EFacturaSdk\Data\Response\PaginatedMessagesResponseData;
use BeeCoded\EFacturaSdk\Enums\MessageFilter;
use BeeCoded\EFacturaSdk\Exceptions\ApiException;
use BeeCoded\EFacturaSdk\Support\DateHelper;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class MessageSyncService
{
    /**
     * ANAF wordings that report "nothing to sync" through the SAME `eroare` channel
     * it uses for real failures.
     *
     * /listaMesajePaginatieFactura has no structured way to say "no messages": a quiet day and
     * a revoked CUI both arrive as HTTP 200 + {"eroare": "..."}. The SDK's hasError()
     * is a bare null check and cannot tell them apart (verified: the SDK carries no
     * constant, regex, helper or doc for this), so the discrimination has to live
     * here, and the only signal ANAF gives is the text itself.
     *
     * Matching free text is unpleasant, but the alternative is worse in both
     * directions: throwing on every hasError() dead-letters the sync on every quiet
     * day, and ignoring hasError() is the silent-success bug this replaces. The
     * pattern is deliberately anchored and narrow — a real error such as "Nu exista
     * niciun CIF pentru care sa aveti drept de interogare" must NOT match — and it
     * fails LOUD: an unrecognised no-messages wording throws rather than resuming the
     * silent-success behaviour, so a future ANAF rewording surfaces as a dead-lettered
     * job instead of another year of invisibly syncing nothing.
     *
     * Known wordings: "Nu exista mesaje in intervalul selectat" (the paginated listing
     * this service uses) and "Nu exista mesaje in ultimele 60 zile" (the simple listing).
     *
     * @var list<string>
     */
    protected const NO_MESSAGES_PATTERNS = [
        '/^nu\s+exist[aă]\s+mesaje\b/iu',
    ];

    public function __construct(
        protected TokenService $tokenService,
    ) {}

    /**
     * Sync messages for a token with optional filter.
     *
     * Always through the paginated listing: the simple one refuses a CUI with more than
     * 500 messages in 60 days ("Folositi endpoint-ul cu paginatie"). One window is fixed
     * per run, so the page count cannot shift while it pages: it starts 70 minutes inside
     * ANAF's 60-day limit (counted in UTC, an hour beyond any summer-time offset) and ends a minute ago (never in the future, even
     * with clock skew). Hourly runs overlap, so the margins lose nothing. Each page is one
     * paginated-list call (SDK `paginated_list_per_day_cui`). A run restarts at page 1 and must
     * reach the last page within the job's timeout (README, "Known limitations").
     *
     * Errors PROPAGATE. This method used to catch \Throwable, log one line and return
     * normally — which is precisely why the message-sync bug (reading a property the
     * SDK never declared) survived for months: every sync threw, every throw was
     * swallowed, and every run looked successful while syncing zero messages. A broken
     * wire contract has to be loud.
     *
     * Callers that work for several companies catch around this method, one company at a
     * time: SyncMessages runs one job per company, and efactura:sync carries on with the
     * next company and exits 1.
     *
     * Listed messages without a usable id are skipped with a warning and not counted.
     *
     * @return int How many messages were synced.
     *
     * @throws \Throwable Whatever the SDK/transport raised.
     */
    public function syncMessages(EfacturaToken $token, ?MessageFilter $filter = null): int
    {
        PipelineTablesDisabledException::throwIfDisabled();

        // In UTC: wall-clock days in a zone with summer time can add up to more than 60 days.
        $start = now('UTC')->subDays(60)->addMinutes(70);
        $end = now('UTC')->subMinute();
        $synced = 0;

        for ($page = 1; ; $page++) {
            $params = PaginatedMessagesParamsData::fromDateRange($token->cui, $start, $end, $page, $filter);

            $response = $this->tokenService->executeWithClient(
                $token,
                fn ($client) => $client->getMessagesPaginated($params)
            );

            if ($this->reportsNoMessages($response, $token)) {
                return $synced;
            }

            foreach ($response->mesaje ?? [] as $message) {
                // Without an id a message can be neither downloaded nor told apart from
                // another: upserting it would merge every such message into one row. ANAF ids
                // are digits; the SDK refuses to download anything else.
                if ($message->id === null || !ctype_digit($message->id)) {
                    Log::warning('EFactura: ANAF listed a message without a usable id, skipping it', [
                        'cui' => $token->cui,
                        'tip' => $message->tip,
                        'id_solicitare' => $message->idSolicitare,
                    ]);

                    continue;
                }

                $this->upsertMessage($token, $message, $filter);
                $synced++;
            }

            // Our own page count bounds the loop even if ANAF kept echoing an old page index.
            if ($page >= ($response->totalPages ?? 0)) {
                return $synced;
            }
        }
    }

    /**
     * Resolve ANAF's error channel, which the SDK cannot raise for us.
     *
     * The message list reports its failures as HTTP 200 + {"eroare": "..."}, and
     * the SDK's BaseApiClient::call() only throws when ! $response->successful(). So
     * a broken sync arrives here looking exactly like an empty one: `mesaje` is null,
     * foreach([]) runs zero times, 0 is returned, nothing throws, and the job, the
     * scheduler and `efactura:sync` all report success. Every hour, forever, while
     * received invoices never land. Checking hasError() is the only thing standing
     * between a broken wire contract and silence.
     *
     * @return bool True when ANAF merely said there is nothing to sync.
     *
     * @throws ApiException When ANAF reported an actual failure.
     */
    protected function reportsNoMessages(PaginatedMessagesResponseData $response, EfacturaToken $token): bool
    {
        if (!$response->hasError()) {
            return false;
        }

        $error = $response->error ?? '';

        if ($this->isNoMessagesNotice($error)) {
            return true;
        }

        // Surfaced as ApiException for consistency: every other ANAF failure already
        // reaches callers as one, the only difference being that ANAF chose to dress
        // this one as a 200.
        throw new ApiException($error, 200, $error, null, [
            'cui' => $token->cui,
            'titlu' => $response->titlu,
        ]);
    }

    /**
     * Whether ANAF's `eroare` text is the benign "there are no messages" notice.
     */
    protected function isNoMessagesNotice(string $error): bool
    {
        $error = trim($error);

        foreach (static::NO_MESSAGES_PATTERNS as $pattern) {
            if (preg_match($pattern, $error) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sync sent messages for a token.
     *
     * @return int How many messages were synced.
     */
    public function syncSentMessages(EfacturaToken $token): int
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->syncMessages($token, MessageFilter::InvoiceSent);
    }

    /**
     * Sync received messages for a token.
     *
     * @return int How many messages were synced.
     */
    public function syncReceivedMessages(EfacturaToken $token): int
    {
        PipelineTablesDisabledException::throwIfDisabled();

        return $this->syncMessages($token, MessageFilter::InvoiceReceived);
    }

    /**
     * Upsert a message into the database.
     */
    protected function upsertMessage(EfacturaToken $token, MessageDetailsData $messageData, ?MessageFilter $filter): void
    {
        $type = $this->determineMessageType($messageData, $filter);
        [$cifEmitent, $cifBeneficiar] = $this->extractCifs($token, $messageData, $type);

        $values = [
            'type' => $type,
            // ANAF's message id IS the download id - it's what /descarcare?id= expects.
            'download_id' => $messageData->id,
            'cif_emitent' => $cifEmitent,
            'cif_beneficiar' => $cifBeneficiar,
            'message_date' => $this->parseMessageDate($messageData->dataCreare),
        ];
        $details = $messageData->toArray();

        $message = EfacturaMessage::firstOrCreate(
            // Per token: two connected companies can both list one message (migration 000011).
            ['efactura_token_id' => $token->id, 'message_id' => $messageData->id],
            $values + ['details' => $details],
        );
        $message->fill($values);

        // Loose ==: MySQL's JSON type reorders keys, which === would read as a change.
        if ($message->details != $details) {
            $message->details = $details;
        }

        $message->save();
    }

    /**
     * Determine message type from ANAF's authoritative `tip` field.
     *
     * `tip` is what ANAF actually says the message is, so it wins over the request
     * filter: a listing can be filtered server-side but the message itself is the
     * source of truth. The filter is only a fallback for a `tip` we don't recognise
     * (e.g. if ANAF adds a new category).
     */
    protected function determineMessageType(MessageDetailsData $messageData, ?MessageFilter $filter): string
    {
        $tip = strtoupper(trim($messageData->tip));

        // A buyer message's tip comes in variants ("MESAJ CUMPARATOR PRIMIT / ... TRANSMIS").
        $type = str_starts_with($tip, 'MESAJ CUMPARATOR') ? 'received' : match ($tip) {
            'FACTURA TRIMISA' => 'sent',
            'FACTURA PRIMITA' => 'received',
            'ERORI FACTURA' => 'error',
            default => null,
        };

        if ($type !== null) {
            return $type;
        }

        $fallback = $filter !== null
            ? match ($filter) {
                MessageFilter::InvoiceSent => 'sent',
                MessageFilter::InvoiceReceived, MessageFilter::BuyerMessage => 'received',
                MessageFilter::InvoiceErrors => 'error',
            }
        // Default to 'sent' as most messages from a CUI's perspective are outgoing
        : 'sent';

        // The fallback is a guess, and on the production path it is an UNTESTABLE one:
        // SyncMessages passes no filter, so every unrecognised `tip` silently becomes
        // 'sent'. Were ANAF to qualify a tip (e.g. "MESAJ CUMPARATOR PRIMIT"), a
        // RECEIVED invoice would be filed as 'sent', never match ->received(), and
        // never be downloaded — with nothing anywhere to say so. Guessing is fine;
        // guessing quietly is not.
        Log::warning('EFactura: ANAF sent an unrecognised message type', [
            'tip' => $messageData->tip,
            'message_id' => $messageData->id,
            'filter' => $filter?->value,
            'fell_back_to' => $fallback,
        ]);

        return $fallback;
    }

    /**
     * Extract the issuer/beneficiary CUIs for a message.
     *
     * MessageDetailsData carries no dedicated emitent/beneficiar fields. ANAF spells
     * both parties out inside the free-text `detalii`, e.g.
     *   "Factura cu id_incarcare=5001130255 emisa de cif_emitent=12345678 pentru cif_beneficiar=87654321"
     * but not for every `tip` (error messages only carry id_incarcare). Whatever
     * `detalii` doesn't give us, we can still infer for our own side from the message
     * type, since the listing was requested for this token's CUI.
     *
     * @return array{0: string|null, 1: string|null} [emitent, beneficiar]
     */
    protected function extractCifs(EfacturaToken $token, MessageDetailsData $messageData, string $type): array
    {
        $cifEmitent = $this->matchCif('cif_emitent', $messageData->detalii);
        $cifBeneficiar = $this->matchCif('cif_beneficiar', $messageData->detalii);

        if ($cifEmitent === null && $type === 'sent') {
            $cifEmitent = $token->cui;
        }

        if ($cifBeneficiar === null && $type === 'received') {
            $cifBeneficiar = $token->cui;
        }

        return [$cifEmitent, $cifBeneficiar];
    }

    /**
     * Pull a single "<key>=<cui>" value out of ANAF's free-text details.
     */
    protected function matchCif(string $key, string $detalii): ?string
    {
        if (preg_match('/'.preg_quote($key, '/').'\s*=\s*([A-Za-z]{0,2}\d+)/', $detalii, $matches) !== 1) {
            return null;
        }

        return VatNumberValidator::stripPrefix($matches[1]);
    }

    /**
     * Parse ANAF's `data_creare` (format 'YmdHi', e.g. 202407171230).
     *
     * Must not be handed to the model's `datetime` cast raw: it would read
     * 202407171230 as a unix timestamp and land in the year ~8383.
     *
     * TIMEZONE: `data_creare` carries no offset and ANAF emits Romanian local time,
     * so it is only meaningful when resolved in ANAF's timezone — parsing it in the
     * app timezone skews every message by the 2-3h Bucharest offset (and by a
     * DST-dependent amount, so no constant correction exists either). DateHelper
     * exposes ANAF_TIMEZONE publicly for exactly this: "callers can anchor their own
     * dates to ANAF's business day".
     *
     * STORAGE: the result is then converted to PHP's default timezone before it
     * reaches the model. EfacturaMessage casts message_date as `datetime`, and
     * Eloquent's fromDateTime() formats whatever wall-clock the Carbon happens to
     * carry, while the read path parses it back in the default timezone. Handing over
     * a Bucharest-anchored Carbon would therefore write "12:30" and read it back as
     * 12:30 app-time — reintroducing the very skew this resolves. Converting first
     * makes the round trip land on the instant ANAF meant.
     *
     * A data_creare that names no real minute in Bucharest (30 February, the spring-forward
     * gap) is null.
     */
    protected function parseMessageDate(string $dataCreare): ?Carbon
    {
        $dataCreare = trim($dataCreare);

        if ($dataCreare === '') {
            return null;
        }

        $date = DateHelper::exact($dataCreare, 'YmdHi', DateHelper::ANAF_TIMEZONE);

        return $date === null ? null : Carbon::instance($date)->setTimezone(date_default_timezone_get());
    }
}
