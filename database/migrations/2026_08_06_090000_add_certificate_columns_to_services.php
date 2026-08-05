<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TLS expiry per service (STAT-23). Certificates come from certbot on the shared
     * droplet, and today the only signal a renewal failed is a hard outage after the
     * cert lapses, arriving as a generic cURL error in an incident reason.
     *
     * certificate_checked_at is stored alongside so a failed lookup is distinguishable
     * from a service that was never inspected: on failure the expiry is nulled, which
     * reads as unknown rather than as an imminent expiry.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('certificate_expires_at')->nullable()->after('last_response_time_ms');
            $table->timestamp('certificate_checked_at')->nullable()->after('certificate_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['certificate_expires_at', 'certificate_checked_at']);
        });
    }
};
