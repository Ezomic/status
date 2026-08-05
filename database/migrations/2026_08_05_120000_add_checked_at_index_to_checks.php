<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The table already has (service_id, checked_at), which covers the per-service
     * queries on the detail page. It cannot serve the three scans that filter on
     * checked_at alone, though: SQLite will not use a composite index for a range
     * over a column that is not the leading one, so BuildUptimeStrip,
     * BuildResponseSparklines and Check::prunable() were all full-scanning a table
     * sized for roughly 1.2 million rows.
     */
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropIndex(['checked_at']);
        });
    }
};
