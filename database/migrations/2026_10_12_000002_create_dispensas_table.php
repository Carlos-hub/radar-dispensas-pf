<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensas', function (Blueprint $table) {
            $table->id();
            $table->text('pncp_id')->unique();
            $table->text('objeto');
            $table->text('complemento')->default('');
            $table->text('orgao')->default('');
            $table->text('unidade')->default('');
            $table->text('municipio')->default('');
            $table->string('uf', 2)->default('')->index();
            $table->text('esfera')->default('');
            $table->decimal('valor', 15, 2)->nullable();
            $table->timestamp('abre_em')->nullable();
            $table->timestamp('encerra_em')->nullable()->index();
            $table->text('amparo')->default('');
            $table->text('disputa')->default('');
            $table->text('link_pncp')->nullable();
            $table->text('link_origem')->nullable();
            $table->jsonb('categorias');
            $table->text('fit')->index();
            $table->text('motivo')->default('');
            $table->timestamp('sincronizado_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensas');
    }
};
