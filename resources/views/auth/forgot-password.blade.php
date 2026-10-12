@extends('layouts.app')
@section('title', 'Recuperar senha · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Recuperar senha</h1>
  <p>Informe o e-mail da conta e enviaremos um link para definir uma nova senha.</p>
  <form method="POST" action="{{ route('password.email') }}">
    @csrf
    <label class="f">E-mail
      <input type="email" name="email" value="{{ old('email') }}" required autofocus>
      @error('email')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <button type="submit" class="btn primary">Enviar link</button>
  </form>
  <p><a href="{{ route('login') }}">Voltar para o login</a></p>
</div>
@endsection
