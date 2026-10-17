<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensas', function (Blueprint $table) {
            // preenchidas pelo próximo pncp:sync, que reclassifica todas as abertas
            $table->text('edital_status')->default('neutro')->index();
            $table->text('edital_motivo')->default('');
            $table->boolean('revisar_misto')->default(false);
            $table->jsonb('barreiras')->nullable();   // null: só havia o objeto, barreiras não verificadas
            $table->text('perfil')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispensas', function (Blueprint $table) {
            $table->dropColumn(['edital_status', 'edital_motivo', 'revisar_misto', 'barreiras', 'perfil']);
        });
    }
};
