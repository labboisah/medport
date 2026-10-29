<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('given_name');
            $table->string('family_name');
            $table->date('birth_date')->nullable();
            $table->string('sex', 10)->nullable(); // unknown values stay null, never inferred
            $table->string('status', 20)->default('active'); // active, merged, inactive
            $table->foreignId('merged_into_patient_id')->nullable()->constrained('patients')->restrictOnDelete();
            $table->string('identity_verification', 20)->default('unverified'); // unverified, pending, verified
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamps();

            $table->index(['family_name', 'given_name', 'birth_date']);
        });

        Schema::create('patient_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('system'); // e.g. national_id, phone, facility MRN namespace
            $table->string('value');
            $table->foreignId('assigning_facility_id')->nullable()->constrained('facilities')->restrictOnDelete();
            $table->string('verification_status', 20)->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['system', 'value']);
            $table->index('patient_id');
        });

        // Uncertain matches are stored for human review; nothing is merged automatically.
        Schema::create('patient_match_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('candidate_patient_id')->constrained('patients')->restrictOnDelete();
            $table->decimal('score', 5, 4);
            $table->json('reasons');
            $table->string('status', 20)->default('pending_review'); // pending_review, confirmed_same, rejected
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'candidate_patient_id']);
            $table->index('status');
        });

        // Append-only history of merges and unmerges.
        Schema::create('patient_merge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surviving_patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('merged_patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('action', 10); // merge, unmerge
            $table->foreignId('match_candidate_id')->nullable()->constrained('patient_match_candidates')->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->index('surviving_patient_id');
            $table->index('merged_patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_merge_events');
        Schema::dropIfExists('patient_match_candidates');
        Schema::dropIfExists('patient_identifiers');
        Schema::dropIfExists('patients');
    }
};
