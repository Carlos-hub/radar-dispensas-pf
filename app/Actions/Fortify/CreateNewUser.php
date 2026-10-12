<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public const LIMITE_POR_HORA = 10;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $chave = 'cadastro:' . request()->ip();
        if (RateLimiter::tooManyAttempts($chave, self::LIMITE_POR_HORA)) {
            throw ValidationException::withMessages([
                'email' => 'Muitos cadastros a partir deste endereço. Tente de novo mais tarde.',
            ]);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ])->validate();

        RateLimiter::hit($chave, 3600);

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
    }
}
