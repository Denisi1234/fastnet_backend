<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'commission_rate')) {
                $table->decimal('commission_rate', 5, 2)->default(10.00)->after('total_price');
            }
            if (!Schema::hasColumn('bookings', 'platform_fee')) {
                $table->decimal('platform_fee', 12, 2)->nullable()->after('commission_rate');
            }
            if (!Schema::hasColumn('bookings', 'owner_payout')) {
                $table->decimal('owner_payout', 12, 2)->nullable()->after('platform_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'commission_rate')) {
                $table->dropColumn('commission_rate');
            }
            if (Schema::hasColumn('bookings', 'platform_fee')) {
                $table->dropColumn('platform_fee');
            }
            if (Schema::hasColumn('bookings', 'owner_payout')) {
                $table->dropColumn('owner_payout');
            }
        });
    }
};
