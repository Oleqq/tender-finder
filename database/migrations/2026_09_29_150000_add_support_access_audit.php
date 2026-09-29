<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_events', function (Blueprint $table): void {
            $table->foreignId('access_entitlement_id')->nullable()->constrained('entitlements')->nullOnDelete();
            $table->string('access_plan_code', 24)->nullable();
            $table->timestamp('access_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('access_entitlement_id');
            $table->dropColumn(['access_plan_code', 'access_ends_at']);
        });
    }
};
