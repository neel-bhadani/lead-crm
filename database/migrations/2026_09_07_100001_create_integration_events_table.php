<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity log behind the Integrations page.
 *
 * Without it, an integration that stops delivering leads is silent: nobody
 * notices until someone asks why Facebook has gone quiet, which is weeks. Every
 * outcome is written here — the ones that made a lead and the ones that did not
 * — so "no rows since Tuesday" is a readable fact rather than an absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');

            // created | duplicate | repeat_enquiry | failed — config('integrations.results')
            $table->string('result');

            // the provider's own id for the enquiry: a leadgen_id for Meta.
            // Null when the failure happened before there was one to record.
            $table->string('external_id')->nullable();

            // set only on `created`, and nulled rather than cascading if the
            // lead is later hard-deleted: the log is history, not a join
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            // why, in one line: the error for a failure, the reason for a skip
            $table->text('message')->nullable();

            $table->timestamps();

            // the page reads the newest 50, per provider
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
