<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hontal_kirim_batches', function (Blueprint $table) {
            $table->id();
            // Fixed :00/:30 intervals — always on a 30-min boundary
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->enum('status', ['open', 'closed', 'dispatched'])->default('open');
            $table->timestamp('closed_at')->nullable();
            // null = auto-closed by cron; non-null = manually closed by dispatcher
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('window_start');
            $table->unique('window_start'); // one batch per 30-min slot
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hontal_kirim_batches');
    }
};
