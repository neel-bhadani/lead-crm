<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A WhatsApp message somebody wrote once and sends many times.
     *
     * `body` is written with NAMED placeholders — {lead_name}, {project} — which
     * is the only form a human can read and edit. Meta will not accept that: a
     * submitted template has to carry numbered placeholders, {{1}} and {{2}}, in
     * the order they appear. `placeholder_map` is the bridge, stored at save
     * time rather than worked out at submission time: ["lead_name","project"]
     * means {{1}} is the lead's name and {{2}} the project. Adding it later
     * would mean rewriting every template that had already been submitted, and
     * guessing the order of the ones that had not.
     *
     * `meta_template_name` and `approval_status` are Meta's side of the same
     * template and stay `null`/`draft` until somebody actually submits it. The
     * application never blocks on them — click-to-send needs no approval at all.
     */
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // marketing | utility | authentication — utility costs roughly an
            // eighth of marketing, so the choice is the admin's and is shown
            $table->string('category')->default('utility');
            $table->text('body');
            $table->json('placeholder_map')->nullable();
            $table->string('meta_template_name')->nullable();
            // draft | pending | approved | rejected
            $table->string('approval_status')->default('draft');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
