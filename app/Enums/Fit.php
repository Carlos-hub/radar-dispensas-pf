<?php

namespace App\Enums;

enum Fit: string
{
    case Alta = 'alta';
    case Verificar = 'verificar';
    case Baixa = 'baixa';
    case Fora = 'fora';

    public function label(): string
    {
        return match ($this) {
            self::Alta => 'Fit PF',
            self::Verificar => 'Verificar',
            self::Baixa => 'Baixa',
            self::Fora => 'Fora do perfil',
        };
    }
}
