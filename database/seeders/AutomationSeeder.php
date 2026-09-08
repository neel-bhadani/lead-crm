<?php

namespace Database\Seeders;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\WhatsApp\TemplateRenderer;
use Illuminate\Database\Seeder;

/**
 * Starter rules and starter messages, shipped switched off.
 *
 * This is documentation that runs. Nobody reads a help page about triggers and
 * conditions; everybody reads eight rules with names like "Chase ignored leads"
 * and immediately understands what the feature is for. The admin opens one,
 * sees the plain-words sentence, edits a number, presses Test, and turns it on
 * — which is the whole workflow, learned by using it once.
 *
 * EVERY RULE IS INACTIVE. That is not a default that could be flipped here for
 * convenience: seeding eight live rules would mean a fresh install starts
 * reassigning leads and queueing messages before anybody has read a word of it.
 * Each one carries a description written for the person who has to decide
 * whether to switch it on, in the words they would use themselves.
 *
 * Idempotent by name, so running it twice does not produce sixteen rules, and
 * so re-running it after an edit does not quietly undo the admin's changes to a
 * rule they have already switched on — see rule().
 */
class AutomationSeeder extends Seeder
{
    public function run(): void
    {
        $templates = $this->templates();
        $this->rules($templates);
    }

    /* ---------------- messages ---------------- */

    /**
     * Six messages a Surat builder's office would actually send.
     *
     * The category on each one is a real decision and not a label: utility is
     * roughly an eighth the price of marketing, and the difference between them
     * is whether the customer started the conversation. A brochure they asked
     * for is utility. A Diwali greeting is marketing, however friendly it
     * sounds, and pricing it as utility is how a WhatsApp bill triples.
     *
     * @return array<string, MessageTemplate>
     */
    private function templates(): array
    {
        $renderer = app(TemplateRenderer::class);

        $rows = [
            'welcome' => [
                'name'     => 'Welcome — new enquiry',
                'category' => 'utility',
                'body'     => "Namaste {first_name}, thank you for your interest in {project}.\n\n"
                    . "I am {owner_name} from our sales team. I will call you shortly to understand "
                    . "what you are looking for.\n\n"
                    . "You can reach me any time on {owner_phone}.",
            ],

            'brochure' => [
                'name'     => 'Brochure follow-up',
                'category' => 'utility',
                'body'     => "Hello {first_name}, I have shared the brochure and floor plans for {project} with you.\n\n"
                    . "Do have a look at the layouts and let me know which configuration suits you best. "
                    . "Happy to arrange a site visit at your convenience.\n\n"
                    . "— {owner_name}, {owner_phone}",
            ],

            'visit_confirmation' => [
                'name'     => 'Site visit confirmation',
                'category' => 'utility',
                'body'     => "Hello {first_name}, your site visit to {project} is confirmed.\n\n"
                    . "Please carry a photo ID for entry. Parking is available at the site office.\n\n"
                    . "I will meet you there — {owner_name}, {owner_phone}. "
                    . "Call me if you need directions or want to change the time.",
            ],

            'post_visit' => [
                'name'     => 'Thank you after a site visit',
                'category' => 'utility',
                'body'     => "Thank you for visiting {project} today, {first_name}.\n\n"
                    . "I hope you liked the sample flat and the amenities. If you have any questions about "
                    . "the payment plan, possession timeline or bank approvals, just message me here.\n\n"
                    . "— {owner_name}",
            ],

            'booking' => [
                'name'     => 'Booking confirmation',
                'category' => 'utility',
                'body'     => "Congratulations {lead_name}! Your booking at {project} is confirmed.\n\n"
                    . "Our team will share the allotment letter and the payment schedule shortly. "
                    . "Welcome to the {project} family.\n\n"
                    . "— {owner_name}, {owner_phone}",
            ],

            'festive' => [
                /*
                 | The one marketing template in the set, and it is here partly
                 | to make the price difference concrete: this is the message
                 | that costs eight times the others, and it is the one an
                 | office is most tempted to send to everybody at once.
                 */
                'name'     => 'Festive greeting',
                'category' => 'marketing',
                'body'     => "Wishing you and your family a very happy Diwali, {first_name}!\n\n"
                    . "From all of us at {project}. May the new year bring you your own new home.\n\n"
                    . "— {owner_name}",
            ],
        ];

        $made = [];

        foreach ($rows as $key => $row) {
            $made[$key] = MessageTemplate::firstOrCreate(
                ['name' => $row['name']],
                $row + [
                    'placeholder_map' => $renderer->mapFor($row['body']),
                    'approval_status' => 'draft',
                    'is_active'       => true,
                ],
            );
        }

        return $made;
    }

    /* ---------------- rules ---------------- */

