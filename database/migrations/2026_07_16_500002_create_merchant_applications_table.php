<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_applications', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 150);
            $table->string('owner_name', 100);
            $table->string('email', 100)->index();
            $table->string('phone', 30);
            $table->string('city', 100)->nullable();
            $table->string('business_type', 100)->nullable();
            $table->unsignedSmallInteger('branch_count')->nullable();
            $table->unsignedInteger('estimated_monthly_deliveries')->nullable();
            $table->string('selected_plan', 50)->nullable();
            $table->text('notes')->nullable();
            // pending | approved | rejected | cancelled | converted
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_applications');
    }
};
