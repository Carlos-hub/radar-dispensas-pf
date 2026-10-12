<?php

namespace App\Enums;

enum StatusCandidatura: string
{
    case Salva = 'salva';
    case Enviada = 'enviada';
    case Ganhei = 'ganhei';
    case Perdi = 'perdi';
    case Descartada = 'descartada';

    public function label(): string
    {
        return match ($this) {
            self::Salva => 'Salva',
            self::Enviada => 'Proposta enviada',
            self::Ganhei => 'Ganhei',
            self::Perdi => 'Perdi',
            self::Descartada => 'Descartada',
        };
    }
}
