<?php

namespace Database\Seeders;

use App\Models\{User, Project, Lead, Todo};
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'first_name' => 'Rajesh',
            'last_name' => 'Mehta',
            'email' => 'admin@crm.test',
            'mobile_number' => '9820000001',
            'password' => bcrypt('123456789'),
            'role' => 'admin',
        ]);
        $tele = User::create([
            'first_name' => 'Priya',
            'last_name' => 'Shah',
            'email' => 'tele@crm.test',
            'mobile_number' => '9820000002',
            'password' => bcrypt('123456789'),
            'role' => 'telecaller',
        ]);
        $sales = collect([
            ['Amit', 'Patel', 'sales@crm.test', '9820000003'],
            ['Nisha', 'Desai', 'sales2@crm.test', '9820000004'],
        ])->map(fn($s) => User::create([
            'first_name' => $s[0],
            'last_name' => $s[1],
            'email' => $s[2],
            'mobile_number' => $s[3],
            'password' => bcrypt('123456789'),
            'role' => 'salesperson',
        ]));
        $projects = collect([
            ['Skyline Residency', 'Vesu, Surat'],
            ['Green Court', 'Pal, Surat'],
            ['Orion Business Hub', 'Adajan, Surat'],
        ])->map(fn($p) => Project::create([
            'name' => $p[0],
            'location' => $p[1],
            'created_by' => $admin->id,
        ]));
        // journeys a lead can take — repeated entries make a path more likely
        $paths = [
            ['fresh'],
            ['fresh', 'not_connected'],
            ['fresh', 'not_connected', 'not_connected'],
            ['fresh', 'not_connected', 'lost'],
            ['fresh', 'connected'],
            ['fresh', 'connected', 'details_shared'],
            ['fresh', 'connected', 'details_shared', 'lost'],
            ['fresh', 'connected', 'details_shared', 'site_visit_scheduled'],
            ['fresh', 'connected', 'details_shared', 'site_visit_scheduled', 'site_visit_done'],
            ['fresh', 'connected', 'details_shared', 'site_visit_scheduled', 'site_visit_done', 'in_discussion'],
            ['fresh', 'connected', 'details_shared', 'site_visit_scheduled', 'site_visit_done', 'in_discussion', 'booking_done'],
            ['fresh', 'connected', 'details_shared', 'site_visit_scheduled', 'site_visit_done', 'in_discussion', 'lost'],
        ];
        $first =
            ['Hardik', 'Meera', 'Jignesh', 'Kavita', 'Sanjay', 'Roshni', 'Bhavesh', 'Ankita', 'Vipul', 'Pooja', 'Nilesh', 'Sneha', 'Dhruv', 'Rekha', 'Manish
', 'Tejas', 'Ronak', 'Nidhi'];
        $last = ['Patel', 'Shah', 'Desai', 'Joshi', 'Trivedi', 'Mehta', 'Chauhan', 'Rana', 'Vaghela', 'Bhatt', 'Modi', 'Solanki'];
        $sources = array_keys(config('crm.sources'));
        foreach (range(1, 55) as $i) {
            $path = $paths[array_rand($paths)];
            $stage = end($path);
            $source = $sources[array_rand($sources)];
            $created = now()->subDays(rand(1, 85))->setTime(rand(9, 19), rand(0, 59));
            $owner = count($path) >= 4 ? $sales->random() : $tele;
            $lead = Lead::create([
                'first_name' => $first[array_rand($first)],
                'last_name' => $last[array_rand($last)],
                'mobile_number' => '9' . rand(100000000, 999999999),
                'email' => 'lead' . $i . '@example.com',
                'project_id' => $projects->random()->id,
                'source' => $source,
                'broker_name' => $source === 'broker' ? 'Shreeji Realty' : null,
                'stage' => $stage,
                'stage_changed_at' => $created,
                'assigned_to' => $owner->id,
                'assigned_role' => $owner->role,
                'created_by' => $admin->id,
                'reason' => $stage === 'lost' ? 'budget' : null,
                'booked_unit' => $stage === 'booking_done' ? 'A-' . rand(101, 904) : null,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
            /*
             | Walk the path, writing one completed to-do per stage change.
             |
             | The timestamps are laid out up front, evenly across the window
             | between the lead's creation and now, so they only ever move
             | forward. Stepping by a random interval and then yanking the
             | cursor back whenever it overshot "now" is what produced history
             | that ran 14:12 -> 22:12 -> 05:12 -> 13:12: with the rows out of
             | order the newest one is not the last stage the lead reached, and
             | leads.stage looks like it disagrees with its own history.
             */
            $steps  = max(1, count($path) - 1);
            $window = max($steps, (int) $created->diffInMinutes(now()));
            $stamps = [];

            foreach (range(1, $steps) as $k) {
                $stamps[] = $created->copy()->addMinutes((int) round($window * $k / ($steps + 1)));
            }

            $cursor = $created->copy();
            $notConnected = 0;
            foreach ($path as $idx => $st) {
                if ($idx === 0) continue;
                $cursor = $stamps[$idx - 1];
                $notConnected = $st === 'not_connected' ? $notConnected + 1 : 0;
                Todo::create([
                    'lead_id' => $lead->id,
                    'assigned_to' => $idx >= 3 ? $owner->id : $tele->id,
                    'created_by' => $admin->id,
                    'scheduled_at' => $cursor->copy()->subHours(rand(1, 6)),
                    'type' => $st === 'site_visit_scheduled' ? 'site_visit' : 'call',
                    'status' => 'completed',
                    'remarks' => 'Call logged during seeding.',
                    'outcome_stage' => $st,
                    'completed_at' => $cursor,
                    'completed_by' => $idx >= 3 ? $owner->id : $tele->id,
                ]);
                $lead->stage_changed_at = $cursor;
            }
            $lead->not_connected_count = $notConnected;
            $lead->save();
            // one pending to-do for every open lead — the core rule
            if (! in_array($stage, config('crm.terminal_stages'))) {
                $offset = [-52, -26, -9, -3, -1, 2, 6, 20, 44, 70, 120][array_rand(range(0, 10))];
                Todo::create([
                    'lead_id' => $lead->id,
                    'assigned_to' => $lead->assigned_to,
                    'created_by' => $admin->id,
                    'scheduled_at' => now()->addHours($offset),
                    'type' => $stage === 'site_visit_scheduled' ? 'site_visit' : 'call',
                    'status' => 'pending',
                ]);
            }
        }
    }
}
