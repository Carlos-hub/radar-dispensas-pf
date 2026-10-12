@extends('layouts.app')
@section('title', 'Entrar · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Entrar</h1>
  <form method="POST" action="{{ route('login') }}">
    @csrf
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
      @error('email')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Senha
      <input type="password" name="password" required autocomplete="current-password">
      @error('password')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="toggle"><input type="checkbox" name="remember"> Manter conectado</label>
    <button type="submit" class="btn primary">Entrar</button>
  </form>
  <p><a href="{{ route('password.request') }}">Esqueci a senha</a> · <a href="{{ route('register') }}">Criar conta grátis</a></p>
</div>
@endsection
