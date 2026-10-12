<?php

namespace App\Models;

use App\Enums\Fit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dispensa extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'categorias' => 'array',
            'fit' => Fit::class,
            'valor' => 'float',
            'abre_em' => 'datetime',
            'encerra_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }

    /** Com prazo em aberto e devolvidas pelo PNCP nas últimas 24 horas. */
    public function scopeAbertas(Builder $query): void
    {
        $query
            ->where(fn (Builder $q) => $q->whereNull('encerra_em')->orWhere('encerra_em', '>', now()))
            ->where('sincronizado_em', '>=', now()->subDay());
    }

    public function encerrada(): bool
    {
        return $this->encerra_em !== null && $this->encerra_em->isPast();
    }

    /** @return array{txt: string, cls: string} */
    public function prazo(): array
    {
        if ($this->encerra_em === null) {
            return ['txt' => 'sem prazo', 'cls' => ''];
        }
        $data = $this->encerra_em->format('d/m H:i');
        $horas = now()->diffInHours($this->encerra_em, false);

        return match (true) {
            $horas <= 0 => ['txt' => "encerrou $data", 'cls' => 'encerrada'],
            $horas < 48 => ['txt' => "encerra $data (" . (int) round($horas) . 'h)', 'cls' => 'urgente'],
            default => ['txt' => "encerra $data (" . (int) round($horas / 24) . ' dias)', 'cls' => ''],
        };
    }

    public function valorTexto(): string
    {
        return $this->valor ? 'R$ ' . number_format($this->valor, 2, ',', '.') : 'valor sigiloso';
    }

    public function urlPncp(): ?string
    {
        return self::urlSegura($this->link_pncp);
    }

    public function urlOrigem(): ?string
    {
        return self::urlSegura($this->link_origem);
    }

    /** Links vêm do PNCP: só são exibidos se forem http ou https. */
    private static function urlSegura(?string $url): ?string
    {
        return $url !== null && preg_match('#^https?://#i', $url) ? $url : null;
    }
}
