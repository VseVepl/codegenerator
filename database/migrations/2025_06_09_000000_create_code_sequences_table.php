<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        // Creates the 'code_sequences' table to manage unique sequential IDs.
        // This table uses a combination of 'date', 'type', and 'location'
        // to define a unique counter for each specific code segment.
        Schema::create('code_sequences', function (Blueprint $table) {
            $table->id(); // Auto-incrementing primary key

            // 'date' column: Stores the date component that the sequence is unique to.
            // This allows sequences to reset daily, monthly, or yearly based on pattern configuration.
            // Example values: '2025-06-09', '2025-06', '2025'.
            $table->string('date', 20);

            // 'type' column: Stores the code's type prefix (e.g., 'ORD', 'INV').
            // Part of the composite unique key.
            $table->string('type', 20);

            // 'location' column: Stores the code's location (e.g., 'HQ', 'MUM').
            // Part of the composite unique key.
            $table->string('location', 20);

            // 'sequence' column: The last *confirmed* and *used* sequence number.
            // This is updated when `confirmUsage()` is called.
            $table->bigInteger('sequence')->unsigned()->default(0);

            // 'pending_sequence' column: The last *generated and reserved* sequence number.
            // This holds a number that has been generated but not yet confirmed as used.
            // Allows conservative sequence management and helps in retries.
            $table->bigInteger('pending_sequence')->unsigned()->nullable();

            $table->timestamps(); // Adds 'created_at' and 'updated_at' columns

            // Unique composite index: Ensures that for any given date, type, and location,
            // there is only one sequence counter. This is critical for correctness.
            $table->unique(['date', 'type', 'location']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('code_sequences');
    }
};
