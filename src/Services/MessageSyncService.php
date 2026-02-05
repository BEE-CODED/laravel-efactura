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

use BeeCoded\EFactura\Models\EfacturaMessage;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFacturaSdk\Data\Messages\ListMessagesParamsData;
use BeeCoded\EFacturaSdk\Enums\MessageFilter;
use BeeCoded\EFacturaSdk\Support\Validators\VatNumberValidator;
use Illuminate\Support\Facades\Log;

class MessageSyncService
{
    public function __construct(
        protected TokenService $tokenService,
    ) {}

    /**
     * Sync messages for a token with optional filter.
     */
    public function syncMessages(EfacturaToken $token, ?MessageFilter $filter = null): void
    {
        try {
            $params = new ListMessagesParamsData(
                days: 60,
                filter: $filter,
            );

            $response = $this->tokenService->executeWithClient(
                $token,
                fn ($client) => $client->getMessages($params)
            );

            foreach ($response->messages as $message) {
                $this->upsertMessage($token, $message, $filter);
            }

        } catch (\Throwable $e) {
            Log::error('EFactura message sync failed', [
                'token_id' => $token->id,
                'cui' => $token->cui,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync sent messages for a token.
     */
    public function syncSentMessages(EfacturaToken $token): void
    {
        $this->syncMessages($token, MessageFilter::InvoiceSent);
    }

    /**
     * Sync received messages for a token.
     */
    public function syncReceivedMessages(EfacturaToken $token): void
    {
        $this->syncMessages($token, MessageFilter::InvoiceReceived);
    }

    /**
     * Sync all messages for all active tokens.
     */
    public function syncAllMessages(?MessageFilter $filter = null): void
    {
        $tokens = $this->tokenService->getActiveTokens();

        foreach ($tokens as $token) {
            $this->syncMessages($token, $filter);
        }
    }

    /**
     * Upsert a message into the database.
     *
     * @param  object  $messageData
     */
    protected function upsertMessage(EfacturaToken $token, mixed $messageData, ?MessageFilter $filter): void
    {
        $type = $this->determineMessageType($token, $messageData, $filter);

        EfacturaMessage::updateOrCreate(
            ['message_id' => $messageData->id],
            [
                'efactura_token_id' => $token->id,
                'type' => $type,
                'download_id' => $messageData->idDescarcare ?? null,
                'cif_emitent' => $messageData->cifEmitent ?? null,
                'cif_beneficiar' => $messageData->cifBeneficiar ?? null,
                'details' => (array) $messageData,
                'message_date' => $messageData->dataCreare ?? null,
            ]
        );
    }

    /**
     * Determine message type from filter or message data.
     *
     * @param  object  $messageData
     */
    protected function determineMessageType(EfacturaToken $token, mixed $messageData, ?MessageFilter $filter): string
    {
        // If filter is specified, use it
        if ($filter !== null) {
            return match ($filter) {
                MessageFilter::InvoiceSent => 'sent',
                MessageFilter::InvoiceReceived, MessageFilter::BuyerMessage => 'received',
                MessageFilter::InvoiceErrors => 'error',
            };
        }

        // Infer type from message data when filter is null
        // If we're the sender (cifEmitent matches our CUI), it's a sent message
        // Normalize CUIs before comparison (token stores without prefix, API may include prefix)
        $cifEmitent = $messageData->cifEmitent ?? null;
        $cifBeneficiar = $messageData->cifBeneficiar ?? null;
        $normalizedTokenCui = $token->cui;

        if ($cifEmitent && VatNumberValidator::stripPrefix($cifEmitent) === $normalizedTokenCui) {
            return 'sent';
        }

        if ($cifBeneficiar && VatNumberValidator::stripPrefix($cifBeneficiar) === $normalizedTokenCui) {
            return 'received';
        }

        // Check if message has error indicators
        if (isset($messageData->erpirsError) || isset($messageData->error)) {
            return 'error';
        }

        // Default to 'sent' as most messages from a CUI's perspective are outgoing
        return 'sent';
    }
}
