<?php

namespace App\Policies;

use App\Models\Candidatura;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CandidaturaPolicy
{
    public function update(User $user, Candidatura $candidatura): Response
    {
        return $this->dono($user, $candidatura);
    }

    public function delete(User $user, Candidatura $candidatura): Response
    {
        return $this->dono($user, $candidatura);
    }

    /** Responde 404 para não confirmar a quem não é dono que a candidatura existe. */
    private function dono(User $user, Candidatura $candidatura): Response
    {
        return $user->id === $candidatura->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
