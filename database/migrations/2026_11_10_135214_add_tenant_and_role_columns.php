<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('Instance') && !Schema::hasColumn('Instance', 'user_id')) {
            Schema::table('Instance', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
                $table->string('tenant_id', 64)->nullable()->after('user_id')->index();
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 32)->default('client')->after('email');
                $table->string('status', 32)->default('active')->after('role'); // active, suspended, banned
                $table->string('plan', 64)->default('free')->after('status');
                $table->integer('max_instances')->default(1)->after('plan');
                $table->string('suspended_reason')->nullable()->after('max_instances');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('Instance') && Schema::hasColumn('Instance', 'user_id')) {
            Schema::table('Instance', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn(['user_id', 'tenant_id']);
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['role', 'status', 'plan', 'max_instances', 'suspended_reason']);
            });
        }
    }
};
