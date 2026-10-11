{{-- Satu balok pemesanan di kalender. Manager/FO: tautan ke detail reservasi; Housekeeping: kode dan tanggal saja (tanpa nama tamu).
     Variabel: $b, $gayaBalok, $namaJenis, $teksBalok --}}
@php
    $kelas = ['z-10 my-1.5 flex min-w-0 items-center gap-1.5 overflow-hidden px-2 text-[12px] font-semibold leading-none', $gayaBalok[$b->jenis],
        'ml-0 rounded-l-none' => $b->potongKiri, 'ml-1 rounded-l-md' => ! $b->potongKiri,
        'mr-0 rounded-r-none' => $b->potongKanan, 'mr-1 rounded-r-md' => ! $b->potongKanan];
    $judul = $teksBalok($b).' · '.$b->tanggal.' · '.$namaJenis[$b->jenis];
@endphp
@if ($b->url)
    <a href="{{ $b->url }}" wire:key="balok-{{ $b->id }}" style="grid-row: 1; grid-column: {{ $b->kolom }}" title="{{ $judul }}" @class([...$kelas, 'hover:no-underline hover:brightness-95'])>
@else
    <div wire:key="balok-{{ $b->id }}" style="grid-row: 1; grid-column: {{ $b->kolom }}" title="{{ $judul }}" @class($kelas)>
@endif
    @if ($b->potongKiri)<span aria-hidden="true">‹</span>@endif
    <span class="truncate">
        @if ($b->nama){{ $b->nama }}@if ($b->instansi)<span class="font-normal opacity-75"> · {{ $b->instansi }}</span>@endif
        @else{{ $b->kode }}@endif
    </span>
    @if ($b->potongKanan)<span class="ml-auto pl-1" aria-hidden="true">›</span>@endif
@if ($b->url)
    </a>
@else
    </div>
@endif
