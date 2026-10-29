<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Provenance required on every clinical item: where it came from, who, when, and how verified. */
    private function provenance(Blueprint $table): void
    {
        $table->foreignId('source_facility_id')->constrained('facilities')->restrictOnDelete();
        $table->string('source_system', 50)->nullable(); // e.g. mediflow, portal, other_ehr
        $table->string('source_identifier', 100)->nullable(); // id in the source system when available
        $table->foreignId('author_practitioner_id')->nullable()->constrained('practitioners')->restrictOnDelete();
        $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        $table->dateTime('clinical_date'); // when it happened clinically
        $table->dateTime('ingested_at')->useCurrent(); // when MediPass received it
        $table->string('verification_status', 20)->default('unverified'); // unverified, source_verified, clinician_verified, patient_entered
        $table->string('verification_method', 50)->nullable();
        $table->string('record_status', 20)->default('active'); // active, entered_in_error, superseded
        $table->timestamps();
    }

    /** Original local code and display are always kept; normalized codes are added alongside. */
    private function coding(Blueprint $table, string $prefix = ''): void
    {
        $table->string($prefix.'local_code', 100)->nullable();
        $table->string($prefix.'local_display');
        $table->string($prefix.'code_system', 100)->nullable();
        $table->string($prefix.'code', 100)->nullable();
    }

    private function sourceUnique(Blueprint $table, string $name): void
    {
        $table->unique(['source_facility_id', 'source_identifier'], $name); // duplicate submissions rejected
    }

    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('encounter_class', 20); // outpatient, inpatient, emergency, lab, pharmacy
            $table->dateTime('ended_at')->nullable();
            $table->string('reason')->nullable();
            $this->provenance($table);
            $this->sourceUnique($table, 'encounters_source_unique');
            $table->index(['patient_id', 'clinical_date']);
        });

        Schema::create('conditions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->restrictOnDelete();
            $this->coding($table);
            $table->string('clinical_status', 20)->default('unknown'); // active, resolved, unknown
            $table->date('onset_date')->nullable();
            $table->date('resolved_date')->nullable();
            $this->provenance($table);
            $this->sourceUnique($table, 'conditions_source_unique');
            $table->index(['patient_id', 'clinical_date']);
        });

        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->restrictOnDelete();
            $this->coding($table);
            $table->string('dose_text')->nullable(); // original wording preserved, not parsed
            $table->string('route', 50)->nullable();
            $table->string('frequency_text')->nullable();
            $table->string('medication_status', 20)->default('unknown'); // active, stopped, completed, unknown
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $this->provenance($table);
            $this->sourceUnique($table, 'medications_source_unique');
            $table->index(['patient_id', 'medication_status']);
        });

        Schema::create('allergies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $this->coding($table); // substance
            $table->string('reaction_text')->nullable();
            $table->string('severity', 20)->nullable(); // mild, moderate, severe; null = unknown
            $table->string('allergy_status', 20)->default('unknown'); // active, inactive, unknown
            $this->provenance($table);
            $this->sourceUnique($table, 'allergies_source_unique');
            $table->index(['patient_id', 'allergy_status']);
        });

        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('diagnostic_report_id')->nullable(); // FK added after reports table exists
            $table->string('category', 20); // vital_sign, laboratory, other
            $this->coding($table);
            $table->decimal('value_number', 14, 4)->nullable();
            $table->string('value_text')->nullable();
            $table->string('unit', 30)->nullable(); // unit as supplied; never silently converted
            $table->decimal('reference_low', 14, 4)->nullable();
            $table->decimal('reference_high', 14, 4)->nullable();
            $table->string('interpretation', 20)->nullable();
            $this->provenance($table);
            $this->sourceUnique($table, 'observations_source_unique');
            $table->index(['patient_id', 'category', 'clinical_date']);
        });

        Schema::create('diagnostic_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('report_type', 20); // laboratory, imaging, other
            $this->coding($table);
            $table->text('conclusion')->nullable();
            $table->string('report_status', 20)->default('final'); // preliminary, final, amended
            $this->provenance($table);
            $this->sourceUnique($table, 'diagnostic_reports_source_unique');
            $table->index(['patient_id', 'clinical_date']);
        });

        Schema::table('observations', function (Blueprint $table) {
            $table->foreign('diagnostic_report_id')->references('id')->on('diagnostic_reports')->restrictOnDelete();
        });

        Schema::create('document_references', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_type', 30); // discharge_summary, imaging_report, scan, other
            $table->string('title');
            $table->string('storage_disk', 30)->default('local'); // private storage only
            $table->string('storage_path')->nullable(); // never exposed via public URLs
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('scan_status', 20)->default('pending'); // pending, clean, rejected
            $this->provenance($table);
            $this->sourceUnique($table, 'document_references_source_unique');
            $table->index(['patient_id', 'document_type']);
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('consent_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->string('referral_status', 20)->default('requested'); // requested, accepted, declined, completed, cancelled
            $this->provenance($table);
            $this->sourceUnique($table, 'referrals_source_unique');
            $table->index(['patient_id', 'referral_status']);
            $table->index(['to_facility_id', 'referral_status']);
        });

        // Corrections and disputes never overwrite the original; the snapshot keeps what was shown.
        Schema::create('record_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('record_type', 40);
            $table->unsignedBigInteger('record_id');
            $table->string('kind', 20); // dispute, correction, entered_in_error
            $table->string('correction_status', 20)->default('open'); // open, routed_to_source, accepted, rejected
            $table->json('original_snapshot');
            $table->json('proposed_change')->nullable();
            $table->text('reason');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
            $table->index(['patient_id', 'correction_status']);
        });

        Schema::create('integration_endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('protocol', 20); // fhir_rest, adapter, portal
            $table->string('environment', 20)->default('sandbox'); // sandbox only until approvals
            $table->string('base_url')->nullable();
            $table->string('credential_ref')->nullable(); // reference into the secret store; never the secret
            $table->string('status', 20)->default('disabled');
            $table->timestamps();

            $table->unique(['facility_id', 'name']);
        });

        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('integration_endpoint_id')->constrained()->restrictOnDelete();
            $table->string('direction', 10); // pull, push
            $table->string('idempotency_key', 100);
            $table->string('sync_status', 20)->default('queued'); // queued, running, succeeded, failed, dead_letter
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error_code', 50)->nullable(); // code only; no payload or patient data
            $table->timestamps();

            $table->unique(['integration_endpoint_id', 'idempotency_key']);
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_jobs');
        Schema::dropIfExists('integration_endpoints');
        Schema::dropIfExists('record_corrections');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('document_references');
        Schema::table('observations', function (Blueprint $table) {
            $table->dropForeign(['diagnostic_report_id']);
        });
        Schema::dropIfExists('diagnostic_reports');
        Schema::dropIfExists('observations');
        Schema::dropIfExists('allergies');
        Schema::dropIfExists('medications');
        Schema::dropIfExists('conditions');
        Schema::dropIfExists('encounters');
    }
};
