<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a customer now removes them for good, with their calls and those calls' follow-ups: there
 * is no bin any more. What is in the bin today goes the same way, then the bin's columns are dropped.
 * (deploy.sh backs the database up before migrating.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $calls = DB::table('calls')
                ->whereNotNull('deleted_at')
                ->orWhereIn('customer_id', DB::table('customers')->whereNotNull('deleted_at')->select('id'))
                ->pluck('id');

            // follow-ups and calls first, whatever the foreign keys would do on their own
            DB::table('follow_ups')->whereIn('call_id', $calls)->delete();
            DB::table('calls')->whereIn('id', $calls)->delete();
            DB::table('customers')->whereNotNull('deleted_at')->delete();
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }

    /** The columns come back empty; what was deleted is gone. */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
