<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_time_ms');
            $table->boolean('ok');
            $table->string('state', 10);
            $table->string('error')->nullable();
            $table->timestamp('checked_at');

            // No timestamps(): checked_at is the truth, and this is the only table
            // with real row volume.
            $table->index(['service_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
