{{-- Kartu piutang instansi (Piutang Instansi dan Dashboard Manager). Variabel: $ringkasan dari PiutangService::ringkasan() --}}
@php $rp = fn ($n) => \App\Services\Reservasi\FolioService::rp($n); @endphp
<div class="mb-5 grid gap-3 sm:grid-cols-3" data-kartu-piutang>
    @foreach ([
        ['Total piutang instansi', $rp($ringkasan['total']), 'Semua tagihan instansi yang belum lunas', '#5B86B8'],
        ['Lewat jatuh tempo', $rp($ringkasan['lewat']), $ringkasan['jumlahLewat'].' tagihan', '#D4514A'],
        ['Diterima bulan ini', $rp($ringkasan['diterima']), now()->translatedFormat('F Y'), '#5C9E67'],
    ] as [$label, $nilai, $ket, $warna])
        <a href="{{ route('staf.keuangan.piutang') }}" class="wt-card relative overflow-hidden px-5 py-4 text-ink-900 shadow-wt-xs hover:border-cream-400 hover:text-ink-900 hover:no-underline">
            <span class="absolute inset-y-0 left-0 w-1" style="background-color: {{ $warna }}"></span>
            <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">{{ $label }}</div>
            <div class="mt-1.5 font-display text-[24px] font-extrabold leading-none tabular-nums">{{ $nilai }}</div>
            <div class="mt-2 truncate text-[12px] text-ink-500">{{ $ket }}</div>
        </a>
    @endforeach
</div>
