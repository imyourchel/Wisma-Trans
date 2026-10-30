@props(['p'])
{{-- Satu kartu pesanan (bentuk App\Support\PesananAkun). Dipakai Pesanan saya dan tab "Pesanan terakhir" di Cek Pesanan.
     Slot: tindakan tambahan di baris bawah kartu, misalnya hapus dari daftar perangkat. --}}
@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $gayaKeadaan = [
        'belum-bayar' => 'bg-[rgba(232,172,69,.16)] text-[#8A5A0B] ring-[rgba(232,172,69,.35)]',
        'diproses' => 'bg-[rgba(108,149,196,.12)] text-[#3F6B9C] ring-[rgba(108,149,196,.3)]',
        'selesai' => 'bg-[rgba(92,158,103,.12)] text-status-available ring-[rgba(92,158,103,.3)]',
        'batal' => 'bg-[rgba(212,81,74,.08)] text-status-dirty ring-[rgba(212,81,74,.25)]',
    ];
    $titik = ['belum-bayar' => 'bg-status-maintenance', 'diproses' => 'bg-status-reserved', 'selesai' => 'bg-status-available', 'batal' => 'bg-status-dirty'];
    $ikonJenis = ['kamar' => 'kasur', 'makanan' => 'makanan', 'laundry' => 'laundry', 'aula' => 'gedung'];
@endphp
<article data-jenis="{{ $p['jenis'] }}" data-keadaan="{{ $p['keadaan'] }}"
         {{ $attributes->merge(['class' => 'group relative flex gap-4 overflow-hidden rounded-xl border border-cream-300 bg-cream-50 p-4 shadow-wt-xs transition hover:-translate-y-0.5 hover:border-cream-400 hover:shadow-wt-md sm:p-5']) }}>
    <span class="absolute inset-y-0 left-0 w-1 {{ $titik[$p['keadaan']] }} opacity-70" aria-hidden="true"></span>
    <span class="hidden h-12 w-12 flex-none items-center justify-center rounded-xl bg-cream-200 text-clay-600 transition group-hover:bg-clay-500 group-hover:text-cream-50 sm:flex" aria-hidden="true">
        <x-tamu.ikon :nama="$ikonJenis[$p['jenis']]" ukuran="20" />
    </span>
    <div class="min-w-0 flex-1">
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2 text-xs text-ink-500">
                <x-tamu.ikon :nama="$ikonJenis[$p['jenis']]" ukuran="14" class="text-clay-600 sm:hidden" />
                <span class="font-semibold uppercase tracking-[.12em] text-clay-600">{{ \App\Support\PesananAkun::JENIS[$p['jenis']] }}</span>
                <span class="h-1 w-1 flex-none rounded-full bg-cream-400"></span>
                <span class="truncate tabular-nums">{{ $p['kode'] }}</span>
            </div>
            <span class="inline-flex flex-none items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $gayaKeadaan[$p['keadaan']] }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $titik[$p['keadaan']] }}"></span>{{ $p['label'] }}
            </span>
        </div>
        <h3 class="mb-0 mt-2 line-clamp-2 text-[15px] font-semibold leading-snug text-ink-900">{{ $p['judul'] }}</h3>
        @if ($p['waktu'])
            <p class="mb-0 mt-1 text-[13px] text-ink-500">{{ $p['waktu'] }}</p>
        @endif

        <div class="mt-3.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t border-dashed border-cream-300 pt-3">
            <span @class(['min-w-0 text-xs', 'inline-flex items-center gap-1.5 font-semibold text-[#8A5A0B]' => $p['keadaan'] === 'belum-bayar', 'text-ink-500' => $p['keadaan'] !== 'belum-bayar'])>
                @if ($p['keadaan'] === 'belum-bayar')<x-tamu.ikon nama="jam" ukuran="13" />@endif{{ $p['catatan'] }}
            </span>
            <div class="ml-auto flex flex-wrap items-center justify-end gap-3">
                <span class="font-display text-base font-extrabold tabular-nums text-ink-900">{{ $rp($p['total']) }}</span>
                @foreach ($p['aksi'] as [$teks, $url, $utama])
                    <a href="{{ $url }}" @class(['h-9 px-4 text-[11px] tracking-[.08em]', 'wt-btn-primary' => $utama, 'wt-btn-outline' => ! $utama])>{{ $teks }}</a>
                @endforeach
                {{ $slot }}
            </div>
        </div>
    </div>
</article>
