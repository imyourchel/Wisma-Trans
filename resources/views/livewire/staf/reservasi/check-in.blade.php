@php
    use App\Services\Reservasi\ReservasiService;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <label class="relative block min-w-0 flex-[1_1_240px] sm:max-w-[320px]">
            <span class="sr-only">Cari reservasi</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Kode reservasi atau nama tamu" class="wt-input pl-9">
        </label>
        <span class="text-[13px] text-ink-500">{{ $daftar->count() }} reservasi menunggu check-in · {{ today()->translatedFormat('l, j F Y') }}</span>
        <span wire:poll.60s class="ml-auto text-[12px] text-ink-500">Diperbarui {{ now()->format('H.i') }} WITA</span>
    </div>

    {{-- Verifikasi bukti pemesanan instansi: kode bukti disebutkan atau ditunjukkan tamu, lalu diketik di sini --}}
    <div class="wt-card mb-4 p-4" x-data x-on:fokus-kode-bukti.window="$nextTick(() => $refs.kodeBukti?.focus())">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-[1_1_100%] sm:flex-[0_0_auto]">
                <div class="font-display text-[13px] font-bold uppercase tracking-[.06em]">Verifikasi bukti instansi</div>
                <p class="m-0 mt-0.5 text-xs text-ink-500">
                    @if ($kodeUntuk)
                        Untuk <span class="font-semibold text-ink-900">{{ $kodeUntuk }}</span> ·
                        <button type="button" wire:click="lepasVerifikasi" class="border-0 bg-transparent p-0 text-xs font-semibold text-clay-600 hover:underline">reservasi lain</button>
                    @else
                        Ketik kode bukti yang disebutkan atau ditunjukkan tamu instansi.
                    @endif
                </p>
            </div>
            <form wire:submit="verifikasi" novalidate class="flex min-w-0 flex-1 flex-wrap items-end gap-3">
                <x-staf.isian nama="kodeBukti" label="Kode bukti" class="w-[200px]">
                    <input type="text" wire:model="kodeBukti" x-ref="kodeBukti" maxlength="12" placeholder="Contoh: LK7Q-2M9X" autocapitalize="characters" spellcheck="false" autocomplete="off"
                           @class(['wt-input uppercase tabular-nums tracking-[.08em] placeholder:normal-case placeholder:tracking-normal', 'is-invalid' => $errors->has('kodeBukti')])>
                </x-staf.isian>
                <button type="submit" wire:loading.attr="disabled" wire:target="verifikasi" class="h-10 rounded border-0 bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900 disabled:opacity-70">Verifikasi</button>
            </form>
        </div>

        @if ($hasil)
            <div @class(['mt-3 flex flex-wrap items-center gap-3 rounded-md border px-4 py-3 text-[13px]',
                    'border-[rgba(92,158,103,.35)] bg-[rgba(92,158,103,.10)] text-ink-900' => $hasil['bisaCheckIn'],
                    'border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] text-[#7A520C]' => $hasil['sah'] && ! $hasil['bisaCheckIn'],
                    'border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] text-status-dirty' => ! $hasil['sah']]) role="status" data-hasil-bukti>
                <span class="min-w-0 flex-1 font-semibold">{{ $hasil['pesan'] }}</span>
                @if ($hasil['bisaCheckIn'] && $bolehKelola)
                    <button type="button" wire:click="open({{ $hasil['id'] }})" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Check-in</button>
                @endif
            </div>
        @endif
    </div>

    @if ($masaEarly)
        <p class="mb-4 rounded bg-[rgba(232,172,69,.14)] px-4 py-3 text-[13px] text-[#7A520C]" data-banner-early>Check-in normal mulai pukul {{ $jamCheckIn }} WITA. Tamu yang datang lebih awal bisa langsung masuk bila kamarnya sudah Siap (early check-in).</p>
    @endif

    @if ($daftar->isEmpty())
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">Tidak ada reservasi yang menunggu check-in{{ $cari !== '' ? ' dengan kata kunci itu' : ' hari ini' }}.</div>
    @else
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($daftar as $x)
                @php
                    $baris = $x->rooms->where('status', 'booked');
                    $terlambat = $x->masuk()?->lt(today());
                    $nota = $x->stayInvoice;
                @endphp
                <div wire:key="ci-{{ $x->id }}" class="wt-card flex flex-col p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-display text-[15px] font-extrabold tabular-nums">{{ $x->reservation_code }}</div>
                            <div class="truncate text-[13px] font-semibold">{{ $x->customer?->full_name }}</div>
                            <div class="truncate text-[12px] text-ink-500">{{ $x->institution?->name ?? $x->customer?->phone }}</div>
                        </div>
                        @if ($terlambat)
                            <span class="wt-lencana bg-[rgba(212,81,74,.12)] text-status-dirty">Datang {{ $x->masuk()->translatedFormat('j M') }}</span>
                        @else
                            <span class="wt-lencana bg-[rgba(108,149,196,.16)] text-[#3F6D9E]">Hari ini</span>
                        @endif
                    </div>
                    <ul class="m-0 mt-3 flex list-none flex-col gap-1 p-0 text-[13px]">
                        @foreach ($baris as $b)
                            <li class="flex justify-between gap-3">
                                <span>{{ $b->roomType->name }}{{ $b->room ? ' · '.$b->room->bedTypeLabel() : '' }}@if ($t = \App\Services\Reservasi\TambahanKamar::teks($b))<span class="block text-[12px] font-medium text-clay-600" data-tambahan-checkin>+ {{ $t }}</span>@endif</span>
                                <span @class(['tabular-nums', 'font-semibold' => $b->room, 'text-ink-500' => ! $b->room])>{{ $b->room ? 'Kamar '.$b->room->room_number : 'belum ditetapkan' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-3 border-t border-cream-300 pt-3 text-[12px] text-ink-500">
                        {{ ReservasiService::rentang($x->masuk(), $x->keluar()) }}
                        @if ($nota) · sisa bayar <span class="font-semibold text-ink-900">{{ $rp(max(0, (float) $nota->total_amount - (float) $nota->paid_amount)) }}</span>@endif
                        @if ($bagiTamu = $x->catatanPembagian())<div class="mt-1 text-ink-900" data-pembagian-tamu>Pembagian tamu: {{ $bagiTamu }}</div>@endif
                        @if (($catatanTamu = $x->catatanTamu(tanpaInstansi: false)) !== '')<div class="mt-1 line-clamp-2 italic text-ink-900">“{{ $catatanTamu }}”</div>@endif
                    </div>
                    @if ($bolehKelola && $x->instansi() && $buktiUntuk !== $x->id)
                        {{-- Reservasi instansi: check-in hanya setelah bukti pemesanan dari PIC diverifikasi --}}
                        <button type="button" wire:click="mulaiVerifikasi({{ $x->id }})" class="mt-3 h-10 rounded border border-brown-800 bg-transparent font-display text-xs font-bold text-brown-800 hover:bg-cream-200">Verifikasi bukti</button>
                    @elseif ($bolehKelola)
                        <button type="button" wire:click="open({{ $x->id }})" class="mt-3 h-10 rounded border-0 bg-clay-500 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Proses check-in</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($r)
        <div class="wt-modal fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <form wire:submit="simpan" role="dialog" aria-modal="true" aria-labelledby="ci-judul" novalidate
                  class="wt-dialog relative flex max-h-[92dvh] w-[560px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <div id="ci-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">Check-in {{ $r->reservation_code }}</div>
                            @if ($early)
                                <span class="wt-lencana bg-[rgba(232,172,69,.18)] text-[#7A520C]" data-lencana-early>Early check-in</span>
                            @endif
                        </div>
                        <div class="mt-0.5 text-[13px] text-ink-500">{{ $r->customer?->full_name }} · {{ ReservasiService::rentang($r->masuk(), $r->keluar()) }}</div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup">
                        <x-staf.ikon nama="tutup" ukuran="16" />
                    </button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                    @foreach ($r->rooms->where('status', 'booked') as $b)
                        @php
                            $mau = $mauKasur[$b->id] ?? null;
                            $kasurPilih = $kasurKamar[$b->id][(int) ($penempatan[$b->id] ?? 0)] ?? null;
                            $beda = $mau && $kasurPilih && $kasurPilih !== $mau;
                        @endphp
                        <x-staf.isian :nama="'kamar.'.$b->id" :label="'Kamar untuk '.$b->roomType->name.($mau ? ' · '.\App\Models\Room::labelKasur($mau) : '')" tag="div" wire:key="tempat-{{ $b->id }}">
                            <x-staf.pilih model="$wire.penempatan[{{ $b->id }}]" :nilai="$penempatan[$b->id] ?? ''" label="Nomor kamar"
                                          :galat="$errors->has('kamar.'.$b->id) ? 'true' : null"
                                          :pilihan="['' => 'Pilih nomor kamar'] + ($pilihanKamar[$b->id] ?? [])" />
                            @isset($usulan[$b->id])
                                <span class="mt-1.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-500" data-usulan>
                                    <x-staf.ikon nama="bintang" ukuran="14" class="text-clay-500" /> Usulan: Kamar {{ $usulan[$b->id]['nomor'] }} ({{ $usulan[$b->id]['alasan'] }})
                                    @if (($penempatan[$b->id] ?? '') !== (string) $usulan[$b->id]['id'])
                                        <button type="button" wire:click="$set('penempatan.{{ $b->id }}', '{{ $usulan[$b->id]['id'] }}')" class="border-0 bg-transparent p-0 text-xs font-semibold text-clay-600 hover:underline">Pakai</button>
                                    @endif
                                </span>
                            @endisset
                            @if ($mau)
                                {{-- Diperbarui langsung di peramban saat FO memilih nomor kamar lain --}}
                                <span x-data="{ kasur: @js($kasurKamar[$b->id] ?? []) }" data-peringatan-kasur="{{ $beda ? 'beda' : 'sama' }}"
                                      x-show="kasur[$wire.penempatan[{{ $b->id }}]] && kasur[$wire.penempatan[{{ $b->id }}]] !== @js($mau)" @style(['display: none' => ! $beda])
                                      class="mt-1.5 flex items-center gap-1.5 rounded bg-[rgba(232,172,69,.14)] px-2.5 py-1.5 text-xs font-medium text-[#7A520C]" role="status">
                                    Jenis kasur berbeda dari pesanan tamu ({{ \App\Models\Room::labelKasur($mau) }})
                                </span>
                                <span x-data="{ kurang: @js($kurangMuat[$b->id] ?? []) }" data-peringatan-kapasitas
                                      x-show="kurang[$wire.penempatan[{{ $b->id }}]]" @style(['display: none' => ! ($kurangMuat[$b->id][(int) ($penempatan[$b->id] ?? 0)] ?? false)])
                                      class="mt-1.5 flex items-center gap-1.5 rounded bg-[rgba(212,81,74,.1)] px-2.5 py-1.5 text-xs font-medium text-status-dirty" role="status">
                                    Kamar ini menampung lebih sedikit tamu dari pesanan (maks {{ $b->roomType->teksKapasitas($mau) }})
                                </span>
                            @endif
                        </x-staf.isian>
                    @endforeach
                    @if ($early)
                        <p class="m-0 text-xs text-ink-500">Early check-in sebelum pukul {{ $jamCheckIn }} WITA tanpa biaya tambahan. Hanya kamar berstatus Siap yang ditampilkan.</p>
                    @else
                        <p class="m-0 text-xs text-ink-500">Hanya kamar berstatus Siap yang bisa dipakai. Kamar yang belum siap perlu dibersihkan dulu oleh Housekeeping.</p>
                    @endif

                    <div class="flex flex-col gap-3 border-t border-cream-300 pt-4">
                        <label class="flex items-center gap-2.5 text-[13px]">
                            <input type="checkbox" wire:model.live="ktpDitahan" class="h-4 w-4 accent-brown-800">
                            KTP tamu ditahan selama menginap
                        </label>
                        @if ($ktpDitahan)
                            <x-staf.isian nama="nama_ktp" label="Nama sesuai KTP" bantuan="Boleh berbeda dari nama pemesan.">
                                <input type="text" wire:model="namaKtp" maxlength="100" @class(['wt-input', 'is-invalid' => $errors->has('nama_ktp')])>
                            </x-staf.isian>
                        @endif
                    </div>
                    @error('status')<p class="m-0 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-none gap-2 border-t border-cream-300 px-6 py-4">
                    <button type="submit" wire:loading.attr="disabled" wire:target="simpan" class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">
                        <span wire:loading.remove wire:target="simpan">Check-in sekarang</span><span wire:loading wire:target="simpan">Memproses…</span>
                    </button>
                    <button type="button" wire:click="close" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
