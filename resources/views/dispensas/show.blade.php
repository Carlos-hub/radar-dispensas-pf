@extends('layouts.app')
@php
    $p = $d->prazo();
@endphp
@section('title', \Illuminate\Support\Str::limit($d->objeto, 60) . ' · ' . $d->orgao)
@section('description', \Illuminate\Support\Str::limit("Dispensa de licitação em {$d->municipio}/{$d->uf}, {$d->orgao}. {$d->valorTexto()}, {$p['txt']}. {$d->objeto}", 155))
@push('head')<link rel="canonical" href="{{ route('dispensas.show', $d) }}">@endpush
@section('content')
<p><a href="{{ route('radar') }}">← Voltar ao radar</a></p>

<article class="card fit-{{ $d->fit->value }}">
  <div>
    <div class="meta"><span>{{ $d->orgao }}</span>@if ($d->unidade)<span>{{ $d->unidade }}</span>@endif<span>{{ $d->municipio }}/{{ $d->uf }}</span><span>{{ $d->esfera }}</span></div>
    <h1 class="obj">{{ $d->objeto }}</h1>
    @if ($d->categorias)
      <div class="tags">
        @foreach ($d->categorias as $c)
          <span class="tag">{{ \App\Services\ClassificadorPf::CATEGORIAS[$c]['label'] ?? $c }}</span>
        @endforeach
      </div>
    @endif
  </div>
  <div class="side">
    <span class="valor {{ $d->valor ? '' : 'sigiloso' }}">{{ $d->valorTexto() }}</span>
    <span class="prazo {{ $p['cls'] }}">{{ $p['txt'] }}</span>
    @if ($d->encerrada())
      <span class="pill fit-fora">Encerrada</span>
    @else
      <span class="pill fit-{{ $d->fit->value }}">{{ $d->fit->label() }}</span>
    @endif
  </div>

  <div class="track">
    <div>
      <p class="why"><strong>Aderência para pessoa física:</strong> {{ $d->motivo }}</p>
      <p class="why">A classificação é automática, feita a partir do texto do aviso, e não substitui a leitura do aviso oficial e do termo de referência.</p>
    </div>
  </div>

  @if ($d->complemento)
    <details open><summary>Informação complementar</summary><p>{{ $d->complemento }}</p></details>
  @endif

  <details open>
    <summary>Dados do aviso</summary>
    <p>
      Amparo legal: {{ $d->amparo ?: 'não informado' }}<br>
      Modo de disputa: {{ $d->disputa ?: 'não informado' }}<br>
      Abertura das propostas: {{ $d->abre_em?->format('d/m/Y H:i') ?? 'não informada' }}<br>
      Encerramento das propostas: {{ $d->encerra_em?->format('d/m/Y H:i') ?? 'não informado' }}<br>
      Número de controle PNCP: {{ $d->pncp_id }}
    </p>
  </details>

  @error('candidatura')<div class="flash erro-geral" style="grid-column:1/-1;margin:0">{{ $message }}</div>@enderror

  <div class="actions">
    @if ($d->urlPncp())<a href="{{ $d->urlPncp() }}" target="_blank" rel="noopener">Aviso no PNCP</a>@endif
    <span class="spacer"></span>
    @if ($salva)
      <a class="btn saved" href="{{ route('candidaturas.index') }}">✓ Salva</a>
    @elseif (! $d->encerrada())
      @auth
        <form method="POST" action="{{ route('candidaturas.store', $d) }}">
          @csrf
          <button type="submit" class="btn">Salvar</button>
        </form>
      @else
        <a class="btn" href="{{ route('dispensas.entrar', $d) }}" rel="nofollow">Salvar</a>
      @endauth
    @endif
    @if ($d->urlOrigem() && ! $d->encerrada())
      <a class="btn primary" href="{{ $d->urlOrigem() }}" target="_blank" rel="noopener">Enviar proposta no sistema oficial</a>
    @endif
  </div>
</article>
@endsection
