<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-service request shape (STAT-40).
     *
     * HttpProbe always issued a GET with no headers. That is fine for a public root and
     * insufficient for anything else: HEAD would answer the same question far more
     * cheaply, and an endpoint behind a token could not be checked at all.
     *
     * headers is encrypted at rest. An Authorization value is the obvious thing to put
     * here, which means this column turns the services table into a place secrets live.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('http_method', 10)->default('GET')->after('url');
            $table->text('headers')->nullable()->after('expected_body');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['http_method', 'headers']);
        });
    }
};
