<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('mobile_number');
            $table->string('email')->nullable();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('broker_name')->nullable();
            $table->string('stage')->default('fresh');
            $table->dateTime('stage_changed_at')->nullable();
            $table->unsignedTinyInteger('not_connected_count')->default(0);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_role')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requirement')->nullable();
            $table->string('reason')->nullable();
            $table->string('booked_unit')->nullable();
            $table->date('booking_date')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['mobile_number', 'project_id']);
            $table->index(['assigned_to', 'stage']);
            $table->index(['stage', 'stage_changed_at']);
            $table->index(['source', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
