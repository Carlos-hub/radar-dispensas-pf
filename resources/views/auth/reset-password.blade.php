@extends('layouts.app')
@section('title', 'Nova senha · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Definir nova senha</h1>
  <form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email', $request->email) }}" required>
      @error('email')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Nova senha
      <input type="password" name="password" required autofocus autocomplete="new-password">
      @error('password')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Repita a nova senha
      <input type="password" name="password_confirmation" required autocomplete="new-password">
    </label>
    <button type="submit" class="btn primary">Salvar senha</button>
  </form>
</div>
@endsection
