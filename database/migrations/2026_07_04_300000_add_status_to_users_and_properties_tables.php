<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('Active'); // Active, Suspended, Pending Verification
            }
        });

        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'status')) {
                $table->string('status')->default('Active'); // Active, Pending, Removed
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'status')) {
                $table->dropColumn('status');
            }
        });

        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
