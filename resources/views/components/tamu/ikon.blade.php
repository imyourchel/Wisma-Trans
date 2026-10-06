@props(['nama' => null, 'fasilitas' => null, 'ukuran' => 18])
{{-- Ikon garis halaman tamu; warna mengikuti currentColor. Beri "fasilitas" (nama fasilitas) untuk memilih ikon otomatis. --}}
@php
    if ($fasilitas !== null) {
        $f = Str::lower($fasilitas);
        $nama = match (true) {
            $f === 'ac' || str_contains($f, 'pendingin') => 'ac',
            str_contains($f, 'tv') || str_contains($f, 'televisi') => 'tv',
            str_contains($f, 'wifi') || str_contains($f, 'wi-fi') || str_contains($f, 'internet') => 'wifi',
            str_contains($f, 'heater') || str_contains($f, 'air panas') || str_contains($f, 'pemanas') => 'air-panas',
            str_contains($f, 'kloset') || str_contains($f, 'toilet') || str_contains($f, 'kamar mandi') => 'kloset',
            str_contains($f, 'kulkas') || str_contains($f, 'lemari es') => 'kulkas',
            str_contains($f, 'meja') || str_contains($f, 'sofa') || str_contains($f, 'kursi') => 'kursi',
            default => 'cek',
        };
    }
@endphp
<svg {{ $attributes->merge(['class' => 'flex-none']) }} width="{{ $ukuran }}" height="{{ $ukuran }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($nama)
        @case('orang')
            <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
            @break
        @case('kasur')
            <path d="M2 20v-8a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v8"/><path d="M4 10V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4"/><path d="M12 4v6M2 17h20"/>
            @break
        @case('ac')
            <path d="M12 2v20M4.9 4.9l14.2 14.2M2 12h20M4.9 19.1 19.1 4.9"/><path d="m9 3 3 2 3-2M9 21l3-2 3 2M3 9l2 3-2 3M21 9l-2 3 2 3"/>
            @break
        @case('tv')
            <rect x="2" y="7" width="20" height="13" rx="2"/><path d="m17 2-5 5-5-5"/>
            @break
        @case('wifi')
            <path d="M5 12.55a11 11 0 0 1 14.08 0M1.42 9a16 16 0 0 1 21.16 0M8.53 16.11a6 6 0 0 1 6.95 0"/><path d="M12 20h.01"/>
            @break
        @case('air-panas')
            <path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7Z"/><path d="M12 18a3 3 0 0 1-3-3"/>
            @break
        @case('kloset')
            <path d="M8 3h5v6H8z"/><path d="M5 9h14v1a6 6 0 0 1-6 6h-2a6 6 0 0 1-6-6Z"/><path d="m9 16-1 5h8l-1-5"/>
            @break
        @case('kulkas')
            <rect x="5" y="2" width="14" height="20" rx="2"/><path d="M5 10h14M9 5v2M9 13v3"/>
            @break
        @case('kursi')
            <path d="M5 11V6a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v5"/><path d="M3 11h18v5H3zM5 16v4M19 16v4"/>
            @break
        @case('jam')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('dompet')
            <rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>
            @break
        @case('gedung')
            <path d="M3 21h18M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1M10 21v-3h4v3"/>
            @break
        @case('kiri')
            <path d="m15 18-6-6 6-6"/>
            @break
        @case('kanan')
            <path d="m9 18 6-6-6-6"/>
            @break
        @case('perbesar')
            <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>
            @break
        @case('tutup')
            <path d="M18 6 6 18M6 6l12 12"/>
            @break
        @case('info')
            <circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>
            @break
        @case('makanan')
            <path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>
            @break
        @case('laundry')
            <path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23Z"/>
            @break
        @case('kalender')
            <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
            @break
        @default
            <path d="M20 6 9 17l-5-5"/>
    @endswitch
</svg>
