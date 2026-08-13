<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user, per-service alert subscriptions (STAT-42).
     *
     * STAT-24 stopped at a single boolean, so you receive every transition for every
     * service or none at all.
     *
     * Deliberately not backfilled. No rows for a user means every service, so existing
     * behaviour carries over untouched, and a service added next month is covered without
     * anyone editing their preferences. Narrowing is opt-in.
     */
    public function up(): void
    {
        Schema::create('service_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_subscriptions');
    }
};
