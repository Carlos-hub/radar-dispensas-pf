@extends('layouts.app')
@section('title', 'Minhas candidaturas · Radar de Dispensas PF')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
<header class="top">
  <div class="brand"><div>
    <h1>Minhas candidaturas</h1>
    <p>Acompanhe as dispensas que você salvou. A proposta é enviada no sistema oficial de cada órgão.</p>
  </div></div>
</header>

<div class="bar">
  <div class="row">
    <a class="chip" href="{{ route('candidaturas.index') }}" @if (! $statusAtivo) aria-current="true" @endif>Todas <small>{{ $total }}</small></a>
    @foreach (\App\Enums\StatusCandidatura::cases() as $s)
      <a class="chip" href="{{ route('candidaturas.index', ['status' => $s->value]) }}" @if ($statusAtivo === $s) aria-current="true" @endif>{{ $s->label() }} <small>{{ $contagem[$s->value] ?? 0 }}</small></a>
    @endforeach
  </div>
</div>

<div class="summary">
  <span class="stat"><b>{{ $emAndamento }}</b>em andamento</span>
  <span class="stat"><b>R$ {{ number_format($estimado, 2, ',', '.') }}</b>estimado em andamento</span>
  <span class="stat"><b>{{ $contagem['ganhei'] ?? 0 }}</b>ganhas</span>
</div>

<div class="list">
  @forelse ($candidaturas as $c)
    @include('partials.ficha', ['d' => $c->dispensa, 'cand' => $c])
  @empty
    @if ($total)
      <div class="state"><strong>Nada com este status</strong>Escolha outro filtro acima.</div>
    @else
      <div class="state"><strong>Nenhuma candidatura salva</strong>No <a href="{{ route('radar') }}">radar</a>, clique em "Salvar" nas dispensas que você quer disputar.</div>
    @endif
  @endforelse
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
@endsection
