<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per connected platform. Facebook is the only one built; the other
 * three cards on the page are stubs and never write a row here.
 *
 * `provider` is unique, so a platform is configured once and reconfiguring it
 * updates the same row — Integration::forProvider() relies on that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();

            /*
             | TEXT, not JSON, and the difference matters.
             |
             | The model casts this `encrypted:array`, so what reaches the
             | database is an encrypted string — base64 ciphertext, not JSON.
             | MySQL's JSON column type validates its input and would reject
             | it outright. `array` is the half of the cast that makes this a
             | JSON document in PHP; `encrypted` is the half that decides what
             | the column has to be able to hold.
             |
             | Everything secret lives in here: the page access token and the
             | app secret. Nothing in the application reads them out to the
             | browser — IntegrationController masks both on the way out.
             */
            $table->text('settings')->nullable();

            $table->boolean('is_active')->default(false);

            // when a lead last actually arrived, not when the row was last
            // saved: the card reads "Last lead received" off this
            $table->dateTime('last_received_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
