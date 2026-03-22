<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('title')->after('name');
            $table->string('first_name')->after('title');
            $table->string('last_name')->after('first_name');
            $table->string('address')->after('last_name');
            $table->string('phone_number')->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['title', 'first_name', 'last_name', 'address', 'phone_number']);
        });
    }
};
