<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-property cancellation policy.
 *
 * BookingCalculationService previously emitted "Free cancellation before
 * {check-in}" on every quote regardless of what the lodge had actually agreed
 * to. That text reached the guest on the checkout page and the receipt, so a
 * property could be held to a promise it never made - or be denied a promise
 * it did. The policy now comes from this column and is null when unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (! Schema::hasColumn('properties', 'cancellation_policy')) {
                $table->text('cancellation_policy')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'cancellation_policy')) {
                $table->dropColumn('cancellation_policy');
            }
        });
    }
};
