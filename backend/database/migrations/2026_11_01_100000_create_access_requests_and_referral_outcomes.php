<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A provider asks; only the patient's decision creates a consent. A request itself grants nothing.
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('practitioner_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 30)->default('direct_care');
            $table->json('categories');
            $table->string('reason', 300);
            $table->unsignedSmallInteger('requested_hours');
            $table->string('status', 20)->default('pending'); // pending, approved, denied, expired
            $table->foreignId('consent_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at'); // an unanswered request lapses
            $table->timestamps();
            $table->index(['patient_id', 'status']);
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->text('outcome_text')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['decided_by_user_id']);
            $table->dropColumn(['outcome_text', 'decided_at', 'decided_by_user_id']);
        });
        Schema::dropIfExists('access_requests');
    }
};
