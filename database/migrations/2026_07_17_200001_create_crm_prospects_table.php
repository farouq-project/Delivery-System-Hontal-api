<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_prospects', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->string('category', 50)->nullable();   // water/catering/bakery/frozen/egg/wholesale/other
            $table->string('city', 100)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('instagram', 100)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_role', 80)->nullable();
            $table->string('pipeline_stage', 30)->default('new'); // new/contacted/demo_scheduled/negotiation/won/lost
            $table->text('notes')->nullable();
            $table->date('last_contact_at')->nullable();
            $table->date('next_followup_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('pipeline_stage');
            $table->index('next_followup_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_prospects');
    }
};
