<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_working_hours', function (Blueprint $table) {
            if (! Schema::hasColumn('appointment_working_hours', 'slot_interval_minutes')) {
                $table->unsignedSmallInteger('slot_interval_minutes')->nullable()->after('end_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointment_working_hours', function (Blueprint $table) {
            if (Schema::hasColumn('appointment_working_hours', 'slot_interval_minutes')) {
                $table->dropColumn('slot_interval_minutes');
            }
        });
    }
};

