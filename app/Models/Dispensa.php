<?php

namespace App\Models;

use App\Enums\Fit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Carbon\Exceptions\InvalidFormatException;

class Dispensa extends Model
{
    use HasFactory;

    /** Chave do cache com a data da última sincronização bem-sucedida (gravada por pncp:sync). */
    public const CHAVE_ULTIMA_SINCRONIZACAO = 'pncp.ultima_sincronizacao';

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

    /**
     * Com prazo em aberto e devolvidas pelo PNCP até 24 horas antes da última sincronização bem-sucedida.
     * A janela conta dessa sincronização, não do relógio: se o PNCP ficar fora do ar por dias, a lista não esvazia.
     * Sem nenhuma sincronização registrada, conta do relógio.
     */
    public function scopeAbertas(Builder $query): void
    {
        $query
            ->where(fn (Builder $q) => $q->whereNull('encerra_em')->orWhere('encerra_em', '>', now()))
            ->where('sincronizado_em', '>=', self::referenciaDaJanela()->subDay());
    }

    private static function referenciaDaJanela(): Carbon
    {
        $ultima = Cache::get(self::CHAVE_ULTIMA_SINCRONIZACAO);
        try {
            return $ultima ? Carbon::parse($ultima) : now();
        } catch (InvalidFormatException) {
            return now();
        }
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
