@php
    $p = $d->prazo();
    $cand = $cand ?? null;
    $salva = $salva ?? false;
@endphp
<article class="card {{ $cand ? 'st-' . $cand->status->value : 'fit-' . $d->fit->value }}">
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
    @if ($cand)
      <span class="pill st-{{ $cand->status->value }}" data-pill>{{ $cand->status->label() }}</span>
    @else
      <span class="pill fit-{{ $d->fit->value }}">{{ $d->fit->label() }}</span>
    @endif
  </div>

  @if ($cand)
    <form class="track" method="POST" action="{{ route('candidaturas.update', $cand) }}" data-candidatura>
      @csrf @method('PATCH')
      <label class="f">Status
        <select name="status">
          @foreach (\App\Enums\StatusCandidatura::cases() as $s)
            <option value="{{ $s->value }}" @selected($cand->status === $s)>{{ $s->label() }}</option>
          @endforeach
        </select>
      </label>
      <label class="f grow">Notas
        <textarea name="notas" rows="2" maxlength="{{ \App\Models\Candidatura::NOTAS_MAX }}" placeholder="Documentos pendentes, valor da proposta, contato do órgão…">{{ $cand->notas }}</textarea>
      </label>
      <button type="submit" class="btn so-sem-js">Guardar</button>
    </form>
  @endif

  <div class="actions">
    @if ($d->urlPncp())<a href="{{ $d->urlPncp() }}" target="_blank" rel="noopener">Aviso no PNCP</a>@endif
    @if ($d->urlOrigem() && ! $d->encerrada())<a href="{{ $d->urlOrigem() }}" target="_blank" rel="noopener">Enviar proposta</a>@endif
    <span class="spacer"></span>
    @if ($cand)
      <form method="POST" action="{{ route('candidaturas.destroy', $cand) }}" data-confirmar="Remover esta candidatura? As notas serão apagadas.">
        @csrf @method('DELETE')
        <button type="submit" class="btn danger">Remover</button>
      </form>
    @elseif ($salva)
      <a class="btn saved" href="{{ route('candidaturas.index') }}">✓ Salva</a>
    @elseif (! $d->encerrada())
      @auth
        <form method="POST" action="{{ route('candidaturas.store', $d) }}">
          @csrf
          <button type="submit" class="btn primary">Salvar</button>
        </form>
      @else
        <a class="btn primary" href="{{ route('dispensas.entrar', $d) }}" rel="nofollow">Salvar</a>
      @endauth
    @endif
  </div>
</article>
