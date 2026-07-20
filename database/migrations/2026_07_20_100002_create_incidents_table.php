<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 10);
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('reason');
            $table->timestamps();

            $table->index(['service_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
