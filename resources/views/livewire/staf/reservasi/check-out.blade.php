@php
    use App\Services\FolioService;
    use App\Services\ReservasiService;

    $rp = fn ($n) => FolioService::rp($n);
    $warna = ['terlambat' => '#D4514A', 'hari-ini' => '#D69A2E', 'menginap' => '#5B86B8'];
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <label class="relative block min-w-0 flex-[1_1_240px] sm:max-w-[320px]">
            <span class="sr-only">Cari tamu menginap</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Nomor kamar, kode, atau nama" class="wt-input pl-9">
        </label>
        <span class="text-[13px] text-ink-500">{{ $jumlahTamu }} reservasi menginap · batas check-out pukul {{ $jamKeluar }} WITA</span>
        <span wire:poll.60s class="ml-auto text-[12px] text-ink-500">Diperbarui {{ now()->format('H.i') }} WITA</span>
    </div>

    @if ($jumlahTamu === 0)
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">Tidak ada tamu menginap{{ $cari !== '' ? ' dengan kata kunci itu' : '' }}.</div>
    @else
        <div class="flex flex-col gap-6">
            @foreach ($kelompok as $k => $g)
                @continue($g['isi']->isEmpty())
                <section id="{{ $k }}" class="scroll-mt-20" aria-label="{{ $g['label'] }}">
                    <h2 class="m-0 mb-2.5 flex items-center gap-2 font-display text-[12px] font-bold uppercase tracking-[.08em]">
                        <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $warna[$k] }}"></span>
                        {{ $g['label'] }}
                        <span class="rounded-full bg-cream-200 px-2 py-0.5 text-[11px] tabular-nums text-ink-500">{{ $g['isi']->count() }}</span>
                    </h2>
                    <div class="wt-card overflow-x-auto shadow-wt-xs">
                        <table class="w-full min-w-[720px] border-collapse">
                            <thead><tr><th class="wt-th">Kamar</th><th class="wt-th">Tamu</th><th class="wt-th">Reservasi</th><th class="wt-th">Keluar</th><th class="wt-th text-right">Sisa tagihan</th></tr></thead>
                            <tbody>
                                @foreach ($g['isi'] as $x)
                                    <tr wire:key="co-{{ $x['r']->id }}" wire:click="open({{ $x['r']->id }})" class="cursor-pointer hover:bg-row-hover">
                                        <td class="wt-td font-display text-[15px] font-extrabold tabular-nums">{{ $x['kamar'] ?: '—' }}</td>
                                        <td class="wt-td">
                                            <div class="font-semibold">{{ $x['r']->customer?->full_name }}</div>
                                            @if ($x['r']->institution)<div class="text-[11px] text-ink-500">{{ $x['r']->institution->name }}</div>@endif
                                        </td>
                                        <td class="wt-td tabular-nums text-[13px]">{{ $x['r']->reservation_code }}</td>
                                        <td @class(['wt-td whitespace-nowrap text-[13px]', 'font-semibold text-status-dirty' => $k === 'terlambat'])>
                                            {{ \Illuminate\Support\Carbon::parse($x['keluar'])->translatedFormat('j M Y') }}
                                        </td>
                                        <td class="wt-td text-right tabular-nums">
                                            @if ($x['sisa'] > 0)
                                                <span class="font-semibold">{{ $rp($x['sisa']) }}</span>
                                            @elseif ($x['piutang'] > 0)
                                                <span class="text-[12px] text-ink-500">Piutang {{ $rp($x['piutang']) }}</span>
                                            @else
                                                <span class="wt-lencana bg-[rgba(92,158,103,.16)] text-status-available">Lunas</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    @if ($r && $f)
        @php
            $menginap = $r->rooms->where('status', 'checked_in');
            $dipilih = $menginap->filter(fn ($b) => $kamar[$b->id] ?? false);
            $awal = $dipilih->filter(fn ($b) => today()->lt($b->check_out_date));
            $telat = $dipilih->filter(fn ($b) => now()->gt(FolioService::batasKeluar($b)));
            $selesai = $r->status !== 'checked_in';
            $segera = $f['nota']->filter(fn ($n) => $n['termin'] === 'immediate' && $n['sisa'] > 0);
            $namaNota = ['stay' => 'Nota kamar', 'service' => 'Nota layanan'];
        @endphp
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="co-judul"
                 class="relative flex max-h-[94vh] w-[760px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="co-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $selesai ? 'Check-out selesai' : 'Check-out' }} {{ $r->reservation_code }}</div>
                        <div class="mt-0.5 truncate text-[13px] text-ink-500">
                            {{ $r->customer?->full_name }}{{ $r->institution ? ' · '.$r->institution->name : '' }} · {{ ReservasiService::rentang($r->masuk(), $r->keluar()) }}
                        </div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup">
                        <x-staf.ikon nama="tutup" ukuran="16" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    {{-- Kamar yang keluar --}}
                    @if (! $selesai && $menginap->count() > 1)
                        <fieldset class="m-0 border-0 p-0">
                            <legend class="wt-field-label mb-2">Kamar yang check-out</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($menginap as $b)
                                    <label wire:key="pilih-{{ $b->id }}" class="flex cursor-pointer items-center gap-2 rounded border border-cream-300 bg-cream-100 px-3 py-2 text-[13px] has-[:checked]:border-brown-800">
                                        <input type="checkbox" wire:model.live="kamar.{{ $b->id }}" class="h-4 w-4 accent-brown-800">
                                        <span class="font-semibold">Kamar {{ $b->room?->room_number }}</span>
                                        <span class="text-ink-500">keluar {{ $b->check_out_date->translatedFormat('j M') }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @if (! $f['akhir'])
                                <p class="m-0 mt-2 text-xs text-ink-500">Check-out sebagian: reservasi tetap menginap, layanan digabung ke nota saat kamar terakhir keluar.</p>
                            @endif
                            @error('kamar')<p class="m-0 mt-1 text-xs font-medium text-status-dirty">{{ $message }}</p>@enderror
                        </fieldset>
                    @endif

                    {{-- Keluar lebih awal --}}
                    @if (! $selesai && $awal->isNotEmpty())
                        <fieldset class="m-0 rounded border border-cream-300 bg-cream-100 p-4">
                            <legend class="px-1 font-display text-[12px] font-bold uppercase tracking-[.06em]">Keluar lebih awal</legend>
                            <p class="m-0 mb-2 text-[13px] text-ink-500">{{ $awal->map(fn ($b) => 'Kamar '.$b->room?->room_number.' dipesan sampai '.$b->check_out_date->translatedFormat('j M'))->join(', ') }}.</p>
                            <div class="flex flex-wrap gap-x-5 gap-y-2 text-[13px]">
                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="keluarAwal" value="dipakai" class="h-4 w-4 accent-brown-800">Tagih malam yang dipakai</label>
                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="keluarAwal" value="pesanan" class="h-4 w-4 accent-brown-800">Tagih sesuai pesanan</label>
                            </div>
                        </fieldset>
                    @endif

                    {{-- Terlambat --}}
                    @if (! $selesai && $telat->isNotEmpty())
                        <fieldset class="m-0 rounded border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.05)] p-4">
                            <legend class="px-1 font-display text-[12px] font-bold uppercase tracking-[.06em] text-status-dirty">Terlambat check-out</legend>
                            <div class="flex flex-col gap-3">
                                @foreach ($telat as $b)
                                    <div wire:key="telat-{{ $b->id }}" class="flex flex-col gap-2">
                                        <div class="flex flex-wrap items-center gap-3 text-[13px]">
                                            <span class="flex-1">Kamar {{ $b->room?->room_number }} lewat batas {{ FolioService::batasKeluar($b)->translatedFormat('j M, H.i') }}, biaya <strong>{{ $rp(FolioService::biayaTerlambat()) }}</strong></span>
                                            <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="bebas.{{ $b->id }}" class="h-4 w-4 accent-brown-800">Bebaskan</label>
                                        </div>
                                        @if ($bebas[$b->id] ?? false)
                                            <x-staf.isian :nama="'alasan.'.$b->id" label="Alasan pembebasan">
                                                <input type="text" wire:model="alasan.{{ $b->id }}" maxlength="150" placeholder="Contoh: menunggu jemputan, disetujui Manager" @class(['wt-input', 'is-invalid' => $errors->has('alasan.'.$b->id)])>
                                            </x-staf.isian>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    {{-- Folio --}}
                    <x-tagihan-sementara :folio="$f" :staf="true" />

                    @if ($f['kredit'])
                        <p class="m-0 rounded bg-[rgba(108,149,196,.12)] px-4 py-3 text-[13px] text-[#3F6D9E]" data-kredit>{{ $f['kredit'] }}</p>
                    @endif

                    {{-- Kelebihan bayar --}}
                    @foreach ($f['nota']->filter(fn ($n) => $n['lebih'] > 0 && $n['model']) as $kunci => $n)
                        <div wire:key="lebih-{{ $kunci }}" class="flex flex-wrap items-center gap-3 rounded border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] px-4 py-3 text-[13px] text-[#7A520C]" role="status">
                            <span class="min-w-0 flex-1 font-semibold">Kembalikan {{ $rp($n['lebih']) }} ke tamu</span>
                            @if ($bolehKelola)
                                <select wire:model="metode" class="wt-input h-9 w-[150px]" aria-label="Metode pengembalian">
                                    @foreach ($metodeBayar as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                </select>
                                <button type="button" wire:click="kembalikan('{{ $kunci }}')" wire:loading.attr="disabled" class="h-9 rounded border-0 bg-brown-800 px-4 text-xs font-bold text-cream-50 hover:bg-brown-900">Catat pengembalian</button>
                            @endif
                        </div>
                    @endforeach

                    {{-- Penghalang dan peringatan --}}
                    @if ($f['penghalang'] !== [] && ! $selesai)
                        <ul class="m-0 flex list-none flex-col gap-1.5 rounded border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] px-4 py-3 text-[13px] text-status-dirty" role="alert" data-penghalang>
                            @foreach ($f['penghalang'] as $p)<li class="font-semibold">{{ $p }}</li>@endforeach
                        </ul>
                    @endif
                    @if ($f['peringatan'] !== [] && ! $selesai)
                        <ul class="m-0 flex list-none flex-col gap-1.5 rounded border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.10)] px-4 py-3 text-[13px] text-[#7A520C]" data-peringatan>
                            @foreach ($f['peringatan'] as $p)
                                <li class="flex flex-wrap items-center gap-x-3">
                                    <span>{{ $p['teks'] }}</span>
                                    @if ($p['url'])<a href="{{ $p['url'] }}" class="font-semibold text-clay-600">{{ $p['tautan'] }}</a>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Pembayaran --}}
                    @if (! $selesai && $bolehKelola && $segera->isNotEmpty())
                        <section class="rounded border border-cream-300 p-4" aria-labelledby="co-bayar">
                            <h3 id="co-bayar" class="m-0 mb-3 font-display text-[12px] font-bold uppercase tracking-[.06em]">Pembayaran</h3>
                            @if ($qris)
                                @include('livewire.staf.partials.qris-meja')
                            @else
                                <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                    @if ($segera->count() > 1)
                                        <x-staf.isian nama="notaBayar" label="Nota" class="sm:col-span-3">
                                            <select wire:model.live="notaBayar" class="wt-input">
                                                @foreach ($segera as $kunci => $n)<option value="{{ $kunci }}">{{ $namaNota[$kunci] }} · sisa {{ $rp($n['sisa']) }}</option>@endforeach
                                            </select>
                                        </x-staf.isian>
                                    @endif
                                    @php $sisaPilih = $segera[$notaBayar]['sisa'] ?? $segera->first()['sisa']; @endphp
                                    <x-staf.isian nama="jumlah" label="Jumlah" :bantuan="'Kosongkan untuk melunasi '.$rp($sisaPilih)">
                                        <input type="text" wire:model="jumlah" inputmode="numeric" placeholder="{{ number_format($sisaPilih, 0, ',', '.') }}" @class(['wt-input tabular-nums', 'is-invalid' => $errors->has('jumlah')])>
                                    </x-staf.isian>
                                    <x-staf.isian nama="metode" label="Metode">
                                        <select wire:model="metode" class="wt-input">
                                            @foreach ($metodeBayar as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                        </select>
                                    </x-staf.isian>
                                    <div class="flex gap-2 sm:pb-[22px]">
                                        <button type="button" wire:click="bayar" wire:loading.attr="disabled" wire:target="bayar" class="h-10 rounded border-0 bg-brown-800 px-4 text-xs font-bold text-cream-50 hover:bg-brown-900 disabled:opacity-70">Catat pembayaran</button>
                                        @if ($midtransSiap)
                                            <button type="button" wire:click="mulaiQris" wire:loading.attr="disabled" wire:target="mulaiQris" class="wt-btn-sm h-10">QRIS (Midtrans)</button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </section>
                    @endif

                    {{-- KTP --}}
                    @if (! $selesai && $r->id_card_held && $f['akhir'])
                        <label class="flex items-center gap-2.5 rounded border border-cream-300 bg-cream-100 px-4 py-3 text-[13px]">
                            <input type="checkbox" wire:model="ktpKembali" class="h-4 w-4 accent-brown-800">
                            KTP sudah dikembalikan{{ $r->id_card_holder_name ? ' (atas nama '.$r->id_card_holder_name.')' : '' }}
                        </label>
                        @error('ktp')<p class="m-0 -mt-3 text-xs font-medium text-status-dirty">{{ $message }}</p>@enderror
                    @endif

                    @error('status')<p class="m-0 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror
                    @if ($qris || $selesai || $segera->isEmpty() || ! $bolehKelola)
                        @error('jumlah')<p class="m-0 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div class="flex flex-none flex-wrap gap-2 border-t border-cream-300 px-6 py-4">
                    @if ($selesai)
                        @if ($urlNota)<a href="{{ $urlNota }}" target="_blank" rel="noopener" class="wt-btn-sm h-11 hover:no-underline">Nota PDF</a>@endif
                        <button type="button" wire:click="close" class="ml-auto h-11 rounded border-0 bg-brown-800 px-6 text-[13px] font-semibold text-cream-50 hover:bg-brown-900">Tutup</button>
                    @else
                        @if ($bolehKelola)
                            <button type="button" wire:click="checkOut" wire:loading.attr="disabled" wire:target="checkOut" @disabled($f['penghalang'] !== [] || $dipilih->isEmpty())
                                    class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:cursor-not-allowed disabled:opacity-50">
                                <span wire:loading.remove wire:target="checkOut">{{ $f['akhir'] ? 'Check-out' : 'Check-out '.$dipilih->count().' kamar' }}</span><span wire:loading wire:target="checkOut">Memproses…</span>
                            </button>
                        @endif
                        <button type="button" wire:click="close" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Tutup</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
