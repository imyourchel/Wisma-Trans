@php
    use App\Models\Payment;
    use App\Services\LaundryService;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $tahap = [
        'pending' => ['Menunggu ditimbang', 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]'],
        'in_progress' => ['Dicuci', 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]'],
        'ready' => ['Siap', 'bg-[rgba(92,158,103,.18)] text-status-available'],
        'delivered' => ['Diserahkan', 'bg-[rgba(155,139,120,.20)] text-[#6E604F]'],
        'cancelled' => ['Dibatalkan', 'bg-[rgba(212,81,74,.12)] text-status-dirty'],
    ];
    $siapa = fn ($x) => $x->reservationRoom ? 'Kamar '.$x->reservationRoom->room?->room_number : 'Tamu luar';
    $bayarLabel = fn ($x) => match (true) {
        $x->payment_mode === 'charge_to_room' => 'Tagihan kamar',
        $x->sudahDibayar() => 'Lunas',
        (bool) $x->invoice => 'Belum dibayar',
        default => 'Bayar setelah ditimbang',
    };
@endphp

<div>
    <div class="mb-4 grid grid-cols-3 gap-3 sm:max-w-[560px]">
        @foreach (['pending' => 'Menunggu ditimbang', 'in_progress' => 'Sedang dicuci', 'ready' => 'Siap diserahkan'] as $s => $l)
            <div class="wt-card px-4 py-3">
                <div class="font-display text-2xl font-extrabold tabular-nums">{{ $jumlah[$s] ?? 0 }}</div>
                <div class="text-[12px] text-ink-500">{{ $l }}</div>
            </div>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Saring laundry">
            @foreach (['aktif' => 'Aktif', 'selesai' => 'Selesai', 'batal' => 'Dibatalkan'] as $v => $l)
                <button type="button" wire:click="$set('tab', '{{ $v }}')" role="tab" aria-selected="{{ $tab === $v ? 'true' : 'false' }}"
                        @class(['h-8 rounded-md border-0 px-3.5 text-[13px] font-semibold transition-colors',
                            'bg-brown-800 text-cream-50' => $tab === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $tab !== $v])>{{ $l }}</button>
            @endforeach
        </div>
        <label class="relative block min-w-0 flex-[1_1_220px] sm:max-w-[300px]">
            <span class="sr-only">Cari laundry</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Kode, nama, atau nomor kamar" class="wt-input pl-9">
        </label>
        <span wire:poll.60s class="text-[12px] text-ink-500">Diperbarui {{ now()->format('H.i') }} WITA</span>
        @if ($bolehKelola)
            <button type="button" wire:click="bukaTerima" class="ml-auto inline-flex h-10 items-center gap-2 whitespace-nowrap rounded border-0 bg-clay-500 px-4 font-display text-[13px] font-semibold text-cream-50 hover:bg-clay-600">
                <x-staf.ikon nama="tambah" ukuran="16" />Terima laundry
            </button>
        @endif
    </div>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[860px] border-collapse">
            <thead><tr><th class="wt-th">Kode</th><th class="wt-th">Tamu</th><th class="wt-th">Masuk</th><th class="wt-th">Berat</th><th class="wt-th">Tahap</th><th class="wt-th">Pembayaran</th><th class="wt-th text-right">Tagihan</th></tr></thead>
            <tbody>
                @forelse ($daftar as $x)
                    @php $item = $x->items->first(); @endphp
                    <tr wire:key="ldr-{{ $x->id }}" wire:click="open({{ $x->id }})" class="cursor-pointer hover:bg-row-hover">
                        <td class="wt-td font-semibold tabular-nums">{{ $x->order_code }}</td>
                        <td class="wt-td"><div class="font-semibold">{{ $siapa($x) }}</div><div class="text-[11px] text-ink-500">{{ $x->customer?->full_name }}</div></td>
                        <td class="wt-td whitespace-nowrap">{{ $x->order_date->translatedFormat('j M, H.i') }}</td>
                        <td class="wt-td text-[12px]">{{ $x->status === 'pending' ? 'belum ditimbang' : $item?->note }}</td>
                        <td class="wt-td"><span class="wt-lencana {{ $tahap[$x->status][1] }}">{{ $tahap[$x->status][0] }}</span></td>
                        <td class="wt-td text-[12px]">{{ $bayarLabel($x) }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $x->status === 'pending' ? '—' : $rp($item?->subtotal) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada pesanan laundry di sini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Detail pesanan --}}
    @if ($o)
        @php $item = $o->items->first(); $lunas = $o->invoice?->payments->firstWhere('status', 'success'); @endphp
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="ldr-judul" class="relative flex max-h-[92vh] w-[560px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="ldr-judul" class="flex flex-wrap items-center gap-2.5"><span class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $o->order_code }}</span><span class="wt-lencana {{ $tahap[$o->status][1] }}">{{ $tahap[$o->status][0] }}</span></div>
                        <div class="mt-1 text-[13px] text-ink-500">{{ $siapa($o) }} · {{ $o->customer?->full_name }} · {{ $o->customer?->phone }}</div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <dl class="m-0 grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
                        <div><dt class="wt-field-label">Diterima</dt><dd class="m-0 mt-1">{{ $o->order_date->translatedFormat('j M Y, H.i') }}</dd></div>
                        <div><dt class="wt-field-label">Serah terima</dt><dd class="m-0 mt-1">{{ $o->reservationRoom ? 'Jemput dan antar ke kamar' : 'Di resepsionis' }}</dd></div>
                        <div><dt class="wt-field-label">Berat</dt><dd class="m-0 mt-1">{{ $item?->note }}</dd></div>
                        <div><dt class="wt-field-label">Tagihan</dt><dd class="m-0 mt-1 font-semibold tabular-nums">{{ $o->status === 'pending' ? 'setelah ditimbang' : $rp($item?->subtotal) }} · {{ $bayarLabel($o) }}</dd></div>
                        @if ($lunas)<div class="col-span-2"><dt class="wt-field-label">Dibayar</dt><dd class="m-0 mt-1">{{ Payment::METHODS[$lunas->method] ?? $lunas->method }} · {{ $lunas->paid_at?->translatedFormat('j M, H.i') }} · {{ $lunas->payment_code }}</dd></div>@endif
                        @if ($o->note)<div class="col-span-2"><dt class="wt-field-label">Catatan</dt><dd class="m-0 mt-1 whitespace-pre-line">{{ $o->note }}</dd></div>@endif
                    </dl>

                    @if ($bolehKelola)
                        <div class="mt-5 flex flex-wrap gap-2 border-t border-cream-300 pt-5">
                            @if ($o->status === 'pending')
                                <button type="button" wire:click="mulai('timbang')" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Timbang</button>
                                <button type="button" wire:click="mulai('batal')" class="wt-btn-sm !text-status-dirty">Batalkan</button>
                            @elseif ($o->status === 'in_progress')
                                <button type="button" wire:click="siap" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Tandai siap</button>
                            @elseif ($o->status === 'ready')
                                <button type="button" wire:click="serahkan" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">{{ $o->reservationRoom ? 'Sudah diantar ke kamar' : 'Sudah diambil' }}</button>
                            @endif
                            @if ($o->payment_mode === 'pay_now' && $o->invoice && ! $o->sudahDibayar() && $o->status !== 'cancelled')
                                <button type="button" wire:click="bayarTunai" wire:confirm="Catat pembayaran tunai {{ $rp($o->invoice->total_amount) }}?" class="wt-btn-sm">Terima tunai {{ $rp($o->invoice->total_amount) }}</button>
                            @endif
                            @if ($waUlang && $o->customer?->phone)
                                <a href="{{ $waUlang }}" target="_blank" rel="noopener" class="wt-btn-sm hover:no-underline">Kabari tamu (WhatsApp)</a>
                            @endif
                            @if ($o->access_token)
                                <a href="{{ $o->urlStatus() }}" target="_blank" rel="noopener" class="wt-btn-sm hover:no-underline">Halaman tamu</a>
                            @endif
                        </div>
                        @error('status')<p class="m-0 mt-3 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror

                        @if ($aksi === 'timbang')
                            <form wire:submit="timbang" class="mt-4 grid gap-3 rounded border border-cream-300 bg-cream-100 p-4 sm:grid-cols-[1fr_auto] sm:items-end" novalidate>
                                <x-staf.isian nama="berat" label="Berat hasil timbang (kg)" :bantuan="'Ditagih minimal '.LaundryService::kg(LaundryService::MIN_KG).' · '.$rp($item?->unit_price).'/kg'">
                                    <input type="text" inputmode="decimal" wire:model="berat" placeholder="Contoh: 2,5" class="wt-input {{ $galat('berat') }}" autofocus>
                                </x-staf.isian>
                                <div class="flex gap-2 sm:mb-[22px]"><button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Simpan</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button></div>
                            </form>
                        @elseif ($aksi === 'batal')
                            <form wire:submit="batalkan" class="mt-4 flex flex-col gap-3 rounded border border-cream-300 bg-cream-100 p-4" novalidate>
                                <x-staf.isian nama="alasan" label="Alasan pembatalan"><input type="text" wire:model="alasan" maxlength="150" class="wt-input {{ $galat('alasan') }}"></x-staf.isian>
                                <div class="flex gap-2"><button class="h-10 rounded border-0 bg-status-dirty px-4 font-display text-xs font-bold text-cream-50">Batalkan pesanan</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Tidak</button></div>
                            </form>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Terima laundry langsung di resepsionis atau dari kamar --}}
    @if ($formTerima)
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.set('formTerima', false)">
            <div wire:click="$set('formTerima', false)" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <form wire:submit="terima" role="dialog" aria-modal="true" aria-labelledby="terima-judul" novalidate class="relative flex max-h-[92vh] w-[520px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div id="terima-judul" class="flex-1 font-display text-[17px] font-extrabold uppercase tracking-[.04em]">Terima laundry</div>
                    <button type="button" wire:click="$set('formTerima', false)" class="flex h-8 w-8 items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                    <div class="inline-flex self-start rounded-lg border border-cream-300 bg-cream-50 p-0.5">
                        @foreach (['kamar' => 'Tamu menginap', 'luar' => 'Tamu luar'] as $v => $l)
                            <button type="button" wire:click="$set('jenisBaru', '{{ $v }}')" @class(['h-8 rounded-md border-0 px-3.5 text-[13px] font-semibold', 'bg-brown-800 text-cream-50' => $jenisBaru === $v, 'bg-transparent text-ink-500' => $jenisBaru !== $v])>{{ $l }}</button>
                        @endforeach
                    </div>
                    @if ($jenisBaru === 'kamar')
                        <x-staf.isian nama="kamarBaru" label="Kamar" tag="div">
                            <x-staf.pilih model="$wire.kamarBaru" label="Kamar" :galat="$errors->has('kamarBaru') ? 'true' : null" :pilihan="['' => 'Pilih kamar terisi'] + $kamarTerisi->all()" />
                        </x-staf.isian>
                        <x-staf.isian nama="tagihBaru" label="Pembayaran" tag="div">
                            <div class="flex gap-4 text-[13px]">
                                <label class="flex items-center gap-2"><input type="radio" wire:model="tagihBaru" value="kamar" class="accent-brown-800"> Tagihkan ke kamar</label>
                                <label class="flex items-center gap-2"><input type="radio" wire:model="tagihBaru" value="sekarang" class="accent-brown-800"> Bayar sekarang</label>
                            </div>
                        </x-staf.isian>
                    @else
                        <x-staf.isian nama="namaBaru" label="Nama tamu"><input type="text" wire:model="namaBaru" class="wt-input {{ $galat('namaBaru') }}"></x-staf.isian>
                        <x-staf.isian nama="hpBaru" label="Nomor WhatsApp"><input type="tel" wire:model="hpBaru" placeholder="08xxxxxxxxxx" class="wt-input {{ $galat('hpBaru') }}"></x-staf.isian>
                    @endif
                    <x-staf.isian nama="beratBaru" label="Berat (kg, opsional)" :bantuan="'Isi bila sudah ditimbang. Tarif '.$rp($harga).'/kg, minimal '.LaundryService::kg(LaundryService::MIN_KG).'.'">
                        <input type="text" inputmode="decimal" wire:model="beratBaru" placeholder="Contoh: 3,2" class="wt-input {{ $galat('beratBaru') }}">
                    </x-staf.isian>
                    <x-staf.isian nama="catatanBaru" label="Catatan (opsional)"><input type="text" wire:model="catatanBaru" maxlength="200" class="wt-input"></x-staf.isian>
                    @error('layanan')<p class="m-0 text-[13px] text-status-dirty">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-none gap-2 border-t border-cream-300 px-6 py-4">
                    <button type="submit" class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600">Simpan</button>
                    <button type="button" wire:click="$set('formTerima', false)" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
