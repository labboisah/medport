<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_inbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('dedupe_key', 100)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->boolean('in_app')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'event']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('category', 40);
            $table->string('severity', 10);
            $table->string('status', 20)->default('open');
            $table->string('title', 150);
            $table->text('summary')->nullable();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('policy_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->unsignedInteger('value')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_settings');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications_inbox');
    }
};
