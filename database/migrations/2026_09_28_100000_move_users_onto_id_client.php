<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status signs in through id-client since STAT-53, which matches a returning user on
 * idp_id where status's own controller used id_sub. id_sub itself stays, so rolling back
 * to the release before this one still finds everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $subjects = DB::table('users')
            ->whereNotNull('id_sub')
            ->whereNull('idp_id')
            ->pluck('id_sub', 'id');

        foreach ($subjects as $id => $subject) {
            DB::table('users')->where('id', $id)->update(['idp_id' => $subject]);
        }

        // Cookies from before STAT-52 were never reached by an ID sign-out, and honouring
        // them now would restore sessions for people who have since signed out at ID or
        // lost access to Status. Without a token no cookie matches.
        DB::table('users')->update(['remember_token' => null]);
    }
};
