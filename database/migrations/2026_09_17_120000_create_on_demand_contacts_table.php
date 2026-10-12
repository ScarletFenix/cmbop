<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('on_demand_contacts')) {
            return;
        }

        Schema::create('on_demand_contacts', function (Blueprint $table) {
            $table->id();
            $table->text('site_url');
            $table->text('email');
            $table->text('whatsapp');
            $table->text('contacted_via_email');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_demand_contacts');
    }
};
