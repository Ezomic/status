<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Human-written updates on an incident (STAT-34).
     *
     * Incidents are otherwise entirely machine-written, so there was nowhere to record
     * that someone looked, what they found, or what they did. Posting updates during an
     * outage is the main thing a status page does that this one could not.
     */
    public function up(): void
    {
        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            // Nullable so an update survives its author being deleted: the record of what
            // happened during an outage outlives whoever happened to write it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            // Opt in, like Service::$is_public. An update is internal until someone
            // decides a reader should see it.
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            // Acknowledging is the cheap version of the same idea: record that someone is
            // looking, so an open incident can read as handled rather than merely open.
            $table->timestamp('acknowledged_at')->nullable()->after('resolved_at');
            $table->foreignId('acknowledged_by_id')->nullable()->after('acknowledged_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['acknowledged_by_id']);
            $table->dropColumn(['acknowledged_at', 'acknowledged_by_id']);
        });

        Schema::dropIfExists('incident_updates');
    }
};
