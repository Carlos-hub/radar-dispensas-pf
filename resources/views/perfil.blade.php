@extends('layouts.app')
@section('title', 'Meu perfil · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel largo">
  <h1>Meu perfil</h1>

  <h2>Dados</h2>
  <form method="POST" action="{{ route('user-profile-information.update') }}">
    @csrf @method('PUT')
    <label class="f">Nome
      <input type="text" name="name" value="{{ old('name', $user->name) }}" required>
      @error('name', 'updateProfileInformation')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
      @error('email', 'updateProfileInformation')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <button type="submit" class="btn primary">Salvar dados</button>
  </form>

  <hr>
  <h2>Senha</h2>
  @if ($user->password)
    <form method="POST" action="{{ route('user-password.update') }}">
      @csrf @method('PUT')
      <label class="f">Senha atual
        <input type="password" name="current_password" required autocomplete="current-password">
        @error('current_password', 'updatePassword')<span class="erro">{{ $message }}</span>@enderror
      </label>
      <label class="f">Nova senha
        <input type="password" name="password" required autocomplete="new-password">
        @error('password', 'updatePassword')<span class="erro">{{ $message }}</span>@enderror
      </label>
      <label class="f">Repita a nova senha
        <input type="password" name="password_confirmation" required autocomplete="new-password">
      </label>
      <button type="submit" class="btn">Alterar senha</button>
    </form>
  @else
    <p>Esta conta entra pelo Google e não tem senha. Para criar uma, saia e use <a href="{{ route('password.request') }}">Esqueci a senha</a>.</p>
  @endif

  <hr>
  <h2>Excluir conta</h2>
  <p>Apaga a conta e todas as suas candidaturas. Não pode ser desfeito.</p>
  <form method="POST" action="{{ route('perfil.destroy') }}">
    @csrf @method('DELETE')
    <label class="f">Digite o seu e-mail para confirmar
      <input type="email" name="email_confirmacao" required autocomplete="off">
      @error('email_confirmacao')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <button type="submit" class="btn danger">Excluir minha conta</button>
  </form>
</div>
@endsection
