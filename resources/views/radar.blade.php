@extends('layouts.app')
@section('title', ($filtro->uf ? "Dispensas de licitação para pessoa física em {$filtro->uf}" : 'Dispensas de licitação para pessoa física') . ' · Radar de Dispensas PF')
@push('head')
  @if ($indexavel)
    <link rel="canonical" href="{{ $canonica }}">
  @else
    <meta name="robots" content="noindex, follow">
  @endif
@endpush
@section('content')
<header class="top">
  <div class="brand">
    <svg class="logo" viewBox="0 0 40 40" aria-hidden="true">
      <circle cx="20" cy="20" r="17" fill="none" stroke="currentColor" stroke-width="2" opacity=".35"/>
      <circle cx="20" cy="20" r="10" fill="none" stroke="currentColor" stroke-width="2" opacity=".6"/>
      <path d="M20 20 L32 8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
      <circle cx="20" cy="20" r="3" fill="currentColor"/>
    </svg>
    <div>
      <h1>Radar de Dispensas PF</h1>
      <p>Dispensas de licitação com propostas abertas no PNCP, filtradas pelo que uma pessoa física consegue executar sem CNPJ.</p>
    </div>
  </div>
</header>

<form class="bar" method="GET" action="{{ route('radar') }}" data-auto-enviar>
  <div class="row">
    <label class="f">UF
      <select name="uf">
        <option value="">Brasil todo</option>
        @foreach (\App\Support\FiltroRadar::UFS as $uf)
          <option value="{{ $uf }}" @selected($filtro->uf === $uf)>{{ $uf }}</option>
        @endforeach
      </select>
    </label>
    <label class="f">Encerra em até
      <select name="dias">
        @foreach (\App\Support\FiltroRadar::DIAS as $dias)
          <option value="{{ $dias }}" @selected($filtro->dias === $dias)>{{ $dias }} dias</option>
        @endforeach
      </select>
    </label>
    <label class="f">Valor máximo (R$)
      <input name="vmax" type="number" min="0" step="1000" placeholder="sem limite" value="{{ $filtro->vmax }}">
    </label>
    <label class="f grow">Buscar no objeto
      <input name="q" type="search" maxlength="100" placeholder="ex.: Laravel, palestra, Chapecó" value="{{ $filtro->q }}">
    </label>
    <button class="btn primary" type="submit">Filtrar</button>
  </div>
  <div class="row">
    @foreach ($categorias as $chave => $label)
      <label class="chip" aria-pressed="{{ in_array($chave, $filtro->cats, true) ? 'true' : 'false' }}">
        <input type="checkbox" name="cat[]" value="{{ $chave }}" @checked(in_array($chave, $filtro->cats, true)) hidden> {{ $label }}
      </label>
    @endforeach
  </div>
  <div class="row">
    <input type="hidden" name="verificar" value="0">
    <label class="toggle"><input type="checkbox" name="verificar" value="1" @checked(in_array('verificar', $filtro->fits, true))> Incluir "verificar"</label>
    <label class="toggle"><input type="checkbox" name="baixa" value="1" @checked(in_array('baixa', $filtro->fits, true))> Incluir baixa aderência</label>
    <label class="toggle"><input type="checkbox" name="fora" value="1" @checked(in_array('fora', $filtro->fits, true))> Incluir bens e engenharia</label>
  </div>
</form>

@if ($desatualizado)
  <div class="flash aviso" style="margin-top:16px">Não conseguimos atualizar com o PNCP na última hora. Os dados podem estar desatualizados.</div>
@endif

<div class="summary">
  <span class="stat"><b>{{ $resumo['exibidas'] }}</b>exibidas</span>
  <span class="stat"><b>{{ $resumo['alta'] }}</b>fit PF</span>
  <span class="stat"><b>{{ $resumo['verificar'] }}</b>a verificar</span>
  <span class="stat"><b>{{ $resumo['abertas'] }}</b>abertas no recorte</span>
  <span class="stat"><b>R$ {{ number_format($resumo['estimado'], 2, ',', '.') }}</b>estimado exibido</span>
  @if ($ultimaSync)<span class="when">Atualizado {{ $ultimaSync->format('d/m H:i') }}</span>@endif
</div>

<div class="list">
  @forelse ($dispensas as $d)
    @include('partials.ficha', ['d' => $d])
  @empty
    <div class="state"><strong>Nenhuma dispensa neste filtro</strong>Amplie o prazo, troque a UF ou inclua os itens a verificar.</div>
  @endforelse
</div>

{{ $dispensas->links('partials.paginacao') }}
@endsection
