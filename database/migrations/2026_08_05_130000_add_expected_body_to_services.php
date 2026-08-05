<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional substring the response body must contain (STAT-22). A status code alone
     * only answers "did a web server respond": a Laravel app with an unreachable
     * database still returns 200 on its root, and so does a vhost pointing at the
     * wrong document root. Null keeps the check behaving exactly as it did.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('expected_body')->nullable()->after('expected_status_code');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('expected_body');
        });
    }
};
