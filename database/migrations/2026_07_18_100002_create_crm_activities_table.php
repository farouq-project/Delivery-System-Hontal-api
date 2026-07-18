<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prospect_id');
            $table->foreign('prospect_id')->references('id')->on('crm_prospects')->cascadeOnDelete();
            $table->string('type', 30)->default('note');
            $table->text('content');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['prospect_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
