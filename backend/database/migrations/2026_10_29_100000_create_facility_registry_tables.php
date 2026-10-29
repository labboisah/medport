<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type', 20); // hospital, clinic, lab, pharmacy, specialist
            $table->string('state')->nullable();
            $table->string('lga')->nullable();
            $table->string('integration_path', 30)->default('portal'); // mediflow, other_ehr, portal
            $table->string('verification_status', 20)->default('pending'); // pending, verified, suspended
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['verification_status', 'type']);
        });

        Schema::create('practitioners', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('given_name');
            $table->string('family_name');
            $table->string('license_body')->nullable();
            $table->string('license_number')->nullable();
            $table->string('verification_status', 20)->default('pending'); // pending, verified, suspended
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['license_body', 'license_number']);
        });

        Schema::create('practitioner_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('practitioner_id')->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('role', 30); // physician, nurse, lab_scientist, pharmacist, records_officer, facility_admin
            $table->string('status', 20)->default('active'); // active, suspended, ended
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['practitioner_id', 'facility_id', 'role']);
            $table->index(['facility_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_roles');
        Schema::dropIfExists('practitioners');
        Schema::dropIfExists('facilities');
    }
};
