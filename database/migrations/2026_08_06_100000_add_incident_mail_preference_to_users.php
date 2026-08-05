<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt in to incident mail (STAT-24).
     *
     * Defaults to false, which is the safe direction: since STAT-7 users are ID SSO
     * shadow copies created on first login, so anyone who ever signs in to status would
     * otherwise start receiving every outage email for every service without asking.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wants_incident_mail')->default(false)->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wants_incident_mail');
        });
    }
};
