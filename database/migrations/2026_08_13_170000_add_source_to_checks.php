<?php

declare(strict_types=1);

use App\Enums\CheckSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each check was taken from (STAT-45).
     *
     * No backfill: every check so far was taken by monitor:run on the droplet, so the
     * column default is already the truth for all 142k existing rows. Filling them in
     * explicitly would rewrite the whole table to write the value they already have.
     */
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->string('source')->default(CheckSource::Internal->value)->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
