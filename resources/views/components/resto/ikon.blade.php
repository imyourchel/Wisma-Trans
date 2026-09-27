@props(['minum' => false, 'ukuran' => 24])
{{-- Penampung foto menu: gelas untuk minuman, sendok-garpu untuk makanan --}}
<svg width="{{ $ukuran }}" height="{{ $ukuran }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @if ($minum)
        <path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 2v2M10 2v2M14 2v2"/>
    @else
        <path d="M4.5 3v5a3 3 0 0 0 6 0V3M7.5 3v5M7.5 11v10"/><path d="M17 3c-1.7 0-3 2-3 4.5S15.3 12 17 12s3-2 3-4.5S18.7 3 17 3ZM17 12v9"/>
    @endif
</svg>
