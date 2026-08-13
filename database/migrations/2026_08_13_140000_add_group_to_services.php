<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A group label per service (STAT-43).
     *
     * A plain string rather than its own table: a group here is a heading, with no
     * attributes of its own and nothing to join to. A table would buy referential
     * integrity over a label nobody references.
     *
     * Nullable, so existing services keep working and fall into an ungrouped section
     * rather than disappearing.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('group')->nullable()->after('name');
            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['group']);
            $table->dropColumn('group');
        });
    }
};
