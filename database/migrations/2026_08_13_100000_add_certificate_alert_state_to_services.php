<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was last alerted about this certificate, so the daily refresh can tell a
     * transition from a state that simply persists (STAT-38). Null means nothing has been
     * alerted, which is also how a renewed certificate re-arms.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('certificate_alerted', 20)->nullable()->after('certificate_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('certificate_alerted');
        });
    }
};
