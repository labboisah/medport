<?php

namespace App\Integrations;

/**
 * Integration boundary. A partner adapter turns one partner-specific delivery into MediPass's neutral
 * item shape, so no partner assumption reaches the core ingest or domain code.
 *
 * Each returned item is either
 *   ['category', 'source_identifier', 'source_updated_at' (Carbon), 'identifier' => ['system','value'], 'clinical_date' (Carbon), 'attrs' => [...model columns]]
 * or ['error' => code] for an item that cannot be mapped (the rest of the delivery still proceeds).
 */
interface PartnerAdapter
{
    /** @throws MalformedPayload when the delivery as a whole is invalid */
    public function normalize(array $payload): array;
}
