<?php

return [

    'stages' => [
        'fresh'                => 'Fresh',
        'connected'            => 'Connected',
        'not_connected'        => 'Not connected',
        'details_shared'       => 'Details shared',
        'site_visit_scheduled' => 'Site visit scheduled',
        'site_visit_done'      => 'Site visit done',
        'in_discussion'        => 'In discussion',
        'booking_done'         => 'Booking done',
        'lost'                 => 'Lost',
    ],

    'terminal_stages' => ['booking_done', 'lost'],

    'stage_colors' => [
        'fresh'                => '#8A94A0',
        'connected'            => '#2F6FB0',
        'not_connected'        => '#C2711A',
        'details_shared'       => '#5B58B8',
        'site_visit_scheduled' => '#8145A8',
        'site_visit_done'      => '#0F766E',
        'in_discussion'        => '#B4881B',
        'booking_done'         => '#1E7A45',
        'lost'                 => '#B23A38',
    ],

    'sources' => [
        'walk_in'       => 'Walk-in',
        'incoming_call' => 'Incoming call',
        'referral'      => 'Referral',
        'facebook'      => 'Facebook',
        'instagram'     => 'Instagram',
        'whatsapp'      => 'WhatsApp',
        'broker'        => 'Broker',
        'hoarding'      => 'Hoarding',
    ],

    'lost_reasons' => [
        'budget'      => 'Budget',
        'location'    => 'Location',
        'competitor'  => 'Chose competitor',
        'not_serious' => 'Not serious',
        'no_response' => 'No response',
    ],

    'todo_types' => [
        'call'       => 'Call',
        'whatsapp'   => 'WhatsApp',
        'meeting'    => 'Meeting',
        'site_visit' => 'Site visit',
    ],

    /*
     |--------------------------------------------------------------------------
     | Working hours
     |--------------------------------------------------------------------------
     |
     | Every follow-up the system generates is pulled inside these before it is
     | saved. A "not connected" call logged at 6 PM would otherwise ask someone
     | to ring back at 10 PM. Nothing outside this file names an hour or a day.
     |
     | A site visit the customer chose is never touched by any of this — see
     | LeadFollowUpService::schedule().
     |
     */

    // 24-hour clock, local time (the app runs in Asia/Kolkata). The day is open
    // from `start` up to but not including `end`: 9 to 18 means 9:00 AM through
    // 5:59 PM, and 6:00 PM itself is already closed.
    'working_hours' => [
        'start' => 9,
        'end'   => 18,
    ],

    /*
     | Days the office is open, as ISO-8601 weekday numbers: Monday is 1 and
     | Sunday is 7. All seven by default — a sales office is normally open on
     | Sunday, because that is the day families come to look. Drop 6 and 7 for
     | a Monday-to-Friday office.
     */
    'working_days' => [1, 2, 3, 4, 5, 6, 7],

    // Y-m-d dates the office is shut regardless of the weekday
    'holidays' => [],

    // hours until the next to-do, keyed by the stage just set
    'followup_hours' => [
        'fresh'           => 0,
        'connected'       => 48,
        'details_shared'  => 48,
        'site_visit_done' => 24,
        'in_discussion'   => 48,
    ],

    // not_connected escalates by attempt number; a missing key ends the ladder
    'retry_hours'  => [1 => 4, 2 => 24, 3 => 48, 4 => 96],
    'max_attempts' => 5,

    /*
     | How a staff member's role reads in a table cell. Shorter than the role
     | key itself — "Sales" rather than "Salesperson" — because it sits on a
     | sub-line under the name and has to stay out of the way. No Vue file
     | spells these out.
     */
    'role_labels' => [
        'admin'       => 'Admin',
        'telecaller'  => 'Telecaller',
        'salesperson' => 'Sales',
    ],

    // the stage that hands a lead from telecaller to salesperson
    'handover_stage' => 'site_visit_scheduled',

    // round_robin | admin
    'handover_mode' => 'round_robin',

    /*
     | Dialling code for the tel: and wa.me links on the to-do rows and in the
     | log-call modal. Stored mobile numbers are the bare 10 digits, so this is
     | what turns one into something a phone can dial. It reaches the front end
     | through each page's `options` prop — no Vue file names it.
     */
    'country_code' => '+91',
];
