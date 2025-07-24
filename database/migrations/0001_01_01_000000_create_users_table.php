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
        Schema::create('user', function (Blueprint $table) {
            $table->integer('no')->primary();
            $table->string('username', 100);
            $table->string('password', 50);
            $table->enum('category', ['cabang', 'mitra', 'member'])->default('member');
            $table->string('replika', 50);
            $table->string('referral', 100);
            $table->string('name', 100);
            $table->string('subdomain', 255);
            $table->string('link', 100);
            $table->string('number_id', 50);
            $table->string('birth', 100);
            $table->enum('sex', ['L', 'P'])->default('L');
            $table->text('address');
            $table->string('city', 50);
            $table->string('phone', 50);
            $table->string('email', 100);
            $table->string('bank_name', 50);
            $table->string('bank_branch', 50);
            $table->string('bank_account_number', 50);
            $table->string('bank_account_name', 100);
            $table->datetime('last_login');
            $table->string('last_ipaddress', 50);
            $table->string('picture', 200);
            $table->datetime('date');
            $table->enum('publish', ['1', '0'])->default('0');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('user_id')->nullable()->index();
            $table->foreign('user_id')->references('no')->on('user')->onDelete('cascade');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user');
    }
};