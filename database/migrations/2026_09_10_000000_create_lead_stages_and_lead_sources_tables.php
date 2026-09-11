<?php

use App\Support\CrmTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pipeline vocabulary, moved out of config/crm.php and into two tables the
 * admin can edit.
 *
 * `leads.stage` and `leads.source` are still plain strings and are deliberately
 * NOT turned into foreign keys. Two reasons, and both of them are the point of
 * the whole exercise:
 *
 *   - A foreign key would make deleting a row impossible rather than refused
 *     with an explanation, and "refused with an explanation" is the feature.
 *   - `todos.outcome_stage` holds the stage a lead was moved to at the moment
 *     it was moved, forever. It is history. A constraint that could rewrite or
 *     block history is a constraint that eventually loses some.
 *
 * So the key is the join, matched by string, exactly as it was when the same
 * strings lived in a config file. Nothing about the existing four table
 * migrations changes, and no lead is touched.
 *
 * SEEDED FROM CONFIG, HERE, IN up(). Not from a seeder — a seeder is a thing
 * somebody remembers to run. Every environment that runs `migrate` ends up with
 * the exact nine stages and eight sources it had a minute earlier, with the
 * same keys, the same labels, the same colours and the same order, so the
 * dashboard and both reports return the same numbers on either side of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_stages', function (Blueprint $table) {
            $table->id();

            /*
             | The join to `leads.stage` and `todos.outcome_stage`. Unique, and
             | locked once the row exists — see LeadStageRequest. Re-keying a
             | stage would silently orphan every lead sitting in it and every
             | history row that named it.
             */
            $table->string('key', 50)->unique();
            $table->string('label', 60);
            $table->string('color', 7);
            $table->unsignedInteger('sort_order')->default(0);

            // what `config('crm.terminal_stages')` used to be
            $table->boolean('is_terminal')->default(false);

            /*
             | A row the application itself names in code — `fresh`,
             | `not_connected`, `site_visit_done`, `booking_done`, `lost`, and
             | whatever `crm.handover_stage` points at. Label and colour stay
             | editable; the key, the existence and the active flag do not.
             */
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('label', 60);
            $table->unsignedInteger('sort_order')->default(0);

            /*
             | What `source_defaults` would hold if it existed. Both nullable
             | and both seeded null, so the migration changes no behaviour: see
             | the note in CrmTaxonomy about why nothing reads them yet.
             */
            $table->string('default_stage_key', 50)->nullable();
            $table->string('default_owner_role', 20)->nullable();

            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();

        $terminal = (array) config('crm.terminal_stages', []);
        $handover = (string) config('crm.handover_stage', '');

        /*
         | The five the application names in code, plus the handover stage.
         | Grep for each of these strings and you land on a branch that reads
         | it: `fresh` in LeadFollowUpService::onLeadCreated(), `not_connected`
         | in applyStage()'s counter, `booking_done` and `lost` in the same
         | method's extra-field handling and in every terminal test, and
         | `site_visit_done` in the funnel and the Site visits card.
         */
        $system = array_unique(array_filter(array_merge(
            ['fresh', 'not_connected', 'site_visit_done', 'booking_done', 'lost'],
            [$handover],
        )));

        $order = 0;

        foreach ((array) config('crm.stages', []) as $key => $label) {
            DB::table('lead_stages')->insert([
                'key'         => $key,
                'label'       => $label,
                'color'       => config("crm.stage_colors.$key", '#8A94A0'),
                'sort_order'  => $order += 10,
                'is_terminal' => in_array($key, $terminal, true),
                'is_system'   => in_array($key, $system, true),
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $order = 0;

        foreach ((array) config('crm.sources', []) as $key => $label) {
            DB::table('lead_sources')->insert([
                'key'                => $key,
                'label'              => $label,
                'sort_order'         => $order += 10,
                'default_stage_key'  => config("crm.source_defaults.$key.stage"),
                'default_owner_role' => config("crm.source_defaults.$key.owner_role"),
                /*
                 | `broker` is the one source the application names in code:
                 | LeadRequest demands a channel partner when it is chosen,
                 | LeadController::leadAttributes() clears the partner columns
                 | when it is not, and Lead::getBrokerLabelAttribute() returns
                 | null for every other source. Deleting or switching it off
                 | would leave the channel-partner feature with no way in.
                 */
                'is_system'          => $key === 'broker',
                'is_active'          => true,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        /*
         | DB::table() writes fire no model events, so nothing has busted the
         | vocabulary cache. Without this, `migrate:fresh` over a warm cache
         | serves the previous database's stages until something else saves one.
         */
        CrmTaxonomy::flush();
    }

    public function down(): void
    {
        CrmTaxonomy::flush();

        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('lead_stages');
    }
};
