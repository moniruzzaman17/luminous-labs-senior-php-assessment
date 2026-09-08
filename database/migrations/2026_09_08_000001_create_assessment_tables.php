<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_id', 100)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('provider_event_id', 100)->charset('ascii')->collation('ascii_bin')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->dateTime('paid_at');
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id', 100)->unique();
            $table->string('source', 50);
            $table->string('import_batch', 100);
            $table->string('raw_shipment_date', 50)->nullable();
            $table->date('shipment_date')->nullable();
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->dateTime('starts_at')->index();
            $table->string('location')->nullable();
            $table->boolean('is_public')->default(false)->index();
            $table->dateTime('published_at')->nullable()->index();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('orders');
    }
};
