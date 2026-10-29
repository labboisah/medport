<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type', 20)->default('patient')->after('password'); // patient, practitioner, admin
            $table->string('status', 20)->default('active')->after('account_type'); // active, disabled
            $table->timestamp('mfa_enrolled_at')->nullable()->after('status');
            $table->unsignedSmallInteger('failed_login_count')->default(0)->after('mfa_enrolled_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_count');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'status', 'mfa_enrolled_at', 'failed_login_count', 'locked_until']);
        });
    }
};
