<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An optional monthly uptime target per service (STAT-39).
     *
     * Nullable rather than defaulted to 99.9: a service with no agreed target should read
     * as having no target, not as failing one nobody set.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->decimal('uptime_target', 5, 2)->nullable()->after('degraded_threshold_ms');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('uptime_target');
        });
    }
};
