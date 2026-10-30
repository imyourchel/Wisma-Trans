{{--
    Paginasi tabel panel staf: $kamar->links('partials.halaman'). Dipakai halaman biasa dan komponen Livewire.
    Di Livewire, alamat dari paginator tidak diawali "/" (misalnya "staf/riwayat-status-kamar?page=2"), sehingga browser
    menempelkannya ke alamat sekarang (/staf/staf/...). Karena itu alamat dijadikan absolut, dan di Livewire klik ditangani
    gotoPage() supaya penyaring di alamat (#[Url]) tetap terbawa. Di halaman biasa wire:click diabaikan dan tautan dipakai.
--}}
@php
    $alamat = fn (?string $u) => $u !== null && ! preg_match('#^(https?:)?//|^/#', $u) ? url($u) : $u;
    $namaHalaman = $paginator->getPageName();
@endphp
@if ($paginator->hasPages())
    <nav class="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Halaman">
        <span class="text-[13px] text-ink-500">
            Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }}
        </span>
        {{-- HP: hanya halaman sekarang beserta tombol sebelumnya/berikutnya --}}
        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="wt-btn-sm opacity-40 pointer-coarse:min-w-11" aria-hidden="true">‹</span>
            @else
                <a href="{{ $alamat($paginator->previousPageUrl()) }}" wire:click.prevent="previousPage('{{ $namaHalaman }}')" rel="prev" class="wt-btn-sm hover:no-underline pointer-coarse:min-w-11" aria-label="Halaman sebelumnya">‹</a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1.5 text-[13px] text-ink-500 max-sm:hidden">…</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span aria-current="page" class="wt-btn-sm min-w-8 pointer-coarse:min-w-11 !border-brown-800 !bg-brown-800 tabular-nums !text-cream-150">{{ $page }}<span class="sm:hidden">&nbsp;/ {{ $paginator->lastPage() }}</span></span>
                        @else
                            <a href="{{ $alamat($url) }}" wire:click.prevent="gotoPage({{ $page }}, '{{ $namaHalaman }}')" class="wt-btn-sm min-w-8 pointer-coarse:min-w-11 tabular-nums hover:no-underline max-sm:hidden">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a href="{{ $alamat($paginator->nextPageUrl()) }}" wire:click.prevent="nextPage('{{ $namaHalaman }}')" rel="next" class="wt-btn-sm hover:no-underline pointer-coarse:min-w-11" aria-label="Halaman berikutnya">›</a>
            @else
                <span class="wt-btn-sm opacity-40 pointer-coarse:min-w-11" aria-hidden="true">›</span>
            @endif
        </div>
    </nav>
@endif
