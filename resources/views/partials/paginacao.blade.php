@if ($paginator->hasPages())
<nav class="paginacao" aria-label="Paginação">
  <a class="btn" @if ($paginator->onFirstPage()) aria-disabled="true" @else href="{{ $paginator->previousPageUrl() }}" rel="prev" @endif>← Anteriores</a>
  <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
  <a class="btn" @if ($paginator->hasMorePages()) href="{{ $paginator->nextPageUrl() }}" rel="next" @else aria-disabled="true" @endif>Próximas →</a>
</nav>
@endif
