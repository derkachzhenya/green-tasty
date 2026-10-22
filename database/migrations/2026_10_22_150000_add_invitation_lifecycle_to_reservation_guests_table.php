<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('invitation_revoked_at')->nullable();
        });

        // Legacy tokens have no guaranteed hashing or expiry; owners must reissue them.
        DB::table('reservation_guests')->whereNotNull('invitation_token')->update(['invitation_token' => null]);
    }

    public function down(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->dropColumn(['invitation_expires_at', 'invitation_revoked_at']);
        });
    }
};
