<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->unsignedSmallInteger('expected_status_code')->default(200);
            $table->unsignedInteger('interval_seconds')->default(60);
            // 15s, not 5s: a burst of concurrent cold TLS handshakes routinely pushes a
            // healthy host past 5s, which reports a false outage.
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->unsignedInteger('degraded_threshold_ms')->default(2000);
            $table->boolean('is_active')->default(true);

            // Denormalised from the latest check so the list view does not need a
            // correlated "latest check per service" subquery. Written only by RecordCheck.
            $table->string('current_state', 10)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_response_time_ms')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
