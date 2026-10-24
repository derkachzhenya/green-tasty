<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->char('email_hash', 64);
            $table->string('purpose', 20);
            $table->char('generation', 32);
            $table->char('code_hash', 64)->nullable();
            $table->char('password_fingerprint', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('resend_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['email_hash', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_challenges');
    }
};
