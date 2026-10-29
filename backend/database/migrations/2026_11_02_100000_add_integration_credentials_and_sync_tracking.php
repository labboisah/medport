<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYNCED = ['encounters', 'conditions', 'medications', 'allergies', 'observations', 'diagnostic_reports'];

    public function up(): void
    {
        Schema::table('integration_endpoints', function (Blueprint $table) {
            $table->char('api_key_hash', 64)->nullable()->unique(); // SHA-256 of a high-entropy key; the key itself is shown once
            $table->json('scopes')->nullable(); // e.g. ["write:observations"]
            $table->timestamp('key_expires_at')->nullable();
        });

        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->char('payload_hash', 64)->nullable(); // detects an idempotency key reused with a different body
            $table->unsignedInteger('received_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_stale_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->json('result')->nullable(); // counts and per-item error codes only; never clinical data
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
        });

        foreach (self::SYNCED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dateTime('source_updated_at')->nullable(); // source system's own last-modified time; stale updates never overwrite newer data
            });
        }
    }

    public function down(): void
    {
        foreach (self::SYNCED as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('source_updated_at'));
        }
        Schema::table('sync_jobs', fn (Blueprint $table) => $table->dropColumn(['payload_hash', 'received_count', 'created_count', 'updated_count', 'skipped_stale_count', 'rejected_count', 'result', 'next_retry_at', 'dead_lettered_at']));
        Schema::table('integration_endpoints', function (Blueprint $table) {
            $table->dropUnique(['api_key_hash']);
            $table->dropColumn(['api_key_hash', 'scopes', 'key_expires_at']);
        });
    }
};
