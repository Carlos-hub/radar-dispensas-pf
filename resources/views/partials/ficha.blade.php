@php
    $p = $d->prazo();
@endphp
<article class="card fit-{{ $d->fit->value }}">
  <div>
    <div class="meta"><span>{{ $d->orgao }}</span><span>{{ $d->municipio }}/{{ $d->uf }}</span><span>{{ $d->esfera }}</span></div>
    <h2 class="obj"><a href="{{ route('dispensas.show', $d) }}">{{ $d->objeto }}</a></h2>
    <p class="why">{{ $d->motivo }}</p>
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
    <span class="pill fit-{{ $d->fit->value }}">{{ $d->fit->label() }}</span>
  </div>
  <div class="actions">
    @if ($d->urlPncp())<a href="{{ $d->urlPncp() }}" target="_blank" rel="noopener">Aviso no PNCP</a>@endif
    @if ($d->urlOrigem())<a href="{{ $d->urlOrigem() }}" target="_blank" rel="noopener">Enviar proposta</a>@endif
    <span class="spacer"></span>
    <a class="btn" href="{{ route('dispensas.show', $d) }}">Ver detalhes</a>
  </div>
</article>
