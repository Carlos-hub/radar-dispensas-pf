<?php

namespace App\Models;

use App\Enums\StatusCandidatura;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candidatura extends Model
{
    use HasFactory;

    public const NOTAS_MAX = 5000;

    protected $fillable = ['user_id', 'dispensa_id', 'status', 'notas'];

    protected $attributes = ['status' => 'salva', 'notas' => ''];

    protected function casts(): array
    {
        return ['status' => StatusCandidatura::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dispensa(): BelongsTo
    {
        return $this->belongsTo(Dispensa::class);
    }
}
