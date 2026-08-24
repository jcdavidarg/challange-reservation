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
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->enum('location', ['A', 'B', 'C', 'D']);
            $table->unsignedSmallInteger('number');
            $table->unsignedSmallInteger('capacity');
            $table->timestamps();

            $table->unique(['location', 'number']);
            $table->index(['location', 'capacity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
