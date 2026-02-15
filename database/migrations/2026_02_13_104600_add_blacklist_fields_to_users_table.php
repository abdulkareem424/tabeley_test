<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('strike_count')->default(0)->after('password');
            $table->timestamp('blocked_until')->nullable()->after('strike_count');
            $table->boolean('blocked_permanent')->default(false)->after('blocked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['strike_count', 'blocked_until', 'blocked_permanent']);
        });
    }
};
