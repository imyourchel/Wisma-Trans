{{-- Paginasi tabel panel staf: $kamar->links('partials.halaman') --}}
@if ($paginator->hasPages())
    <nav class="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Halaman">
        <span class="text-[13px] text-ink-500">
            Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }}
        </span>
        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="wt-btn-sm opacity-40" aria-hidden="true">‹</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="wt-btn-sm hover:no-underline" aria-label="Halaman sebelumnya">‹</a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1.5 text-[13px] text-ink-500">…</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span aria-current="page" class="wt-btn-sm min-w-8 !border-brown-800 !bg-brown-800 tabular-nums !text-cream-150">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="wt-btn-sm min-w-8 tabular-nums hover:no-underline">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="wt-btn-sm hover:no-underline" aria-label="Halaman berikutnya">›</a>
            @else
                <span class="wt-btn-sm opacity-40" aria-hidden="true">›</span>
            @endif
        </div>
    </nav>
@endif
