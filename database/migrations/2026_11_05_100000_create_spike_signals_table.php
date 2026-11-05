<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spike_signals', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('key', 191);
            $table->string('state', 10);
            $table->unsignedBigInteger('application_id');
            $table->string('application_name');
            $table->string('route')->nullable();
            $table->unsignedInteger('window_minutes');
            $table->unsignedInteger('count');
            $table->double('baseline_per_window');
            $table->double('threshold');
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['source', 'key']);
            $table->index(['state', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spike_signals');
    }
};
