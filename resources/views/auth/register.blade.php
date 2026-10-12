@extends('layouts.app')
@section('title', 'Criar conta · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Criar conta grátis</h1>
  <p>Salve dispensas e acompanhe as suas candidaturas.</p>
  <form method="POST" action="{{ route('register') }}">
    @csrf
    <label class="f">Nome
      <input type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
      @error('name')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
      @error('email')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Senha
      <input type="password" name="password" required autocomplete="new-password">
      @error('password')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label class="f">Repita a senha
      <input type="password" name="password_confirmation" required autocomplete="new-password">
    </label>
    <button type="submit" class="btn primary">Criar conta</button>
  </form>
  <p>Já tem conta? <a href="{{ route('login') }}">Entrar</a></p>
</div>
@endsection
