<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('recipient_practitioner_id')->nullable()->constrained('practitioners')->restrictOnDelete();
            $table->string('purpose', 30)->default('direct_care'); // direct_care kept separate from secondary use
            $table->json('categories'); // approved dataset categories shared
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('consent_text_version', 30);
            $table->string('method', 20); // in_app, assisted, paper
            $table->string('status', 20)->default('active'); // active, revoked, expired
            $table->timestamp('granted_at');
            $table->timestamp('expires_at'); // sharing is always time-limited
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'status', 'expires_at']);
            $table->index(['recipient_facility_id', 'status']);
        });

        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('consent_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('practitioner_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20)->default('consent'); // consent, emergency (disabled until approved)
            $table->json('scope');
            $table->string('status', 20)->default('active'); // active, revoked, expired
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'facility_id', 'status', 'expires_at']);
            $table->index('practitioner_id');
        });

        // Append-only. No foreign keys so audit history survives any later change to subject rows.
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('actor_type', 20); // user, system, integration
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('facility_id')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('resource_type', 50);
            $table->string('resource_id', 64)->nullable();
            $table->string('action', 30); // read, create, update, share, revoke, deny, ...
            $table->string('purpose', 30)->nullable();
            $table->string('outcome', 10); // success, denied, error
            $table->string('authorization_basis', 100)->nullable();
            $table->boolean('emergency')->default(false);
            $table->string('correlation_id', 64)->nullable();

            $table->index(['patient_id', 'occurred_at']);
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
            $table->index(['facility_id', 'occurred_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('access_grants');
        Schema::dropIfExists('consents');
    }
};
