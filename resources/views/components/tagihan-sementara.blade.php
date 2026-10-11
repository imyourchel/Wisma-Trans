@props([
    'folio',            // hasil App\Services\FolioService::rincian()
    'staf' => false,    // tampilan staf: tanda baris yang baru digabung saat check-out
])
@php
    $rp = fn ($n) => \App\Services\FolioService::rp($n);
    $terpisah = $folio['nota']->has('service');
    $kelompok = [
        'Kamar' => $folio['kamar'],
        'Layanan ditagihkan ke kamar' => $folio['layanan'],
        'Terlambat check-out' => $folio['terlambat'],
    ];
@endphp
{{-- Tagihan berjalan: kamar, layanan tagihan kamar, biaya terlambat, dan pembayaran yang sudah diterima --}}
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded border border-cream-300']) }} data-tagihan-sementara>
    @foreach ($kelompok as $judul => $baris)
        @continue($baris->isEmpty())
        <div class="border-b border-cream-300 last:border-b-0">
            <div class="flex items-center justify-between gap-3 bg-cream-200 px-4 py-2 text-[11px] font-bold uppercase tracking-[.08em] text-ink-500">
                <span>{{ $judul }}</span>
                @if ($terpisah && $judul === 'Layanan ditagihkan ke kamar')
                    <span class="normal-case tracking-normal">Nota layanan terpisah</span>
                @endif
            </div>
            <ul class="m-0 list-none p-0 text-[13px]">
                @foreach ($baris as $b)
                    <li class="flex items-baseline justify-between gap-3 px-4 py-2">
                        <span class="min-w-0">
                            <span @class(['line-through text-ink-500' => $b['bebas'] ?? false])>{{ $b['deskripsi'] }}</span>
                            @if ($b['bebas'] ?? false)
                                <span class="ml-1 text-[11px] font-semibold text-status-available">dibebaskan</span>
                            @endif
                            @if ($b['perkiraan'] ?? false)
                                <span class="ml-1 text-[11px] font-semibold text-[#8A5E10]">perkiraan, belum ditimbang</span>
                            @endif
                            @if ($staf && ($b['baru'] ?? false) && ! ($b['bebas'] ?? false) && ! ($b['perkiraan'] ?? false))
                                <span class="ml-1 text-[11px] text-ink-500">masuk nota saat check-out</span>
                            @endif
                        </span>
                        <span class="flex-none tabular-nums">{{ $rp($b['subtotal']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
    @if ($folio['periode']->isNotEmpty())
        {{-- Menginap panjang instansi: malam yang sudah ditagih per periode tidak dihitung lagi di sini --}}
        <div class="border-b border-cream-300 bg-cream-50 px-4 py-2 text-[12px] text-ink-500" data-periode>
            Sudah ditagihkan per periode:
            {{ $folio['periode']->map(fn ($p) => $p['nomor'].' ('.$p['mulai']->translatedFormat('j M').' – '.$p['akhir']->translatedFormat('j M').', '.$rp($p['total']).')')->join('; ') }}
        </div>
    @endif
    <dl class="m-0 grid gap-1.5 bg-cream-100 px-4 py-3 text-[13px]">
        @if ($folio['nota']->sum('diskon') > 0)
            <div class="flex justify-between gap-3"><dt class="text-ink-500">Potongan</dt><dd class="m-0 tabular-nums">−{{ $rp($folio['nota']->sum('diskon')) }}</dd></div>
        @endif
        <div class="flex justify-between gap-3"><dt class="text-ink-500">Total</dt><dd class="m-0 font-semibold tabular-nums" data-total>{{ $rp($folio['total']) }}</dd></div>
        <div class="flex justify-between gap-3"><dt class="text-ink-500">Sudah dibayar</dt><dd class="m-0 tabular-nums">{{ $rp($folio['sudah_dibayar']) }}</dd></div>
        @if ($folio['piutang'] > 0)
            <div class="flex justify-between gap-3"><dt class="text-ink-500">Ditagihkan ke instansi</dt><dd class="m-0 tabular-nums">{{ $rp($folio['piutang']) }}</dd></div>
        @endif
        <div class="flex justify-between gap-3 border-t border-cream-300 pt-1.5">
            <dt class="font-semibold">Sisa</dt>
            <dd class="m-0 font-display text-[16px] font-extrabold tabular-nums" data-sisa>{{ $rp($folio['sisa']) }}</dd>
        </div>
    </dl>
</div>
