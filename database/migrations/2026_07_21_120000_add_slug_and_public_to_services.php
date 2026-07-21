<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // A stable, non-sensitive identifier other apps (the id portal, ID-13)
            // can join on without ever seeing the service's URL or host.
            $table->string('slug')->nullable()->unique()->after('name');

            // Opt-in: only public services appear on the machine-readable status
            // endpoint and (later) the public status page.
            $table->boolean('is_public')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'is_public']);
        });
    }
};
