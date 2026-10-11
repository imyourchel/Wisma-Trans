@php
    use App\Livewire\Staf\Layanan\RiwayatPesanan;
    use App\Services\Layanan\PesananMakananService as PM;
    use App\Support\GayaPesanan;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $kartu = [
        ['Jumlah pesanan', number_format($ringkasan['jumlah'], 0, ',', '.'), '#6E5233'],
        ['Total lunas', $rp($ringkasan['lunas']), '#5C9E67'],
        ['Total tagihan kamar', $rp($ringkasan['tagihanKamar']), '#5B86B8'],
        ['Dibatalkan', $ringkasan['batal'], '#D4514A'],
    ];
@endphp

<div>
    {{-- Saringan --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <label class="relative block min-w-0 flex-[1_1_220px] sm:max-w-[280px]">
            <span class="sr-only">Cari pesanan</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Kode atau nama pemesan" class="wt-input pl-9">
        </label>
        <label class="block">
            <span class="sr-only">Tanggal</span>
            <input type="date" wire:model.live="tanggal" class="wt-input w-[170px]" aria-label="Tanggal pesanan">
        </label>
        <x-staf.pilih model="$wire.status" x-on:change="$wire.$commit()" label="Saring status" class="w-[160px]" :nilai="$status"
                      :pilihan="['' => 'Semua status'] + PM::STATUS" />
        <x-staf.pilih model="$wire.jenis" x-on:change="$wire.$commit()" label="Saring jenis" class="w-[170px]" :nilai="$jenis"
                      :pilihan="['' => 'Semua pemesan'] + RiwayatPesanan::JENIS" />
        <x-staf.pilih model="$wire.bayar" x-on:change="$wire.$commit()" label="Saring pembayaran" class="w-[180px]" :nilai="$bayar"
                      :pilihan="['' => 'Semua pembayaran'] + RiwayatPesanan::BAYAR" />
        <button type="button" wire:click="resetSaringan" class="wt-btn-sm h-10">Atur ulang</button>
    </div>

    {{-- Ringkasan sesuai saringan --}}
    <div class="mb-4 grid gap-3 lg:grid-cols-[repeat(4,minmax(0,1fr))_minmax(0,1.4fr)]">
        @foreach ($kartu as [$label, $nilai, $warna])
            <div class="wt-card relative overflow-hidden px-5 py-4 shadow-wt-xs">
                <span class="absolute inset-y-0 left-0 w-1" style="background-color: {{ $warna }}"></span>
                <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">{{ $label }}</div>
                <div class="mt-1.5 font-display text-[24px] font-extrabold leading-none tabular-nums">{{ $nilai }}</div>
            </div>
        @endforeach
        <div class="wt-card px-5 py-4 shadow-wt-xs">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">Menu terlaris</div>
            @if ($ringkasan['terlaris']->isEmpty())
                <div class="mt-2 text-[13px] text-ink-500">Belum ada.</div>
            @else
                <ol class="m-0 mt-2 flex list-none flex-col gap-1 p-0 text-[13px]">
                    @foreach ($ringkasan['terlaris'] as $i => $t)
                        <li class="flex items-baseline gap-2"><span class="w-4 font-display font-extrabold text-clay-500">{{ $i + 1 }}</span><span class="min-w-0 flex-1 truncate">{{ $t->name }}</span><span class="tabular-nums text-ink-500">{{ (int) $t->porsi }} porsi</span></li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    <div class="wt-card overflow-x-auto shadow-wt-xs">
        <table class="w-full min-w-[960px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Kode</th><th class="wt-th">Waktu</th><th class="wt-th">Pemesan</th><th class="wt-th">Menu</th>
                    <th class="wt-th text-right">Total</th><th class="wt-th">Pembayaran</th><th class="wt-th">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($daftar as $x)
                    @php [$kunciBayar, $labelBayar] = PM::pembayaran($x); @endphp
                    <tr wire:key="rwy-{{ $x->id }}" wire:click="open({{ $x->id }})" class="cursor-pointer hover:bg-row-hover">
                        <td class="wt-td font-semibold tabular-nums">{{ $x->order_code }}</td>
                        <td class="wt-td whitespace-nowrap tabular-nums">{{ $x->order_date->translatedFormat('j M, H.i') }}</td>
                        <td class="wt-td"><div class="font-semibold">{{ $x->customer?->full_name }}</div><div class="text-[11px] text-ink-500">{{ PM::jenis($x) }}</div></td>
                        <td class="wt-td text-[13px]">{{ PM::ringkasMenu($x) }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $rp(PM::total($x)) }}</td>
                        <td class="wt-td"><span class="wt-lencana {{ GayaPesanan::BAYAR[$kunciBayar] }}">{{ $labelBayar }}</span></td>
                        <td class="wt-td"><span class="wt-lencana {{ GayaPesanan::TAHAP[$x->status] ?? '' }}">{{ PM::STATUS[$x->status] ?? $x->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="wt-td py-16 text-center text-sm text-ink-500">Tidak ada pesanan yang cocok dengan saringan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $daftar->links('partials.halaman') }}

    @if ($o)
        @include('livewire.staf.layanan.partials.detail-pesanan', ['aksiDapur' => false])
    @endif
</div>
