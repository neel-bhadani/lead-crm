<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * The one place an integration outcome is recorded.
 *
 * Two destinations, deliberately: a row in `integration_events` for the admin,
 * and a line in the application log for whoever is debugging. The first is the
 * feature — an integration that quietly stops delivering is invisible without
 * it — and the second is what survives a database that has been reseeded.
 */
class IntegrationLogger
{
    public function created(string $provider, string $externalId, Lead $lead): IntegrationEvent
    {
        // the only outcome that means a lead actually arrived, so it is the
        // only one that moves the card's "Last lead received"
        Integration::where('provider', $provider)->update(['last_received_at' => now()]);

        return $this->write($provider, 'created', $externalId, "Lead #{$lead->id} created.", $lead->id);
    }

    /** The same leadgen_id twice: a Meta retry, or a redelivery. Nothing to do. */
    public function duplicate(string $provider, string $externalId, string $message): IntegrationEvent
    {
        return $this->write($provider, 'duplicate', $externalId, $message);
    }

    /**
     * A real second enquiry from a number already on this project.
     *
     * Not an error and not a duplicate delivery — somebody has filled in a
     * second form. The lead already exists and already has an owner and a
     * pending follow-up, so making another would split one conversation across
     * two rows. Worth seeing, which is why it is its own result.
     */
    public function repeatEnquiry(string $provider, string $externalId, string $message): IntegrationEvent
    {
        return $this->write($provider, 'repeat_enquiry', $externalId, $message);
    }

    public function failed(string $provider, ?string $externalId, string $message): IntegrationEvent
    {
        return $this->write($provider, 'failed', $externalId, $message);
    }

    private function write(string $provider, string $result, ?string $externalId, string $message, ?int $leadId = null): IntegrationEvent
    {
        Log::log(
            $result === 'failed' ? 'error' : 'info',
            "[integration:{$provider}] {$result}",
            ['external_id' => $externalId, 'lead_id' => $leadId, 'message' => $message],
        );

        return IntegrationEvent::create([
            'provider'    => $provider,
            'result'      => $result,
            'external_id' => $externalId,
            'lead_id'     => $leadId,
            // one line in a table cell; the full text is in the application log
            'message'     => \Illuminate\Support\Str::limit($message, 500),
        ]);
    }
}
