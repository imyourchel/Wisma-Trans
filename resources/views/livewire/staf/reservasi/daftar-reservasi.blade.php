@php
    use App\Models\Reservation;
    use App\Models\ReservationLog;
    use App\Services\ReservasiService;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $ringkasKamar = fn (Reservation $x) => $x->rooms->where('status', '!=', 'moved')->isEmpty()
        ? ($x->request ? $x->request->room_count.' kamar diajukan' : '—')
        : $x->rooms->whereNotIn('status', ['moved'])->map(fn ($k) => $k->room?->room_number ?? $k->roomType->code)->join(', ');
@endphp

<div>
    {{-- Saringan --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <label class="relative block min-w-0 flex-[1_1_240px] sm:max-w-[320px]">
            <span class="sr-only">Cari reservasi</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Kode, nama, HP, atau instansi" class="wt-input pl-9">
        </label>
        <x-staf.pilih model="$wire.status" x-on:change="$wire.$commit()" label="Saring status" class="w-[210px]" :nilai="$status"
                      :pilihan="['aktif' => 'Belum selesai', '' => 'Semua status'] + Reservation::STATUS" />
        <x-staf.pilih model="$wire.jenis" x-on:change="$wire.$commit()" label="Saring jenis" class="w-[190px]" :nilai="$jenis"
                      :pilihan="['' => 'Semua jenis', 'perorangan' => 'Online perorangan', 'langsung' => 'Datang langsung', 'instansi' => 'Instansi']" />
        <span class="whitespace-nowrap text-[13px] text-ink-500">{{ $daftar->total() }} reservasi</span>
        @if ($bolehKelola)
            <a href="{{ route('staf.reservasi-langsung') }}" class="ml-auto inline-flex h-10 items-center gap-2 whitespace-nowrap rounded bg-clay-500 px-4 font-display text-[13px] font-semibold text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">
                <x-staf.ikon nama="tambah" ukuran="16" />Reservasi langsung
            </a>
        @endif
    </div>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[960px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Pemesan</th>
                    <th class="wt-th">Menginap</th>
                    <th class="wt-th">Kamar</th>
                    <th class="wt-th">Status</th>
                    <th class="wt-th text-right">Total kamar</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($daftar as $x)
                    @php $m = $x->masuk(); $k = $x->keluar(); @endphp
                    <tr wire:key="rsv-{{ $x->id }}" class="cursor-pointer hover:bg-row-hover" wire:click="open({{ $x->id }})">
                        <td class="wt-td">
                            <div class="font-semibold tabular-nums">{{ $x->reservation_code }}</div>
                            <div class="text-[11px] text-ink-500">{{ $x->booking_channel === 'walk_in' ? 'Datang langsung' : ($x->request ? 'Instansi' : 'Online') }}</div>
                        </td>
                        <td class="wt-td">
                            <div class="font-semibold">{{ $x->customer?->full_name }}</div>
                            <div class="text-[11px] text-ink-500">{{ $x->institution?->name ?? $x->customer?->phone }}</div>
                        </td>
                        <td class="wt-td whitespace-nowrap">
                            <div>{{ ReservasiService::rentang($m, $k) }}</div>
                            <div class="text-[11px] text-ink-500">{{ $m && $k ? $m->diffInDays($k).' malam' : '' }}</div>
                        </td>
                        <td class="wt-td max-w-[200px] truncate tabular-nums">{{ $ringkasKamar($x) }}</td>
                        <td class="wt-td"><x-staf.status-reservasi :status="$x->status" /></td>
                        <td class="wt-td text-right tabular-nums">{{ $x->stayInvoice ? $rp($x->stayInvoice->total_amount) : '—' }}</td>
                        <td class="wt-td text-right"><button type="button" class="wt-btn-sm">Detail</button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada reservasi yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $daftar->links('partials.halaman') }}

    {{-- Detail reservasi --}}
    @if ($r)
        @php
            $m = $r->masuk(); $k = $r->keluar();
            $nota = $r->stayInvoice;
            $kamar = $r->rooms->sortBy(fn ($b) => [$b->check_in_date->toDateString(), $b->id]);
        @endphp
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="detail-rsv-judul"
                 class="relative flex max-h-[92vh] w-[720px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="detail-rsv-judul" class="flex flex-wrap items-center gap-2.5">
                            <span class="font-display text-[17px] font-extrabold uppercase leading-tight tracking-[.04em]">{{ $r->reservation_code }}</span>
                            <x-staf.status-reservasi :status="$r->status" />
                        </div>
                        <div class="mt-1 text-[13px] text-ink-500">{{ $r->customer?->full_name }}{{ $r->institution ? ' · '.$r->institution->name : '' }} · {{ $r->customer?->phone }}</div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup">
                        <x-staf.ikon nama="tutup" ukuran="16" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <dl class="m-0 grid grid-cols-2 gap-x-6 gap-y-3 text-[13px] sm:grid-cols-4">
                        <div><dt class="wt-field-label">Check-in</dt><dd class="m-0 mt-1">{{ $m?->translatedFormat('D, j M Y') }}</dd></div>
                        <div><dt class="wt-field-label">Check-out</dt><dd class="m-0 mt-1">{{ $k?->translatedFormat('D, j M Y') }}</dd></div>
                        <div><dt class="wt-field-label">Jenis</dt><dd class="m-0 mt-1">{{ $r->booking_channel === 'walk_in' ? 'Datang langsung' : ($r->request ? 'Instansi' : 'Online') }}</dd></div>
                        <div><dt class="wt-field-label">Token tamu</dt><dd class="m-0 mt-1 font-semibold tabular-nums tracking-[.06em]">{{ $r->token() ?: '—' }}</dd></div>
                        @if ($r->request)
                            <div class="col-span-2"><dt class="wt-field-label">Kegiatan</dt><dd class="m-0 mt-1">{{ $r->request->event_name ?: '—' }} · {{ $r->request->room_count }} kamar diajukan</dd></div>
                        @endif
                        @if ($r->status === 'pending_payment')
                            <div class="col-span-2"><dt class="wt-field-label">Batas bayar uang muka</dt><dd class="m-0 mt-1">{{ $r->dp_due_at?->translatedFormat('j M Y, H.i') }} WITA</dd></div>
                        @endif
                        @if ($r->id_card_held)
                            <div class="col-span-2"><dt class="wt-field-label">KTP ditahan</dt><dd class="m-0 mt-1">{{ $r->id_card_holder_name }}</dd></div>
                        @endif
                        @if ($r->special_request)
                            <div class="col-span-2 sm:col-span-4"><dt class="wt-field-label">Permintaan / catatan</dt><dd class="m-0 mt-1 whitespace-pre-line">{{ $r->special_request }}</dd></div>
                        @endif
                    </dl>

                    @if ($profilPemesan)
                        <div class="mt-5">@include('livewire.staf.partials.kartu-profil-pelanggan', $profilPemesan)</div>
                    @elseif ($polaRombongan)
                        <div class="mt-5">@include('livewire.staf.partials.kartu-pola-rombongan', $polaRombongan)</div>
                    @endif

                    {{-- Kamar --}}
                    <h3 class="mb-2 mt-6 font-display text-[13px] font-bold uppercase tracking-[.06em] text-ink-500">Kamar</h3>
                    @if ($kamar->isEmpty())
                        <p class="m-0 rounded bg-cream-200 px-4 py-3 text-[13px]">Belum ada kamar. Kamar ditetapkan Manager saat menyetujui pengajuan.</p>
                    @else
                        <div class="overflow-hidden rounded border border-cream-300">
                            @foreach ($kamar as $b)
                                <div wire:key="baris-{{ $b->id }}" @class(['flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-cream-300 px-4 py-3 text-[13px] first:border-t-0', 'opacity-60' => in_array($b->status, ['moved', 'cancelled'], true)])>
                                    <div class="min-w-[140px] flex-1">
                                        <div class="font-semibold">{{ $b->room ? 'Kamar '.$b->room->room_number : 'Belum ditetapkan' }} · {{ $b->roomType->name }}</div>
                                        <div class="text-[12px] text-ink-500">
                                            {{ ReservasiService::rentang($b->check_in_date, $b->check_out_date) }} · {{ $b->malam() }} malam · {{ $rp($b->price_per_night) }}/malam
                                            @if ($b->potongan() > 0) · potongan {{ $rp($b->potongan()) }} @endif
                                        </div>
                                    </div>
                                    <span class="wt-lencana wt-lencana-netral">{{ \App\Models\ReservationRoom::STATUS[$b->status] ?? $b->status }}</span>
                                    @if ($bolehKelola && $b->status === 'checked_in')
                                        <div class="flex gap-1.5">
                                            <button type="button" wire:click="mulai('perpanjang', {{ $b->id }})" class="wt-btn-sm">Perpanjang</button>
                                            <button type="button" wire:click="mulai('pindah', {{ $b->id }})" class="wt-btn-sm">Pindah kamar</button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Nota; selama menginap tampil tagihan sementara (kamar, layanan tagihan kamar, terlambat) --}}
                    @if ($folio)
                        <div class="mt-4">
                            <div class="wt-field-label mb-2">Tagihan sementara</div>
                            <x-tagihan-sementara :folio="$folio" :staf="true" />
                        </div>
                    @elseif ($nota)
                        <dl class="m-0 mt-4 grid grid-cols-3 gap-3 rounded bg-cream-200 px-4 py-3 text-[13px]">
                            <div><dt class="text-[11px] text-ink-500">Total nota {{ $nota->invoice_number }}</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp($nota->total_amount) }}</dd></div>
                            <div><dt class="text-[11px] text-ink-500">Dibayar{{ $r->dp_amount_required ? ' (uang muka '.$rp($r->dp_amount_required).')' : '' }}</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp($nota->paid_amount) }}</dd></div>
                            <div><dt class="text-[11px] text-ink-500">Sisa</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp(max(0, (float) $nota->total_amount - (float) $nota->paid_amount)) }}</dd></div>
                        </dl>
                    @endif

                    {{-- Aksi --}}
                    <div class="mt-5 flex flex-wrap gap-2 border-t border-cream-300 pt-5">
                        @if ($r->status === 'confirmed' && $m && ! $m->isFuture() && $bolehKelola)
                            {{-- Reservasi instansi check-in lewat kode bukti pemesanan --}}
                            <a href="{{ route('staf.check-in', $buktiBerlaku ? ['kode' => $r->reservation_code] : ['cari' => $r->reservation_code]) }}" class="inline-flex h-9 items-center rounded bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">{{ $buktiBerlaku ? 'Verifikasi bukti' : 'Check-in' }}</a>
                        @endif
                        @if ($r->status === 'checked_in')
                            <a href="{{ route('staf.check-out', ['cari' => $r->reservation_code]) }}" class="inline-flex h-9 items-center rounded bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">Check-out</a>
                        @endif
                        @if ($bolehKelola && $buktiBerlaku)
                            <button type="button" wire:click="kirimUlangBukti" wire:loading.attr="disabled" wire:target="kirimUlangBukti" class="wt-btn-sm">Kirim ulang bukti</button>
                        @endif
                        @if ($r->status === 'waiting_approval')
                            <a href="{{ route('staf.pengajuan-instansi') }}" class="wt-btn-sm hover:no-underline">Buka pengajuan instansi</a>
                        @endif
                        @if ($bolehKelola && $bolehUbah)
                            <button type="button" wire:click="mulai('ubah')" class="wt-btn-sm">Ubah tanggal</button>
                        @endif
                        @if ($bolehKelola && $bolehBatal)
                            <button type="button" wire:click="mulai('batal')" class="wt-btn-sm !text-status-dirty">Batalkan</button>
                        @endif
                        @if ($waUlang && $r->customer?->phone)
                            <a href="{{ $waUlang }}" target="_blank" rel="noopener" class="wt-btn-sm hover:no-underline">{{ $r->dpDibayar() > 0 ? 'Kirim ulang nota (WhatsApp)' : 'Kirim ulang konfirmasi (WhatsApp)' }}</a>
                        @endif
                        @if ($urlNota)
                            <a href="{{ $urlNota }}" target="_blank" rel="noopener" class="wt-btn-sm hover:no-underline">Nota PDF</a>
                        @endif
                        @if ($r->access_token)
                            <a href="{{ $r->urlStatus() }}" target="_blank" rel="noopener" class="wt-btn-sm hover:no-underline">Halaman tamu</a>
                        @endif
                    </div>

                    @if ($aksi !== '')
                        <div class="mt-4 rounded border border-cream-300 bg-cream-100 p-4">
                            @if ($aksi === 'ubah')
                                <form wire:submit="simpanUbah" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" novalidate>
                                    <x-staf.isian nama="masuk" label="Check-in baru"><input type="date" wire:model="masuk" min="{{ today()->toDateString() }}" @class(['wt-input', 'is-invalid' => $errors->has('masuk')])></x-staf.isian>
                                    <x-staf.isian nama="keluar" label="Check-out baru"><input type="date" wire:model="keluar" @class(['wt-input', 'is-invalid' => $errors->has('keluar')])></x-staf.isian>
                                    <div class="flex gap-2"><button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Simpan</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button></div>
                                    @error('tanggal')<p class="m-0 text-xs font-medium text-status-dirty sm:col-span-3">{{ $message }}</p>@enderror
                                    @error('kamar')<p class="m-0 text-xs font-medium text-status-dirty sm:col-span-3">{{ $message }}</p>@enderror
                                    <p class="m-0 text-xs text-ink-500 sm:col-span-3">Harga per malam tetap mengikuti harga saat memesan. Perubahan dicatat di riwayat.</p>
                                </form>
                            @elseif ($aksi === 'batal')
                                <form wire:submit="simpanBatal" class="flex flex-col gap-3" novalidate>
                                    <p class="m-0 text-[13px]">
                                        @if ($r->status === 'confirmed' && $r->dpDibayar() > 0)
                                            Uang muka yang dikembalikan: <strong>{{ $rp($pengembalian) }}</strong>{{ $pengembalian > 0 ? '' : ' (uang muka hangus, kurang dari '.(ReservasiService::hariMinimalBatal() + 1).' hari sebelum kedatangan)' }}.
                                        @else
                                            Belum ada uang muka yang dibayar.
                                        @endif
                                    </p>
                                    <x-staf.isian nama="alasan" label="Alasan pembatalan"><input type="text" wire:model="alasan" maxlength="200" placeholder="Contoh: tamu membatalkan lewat telepon" @class(['wt-input', 'is-invalid' => $errors->has('alasan')])></x-staf.isian>
                                    @error('status')<p class="m-0 text-xs font-medium text-status-dirty">{{ $message }}</p>@enderror
                                    <div class="flex gap-2"><button class="h-10 rounded border-0 bg-status-dirty px-4 font-display text-xs font-bold text-cream-50 hover:opacity-90">Batalkan reservasi</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Tidak</button></div>
                                </form>
                            @elseif ($aksi === 'perpanjang')
                                <form wire:submit="simpanPerpanjang" class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" novalidate>
                                    <x-staf.isian nama="keluar" label="Check-out baru"><input type="date" wire:model="keluar" @class(['wt-input', 'is-invalid' => $errors->has('keluar')])></x-staf.isian>
                                    <div class="flex gap-2"><button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Perpanjang</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button></div>
                                    @error('kamar')<p class="m-0 text-xs font-medium text-status-dirty sm:col-span-2">{{ $message }}</p>@enderror
                                    <p class="m-0 text-xs text-ink-500 sm:col-span-2">Malam tambahan memakai harga per malam yang sama.</p>
                                </form>
                            @elseif ($aksi === 'pindah')
                                <form wire:submit="simpanPindah" class="flex flex-col gap-3" novalidate>
                                    <x-staf.isian nama="kamarBaru" label="Pindah ke kamar (siap ditempati)">
                                        <x-staf.pilih model="$wire.kamarBaru" label="Kamar tujuan" :galat="$errors->has('kamarBaru') ? 'true' : null"
                                                      :pilihan="['' => 'Pilih kamar'] + $kamarPindah->mapWithKeys(fn ($x) => [$x->id => 'Kamar '.$x->room_number.' · '.($namaTipe[$x->room_type_id] ?? '')])->all()" />
                                    </x-staf.isian>
                                    <x-staf.isian nama="alasan" label="Alasan (opsional)"><input type="text" wire:model="alasan" maxlength="150" placeholder="Contoh: AC kamar rusak" class="wt-input"></x-staf.isian>
                                    @error('kamar')<p class="m-0 text-xs font-medium text-status-dirty">{{ $message }}</p>@enderror
                                    <div class="flex gap-2"><button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Pindahkan</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button></div>
                                    <p class="m-0 text-xs text-ink-500">Harga dan potongan tetap sama. Kamar lama menjadi kotor dan masuk antrean pembersihan.</p>
                                </form>
                            @endif
                        </div>
                    @endif

                    {{-- Riwayat --}}
                    <h3 class="mb-2 mt-6 font-display text-[13px] font-bold uppercase tracking-[.06em] text-ink-500">Riwayat</h3>
                    <ol class="m-0 flex list-none flex-col p-0">
                        @foreach ($r->logs->sortByDesc(fn ($l) => [$l->changed_at, $l->id]) as $log)
                            <li wire:key="log-{{ $log->id }}" class="flex gap-3 border-t border-cream-300 py-2.5 text-[13px] first:border-t-0">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="font-semibold">{{ $log->event_type === 'status_change' ? (Reservation::STATUS[$log->status] ?? $log->status) : ReservationLog::EVENT[$log->event_type] }}</span>
                                        <span class="text-[12px] tabular-nums text-ink-500">{{ $log->changed_at->translatedFormat('d M Y, H.i') }}</span>
                                    </div>
                                    <div class="text-[12px] text-ink-500">oleh {{ $log->changedBy?->name ?? 'Sistem atau tamu' }}</div>
                                    @if ($log->note)<div class="mt-0.5 text-[12px] text-ink-900">{{ $log->note }}</div>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    @endif
</div>
