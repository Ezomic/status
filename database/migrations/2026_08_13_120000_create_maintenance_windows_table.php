<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declared maintenance, as opposed to the detected kind (STAT-37).
     *
     * STAT-18 recognises a live 503 carrying Retry-After as a deploy in progress, which
     * covers `artisan down` and nothing else. It cannot cover planned work where the app
     * keeps answering 200, which is most planned work, and it cannot say anything ahead of
     * time. A declared window can do both.
     */
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // Shown to readers on the public page, so it is written for them rather than
            // for an operator.
            $table->string('description');
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });

        // Many to many: planned work usually touches more than one app, and one app can
        // have several windows scheduled.
        Schema::create('maintenance_window_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_window_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            $table->unique(['maintenance_window_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_window_service');
        Schema::dropIfExists('maintenance_windows');
    }
};
