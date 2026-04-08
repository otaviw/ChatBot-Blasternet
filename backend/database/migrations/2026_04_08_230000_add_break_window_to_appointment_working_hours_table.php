<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_working_hours', function (Blueprint $table) {
            if (! Schema::hasColumn('appointment_working_hours', 'break_start_time')) {
                $table->time('break_start_time')->nullable()->after('start_time');
            }
            if (! Schema::hasColumn('appointment_working_hours', 'break_end_time')) {
                $table->time('break_end_time')->nullable()->after('break_start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointment_working_hours', function (Blueprint $table) {
            if (Schema::hasColumn('appointment_working_hours', 'break_end_time')) {
                $table->dropColumn('break_end_time');
            }
            if (Schema::hasColumn('appointment_working_hours', 'break_start_time')) {
                $table->dropColumn('break_start_time');
            }
        });
    }
};