    /** @param array<string, MessageTemplate> $templates */
    private function rules(array $templates): void
    {
        $author = User::where('role', 'admin')->orderBy('id')->first();

        $this->rule($author, [
            'name'        => 'Facebook leads to the telecaller desk',
            'description' => 'Leads that come in from a Facebook ad are shared out evenly among the '
                . 'telecallers, and each one gets a call booked in an hour\'s time. Turn this on if '
                . 'you are running ads and want them picked up the same day rather than whenever '
                . 'somebody notices them.',
            'trigger'    => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => 'facebook']],
            'actions'    => [
                ['type' => 'assign_round_robin', 'role' => 'telecaller'],
                ['type' => 'create_follow_up', 'hours' => 1, 'todo_type' => 'call',
                 'remarks' => 'New Facebook lead — first call.'],
            ],
        ]);

        $this->rule($author, [
            'name'        => 'Broker leads straight to sales',
            'description' => 'A lead that comes through a broker is usually further along than a cold '
                . 'enquiry, so it goes to a salesperson rather than a telecaller, with a call booked '
                . 'for two hours\' time.',
            'trigger'    => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => 'broker']],
            'actions'    => [
                ['type' => 'assign_round_robin', 'role' => 'salesperson'],
                ['type' => 'create_follow_up', 'hours' => 2, 'todo_type' => 'call',
                 'remarks' => 'Broker lead — call and confirm what the broker has already told them.'],
            ],
        ]);

        $this->rule($author, [
            'name'        => 'Thank a walk-in',
            'description' => 'Somebody who walked into the site office gets a WhatsApp welcome ready to '
                . 'send. The message is not sent on its own — it waits in the Queue tab for you to open '
                . 'and send it.',
            'trigger'    => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => 'walk_in']],
            'actions'    => [
                ['type' => 'queue_whatsapp', 'template_id' => $templates['welcome']->id],
            ],
        ]);

        $this->rule($author, [
            'name'        => 'Chase ignored leads',
            'description' => 'If a follow-up is three days past its date and still open, the person it '
                . 'belongs to is told about it. This is the one most offices switch on first — it is '
                . 'the cheapest way to stop leads going quiet.',
            'trigger'        => 'follow_up_overdue',
            'trigger_config' => ['days' => 3],
            'actions'        => [[
                'type'      => 'raise_alert',
                'recipient' => 'lead_owner',
                'severity'  => 'warning',
                'title'     => '{lead_name} has been waiting three days',
                'body'      => 'The follow-up on this lead is overdue. Call them today or move the date.',
            ]],
        ]);

        $this->rule($author, [
            'name'        => 'Flag stuck negotiations',
            'description' => 'A lead that has been sitting In discussion for a week is going cold. '
                . 'Everybody with an admin login is told, because at that point it usually needs a '
                . 'senior person to step in on price or possession.',
            'trigger'        => 'stage_idle',
            'trigger_config' => ['stage' => 'in_discussion', 'days' => 7],
            'actions'        => [[
                'type'      => 'raise_alert',
                'recipient' => 'admins',
                'severity'  => 'warning',
                'title'     => '{lead_name} has been in discussion for a week',
                'body'      => 'Nothing has moved on {project} since the negotiation started. '
                    . 'Worth a call from a senior person.',
            ]],
        ]);

        $this->rule($author, [
            'name'        => 'Nudge after a site visit',
            'description' => 'The day after somebody visits the site is when they decide. This books a '
                . 'call for 24 hours later and puts a thank-you message in the queue.',
            'trigger'        => 'stage_changed',
            'trigger_config' => ['stage' => 'site_visit_done'],
            'actions'        => [
                ['type' => 'create_follow_up', 'hours' => 24, 'todo_type' => 'call',
                 'remarks' => 'Day-after call. Ask what they thought of the sample flat.'],
                ['type' => 'queue_whatsapp', 'template_id' => $templates['post_visit']->id],
            ],
        ]);

        $this->rule($author, [
            'name'        => 'Confirm a booked site visit',
            'description' => 'When a site visit is scheduled, a confirmation message with the ID and '
                . 'parking details is put in the queue. Cuts down on people not turning up.',
            'trigger'        => 'stage_changed',
            'trigger_config' => ['stage' => 'site_visit_scheduled'],
            'actions'        => [
                ['type' => 'queue_whatsapp', 'template_id' => $templates['visit_confirmation']->id],
            ],
        ]);

        $this->rule($author, [
            'name'        => 'Tell everyone about a booking',
            'description' => 'A booking is good news and everybody should see it. Raises an alert for '
                . 'all admins and queues the confirmation message for the customer.',
            'trigger'        => 'stage_changed',
            'trigger_config' => ['stage' => 'booking_done'],
            'actions'        => [
                ['type' => 'raise_alert', 'recipient' => 'admins', 'severity' => 'info',
                 'title' => 'Booking done — {lead_name} at {project}',
                 'body'  => 'Closed by {owner_name}.'],
                ['type' => 'queue_whatsapp', 'template_id' => $templates['booking']->id],
            ],
        ]);
    }

    /**
     * One starter rule, written switched off, and never overwritten.
     *
     * firstOrCreate rather than updateOrCreate on purpose. The whole point of
     * these is that the admin edits them — changes three days to five, swaps
     * the telecaller desk for the sales desk — and a seeder that rewrote them
     * on the next deploy would undo that silently. They are a starting point,
     * not managed configuration.
     */
    private function rule(?User $author, array $attrs): void
    {
        AutomationRule::firstOrCreate(
            ['name' => $attrs['name']],
            $attrs + [
                'trigger_config' => [],
                'conditions'     => [],
                // shipped off. Always.
                'is_active'      => false,
                'created_by'     => $author?->id,
            ],
        );
    }
}
