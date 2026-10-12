@extends('layouts.app')
@section('title', 'Confirme o seu e-mail · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<div class="painel">
  <h1>Confirme o seu e-mail</h1>
  <p>Enviamos um link de confirmação para <strong>{{ auth()->user()->email }}</strong>. Clique nele para poder salvar dispensas.</p>
  <form method="POST" action="{{ route('verification.send') }}">
    @csrf
    <button type="submit" class="btn primary">Reenviar o link</button>
  </form>
  <p><a href="{{ url('/') }}">Continuar navegando no radar</a></p>
</div>
@endsection
