@php($selos = $d->selos())
@if ($selos)
  <div class="tags" aria-label="Classificação do edital">
    @foreach ($selos as $selo)
      <span class="tag {{ $selo['cls'] }}">{{ $selo['txt'] }}</span>
    @endforeach
  </div>
@endif
