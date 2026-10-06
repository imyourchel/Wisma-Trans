@php
    use App\Models\HallBooking;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $warna = [
        'pending' => 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]',
        'confirmed' => 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]',
        'completed' => 'bg-[rgba(155,139,120,.20)] text-[#6E604F]',
    ];
    $instansiTulis = fn ($x) => preg_match('/^Instansi: (.+)$/m', (string) $x->note, $m) ? trim($m[1]) : null;
    $harga = fn ($x) => $x->hargaDisepakati() ? $rp($x->rental_price) : 'belum disepakati';
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Saring pemesanan">
            @foreach (['menunggu' => 'Menunggu ('.($jumlah['pending'] ?? 0).')', 'terkonfirmasi' => 'Terkonfirmasi ('.($jumlah['confirmed'] ?? 0).')', 'riwayat' => 'Riwayat'] as $v => $l)
                <button type="button" wire:click="$set('tab', '{{ $v }}')" role="tab" aria-selected="{{ $tab === $v ? 'true' : 'false' }}"
                        @class(['h-8 rounded-md border-0 px-3.5 text-[13px] font-semibold transition-colors',
                            'bg-brown-800 text-cream-50' => $tab === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $tab !== $v])>{{ $l }}</button>
            @endforeach
        </div>
        <a href="{{ route('staf.aula-kalender') }}" class="wt-btn-sm hover:no-underline">Lihat kalender</a>
        @if ($bolehKelola)
            <button type="button" wire:click="bukaBaru" class="ml-auto inline-flex h-10 items-center gap-2 whitespace-nowrap rounded border-0 bg-clay-500 px-4 font-display text-[13px] font-semibold text-cream-50 hover:bg-clay-600">
                <x-staf.ikon nama="tambah" ukuran="16" />Catat pemesanan
            </button>
        @endif
    </div>
    @if (! $bolehPutuskan && $tab === 'menunggu')
        <p class="mb-4 rounded bg-cream-200 px-4 py-3 text-[13px]">Rundingkan harga dengan penyewa lalu isi harga usulan. Persetujuan dilakukan Manager.</p>
    @endif

    @if ($daftar->isEmpty())
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">Tidak ada pemesanan di sini.</div>
    @else
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($daftar as $x)
                <button type="button" wire:key="hb-{{ $x->id }}" wire:click="open({{ $x->id }})" class="wt-card flex flex-col p-4 text-left hover:border-clay-500">
                    <div class="flex w-full items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-display text-[15px] font-extrabold tabular-nums">{{ $x->booking_code }}</span>
                                <span class="wt-lencana {{ $warna[$x->status] ?? 'bg-[rgba(212,81,74,.12)] text-status-dirty' }}">{{ $x->label() }}</span>
                            </div>
                            <div class="mt-0.5 text-[13px] font-semibold">{{ $x->purpose ?: 'Acara' }} · {{ $x->participant_count }} peserta</div>
                            <div class="text-[12px] text-ink-500">{{ $x->customer?->full_name }}{{ ($x->customer?->institution?->name ?? $instansiTulis($x)) ? ' · '.($x->customer?->institution?->name ?? $instansiTulis($x)) : '' }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-[13px] font-semibold tabular-nums">{{ $harga($x) }}</div>
                            <div class="text-[11px] text-ink-500">{{ $x->hall->name }}</div>
                        </div>
                    </div>
                    <div class="mt-3 w-full rounded bg-cream-100 px-3 py-2 text-[13px]">{{ $x->jadwal() }} WITA</div>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Detail --}}
    @if ($b)
        @php $tertulis = $instansiTulis($b); @endphp
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="hb-judul" class="relative flex max-h-[92vh] w-[600px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="hb-judul" class="flex flex-wrap items-center gap-2.5"><span class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $b->booking_code }}</span><span class="wt-lencana {{ $warna[$b->status] ?? 'bg-[rgba(212,81,74,.12)] text-status-dirty' }}">{{ $b->label() }}</span></div>
                        <div class="mt-1 text-[13px] text-ink-500">{{ $b->hall->name }} · {{ $b->jadwal() }} WITA</div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <dl class="m-0 grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
                        <div><dt class="wt-field-label">Penyewa</dt><dd class="m-0 mt-1">{{ $b->customer?->full_name }}<br><span class="text-ink-500">{{ $b->customer?->phone }}</span></dd></div>
                        <div><dt class="wt-field-label">Instansi</dt><dd class="m-0 mt-1">{{ $b->customer?->institution?->name ?? $tertulis ?? 'Perorangan' }}</dd></div>
                        <div><dt class="wt-field-label">Acara</dt><dd class="m-0 mt-1">{{ $b->purpose ?: '—' }}</dd></div>
                        <div><dt class="wt-field-label">Peserta</dt><dd class="m-0 mt-1">{{ $b->participant_count }} orang (kapasitas {{ $b->hall->capacity }})</dd></div>
                        <div><dt class="wt-field-label">Harga sewa</dt><dd class="m-0 mt-1 font-semibold">{{ $harga($b) }}{{ $b->status === 'pending' && $b->hargaDisepakati() ? ' (usulan)' : '' }}</dd></div>
                        <div><dt class="wt-field-label">Nota</dt><dd class="m-0 mt-1">{{ $b->invoice ? $b->invoice->invoice_number.' · '.($b->invoice->status === 'paid' ? 'lunas' : 'jatuh tempo '.$b->invoice->due_date?->translatedFormat('j M')) : '—' }}</dd></div>
                        @if ($b->note)<div class="col-span-2"><dt class="wt-field-label">Catatan</dt><dd class="m-0 mt-1 whitespace-pre-line">{{ $b->note }}</dd></div>@endif
                        <div class="col-span-2 text-[12px] text-ink-500">Dicatat {{ $b->created_at?->translatedFormat('j M Y, H.i') }} oleh {{ $b->createdBy?->name ?? 'penyewa lewat situs' }}</div>
                    </dl>
                    @if ($bentrok)
                        <p class="m-0 mt-4 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty">Bentrok dengan {{ $bentrok->booking_code }} ({{ $bentrok->jadwal() }}). Salah satunya perlu ditolak atau diubah.</p>
                    @endif

                    <div class="mt-5 flex flex-wrap gap-2 border-t border-cream-300 pt-5">
                        @if ($b->status === 'pending' && $bolehPutuskan)
                            <button type="button" wire:click="mulai('setuju')" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Setujui</button>
                            <button type="button" wire:click="mulai('tolak')" class="wt-btn-sm !text-status-dirty">Tolak</button>
                        @endif
                        @if ($b->status === 'pending' && $bolehKelola)
                            <button type="button" wire:click="mulai('harga')" class="wt-btn-sm">Isi harga usulan</button>
                        @endif
                        @if ($b->status === 'confirmed' && $bolehKelola && $b->end_at->isPast())
                            <button type="button" wire:click="tandaiSelesai" class="wt-btn-sm">Tandai selesai</button>
                        @endif
                        @if (in_array($b->status, ['pending', 'confirmed'], true) && $bolehKelola)
                            <button type="button" wire:click="mulai('batal')" class="wt-btn-sm !text-status-dirty">Batalkan</button>
                        @endif
                        @if ($urlNota)<a href="{{ $urlNota }}" target="_blank" rel="noopener" class="wt-btn-sm hover:no-underline">Nota PDF</a>@endif
                        @if ($waUlang && $b->customer?->phone)<a href="{{ $waUlang }}" target="_blank" rel="noopener" class="wt-btn-sm hover:no-underline">Kabari penyewa (WhatsApp)</a>@endif
                    </div>
                    @error('status')<p class="m-0 mt-3 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror
                    @error('mulai')<p class="m-0 mt-3 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror

                    @if ($aksi !== '')
                        <div class="mt-4 rounded border border-cream-300 bg-cream-100 p-4">
                            @if ($aksi === 'harga')
                                <form wire:submit="simpanHarga" class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" novalidate>
                                    <x-staf.isian nama="harga" label="Harga usulan (Rp)"><input type="number" min="0" step="50000" wire:model="harga" class="wt-input {{ $galat('harga') }}"></x-staf.isian>
                                    <div class="flex gap-2"><button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Simpan</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button></div>
                                </form>
                            @elseif ($aksi === 'setuju')
                                <form wire:submit="setujui" class="flex flex-col gap-3" novalidate>
                                    <x-staf.isian nama="harga" label="Harga sewa yang disepakati (Rp)"><input type="number" min="0" step="50000" wire:model="harga" class="wt-input {{ $galat('harga') }}"></x-staf.isian>
                                    <x-staf.isian nama="tagih" label="Ditagihkan kepada" tag="div">
                                        <div class="flex flex-wrap gap-4 text-[13px]">
                                            <label class="flex items-center gap-2"><input type="radio" wire:model.live="tagih" value="perorangan" class="accent-brown-800"> Penyewa (nota aula sendiri)</label>
                                            <label class="flex items-center gap-2"><input type="radio" wire:model.live="tagih" value="instansi" class="accent-brown-800"> Instansi (tagihan instansi)</label>
                                        </div>
                                    </x-staf.isian>
                                    @if ($tagih === 'instansi')
                                        <x-staf.isian nama="instansi" label="Instansi" tag="div" :bantuan="$tertulis ? 'Ditulis penyewa: '.$tertulis.'. Instansi baru didaftarkan dulu lewat Pengajuan Instansi.' : null">
                                            <x-staf.pilih model="$wire.instansi" label="Instansi" :nilai="$instansi" :galat="$errors->has('instansi') ? 'true' : null" :pilihan="['' => 'Pilih instansi'] + $daftarInstansi->all()" />
                                        </x-staf.isian>
                                    @endif
                                    <div class="flex gap-2"><button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Setujui</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button></div>
                                    <p class="m-0 text-xs text-ink-500">Penyewa perorangan dibuatkan nota yang jatuh tempo sehari sebelum acara. Tautan nota dikirim ke WhatsApp penyewa.</p>
                                </form>
                            @else
                                <form wire:submit="{{ $aksi === 'tolak' ? 'tolak' : 'batalkan' }}" class="flex flex-col gap-3" novalidate>
                                    <x-staf.isian nama="alasan" :label="$aksi === 'tolak' ? 'Alasan penolakan' : 'Alasan pembatalan'"><input type="text" wire:model="alasan" maxlength="150" class="wt-input {{ $galat('alasan') }}"></x-staf.isian>
                                    <div class="flex gap-2"><button class="h-10 rounded border-0 bg-status-dirty px-4 font-display text-xs font-bold text-cream-50">{{ $aksi === 'tolak' ? 'Tolak' : 'Batalkan' }}</button><button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Tidak</button></div>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Catat pemesanan baru --}}
    @if ($formBaru)
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.set('formBaru', false)">
            <div wire:click="$set('formBaru', false)" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <form wire:submit="simpanBaru" role="dialog" aria-modal="true" aria-labelledby="baru-judul" novalidate class="relative flex max-h-[94vh] w-[640px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div id="baru-judul" class="flex-1 font-display text-[17px] font-extrabold uppercase tracking-[.04em]">Catat pemesanan aula</div>
                    <button type="button" wire:click="$set('formBaru', false)" class="flex h-8 w-8 items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="grid min-h-0 flex-1 gap-4 overflow-y-auto px-6 py-5 sm:grid-cols-2">
                    @if ($daftarAula->count() > 1)
                        <x-staf.isian nama="baru.aula" label="Aula" tag="div" class="sm:col-span-2"><x-staf.pilih model="$wire.baru.aula" label="Aula" :nilai="$baru['aula'] ?? ''" :pilihan="$daftarAula->all()" /></x-staf.isian>
                    @endif
                    <x-staf.isian nama="baru.nama" label="Nama penyewa"><input type="text" wire:model="baru.nama" class="wt-input {{ $galat('baru.nama') }}"></x-staf.isian>
                    <x-staf.isian nama="baru.hp" label="Nomor WhatsApp"><input type="tel" wire:model="baru.hp" placeholder="08xxxxxxxxxx" class="wt-input {{ $galat('baru.hp') }}"></x-staf.isian>
                    <x-staf.isian nama="baru.instansi" label="Instansi (opsional)" class="sm:col-span-2"><input type="text" wire:model="baru.instansi" class="wt-input"></x-staf.isian>
                    <x-staf.isian nama="baru.tanggal" label="Tanggal"><input type="date" wire:model="baru.tanggal" min="{{ today()->toDateString() }}" class="wt-input {{ $galat('baru.tanggal') }}"></x-staf.isian>
                    <div class="grid grid-cols-2 gap-3">
                        <x-staf.isian nama="baru.mulai" label="Mulai"><input type="time" wire:model="baru.mulai" class="wt-input {{ $galat('baru.mulai') }}"></x-staf.isian>
                        <x-staf.isian nama="baru.selesai" label="Selesai"><input type="time" wire:model="baru.selesai" class="wt-input {{ $galat('baru.selesai') }}"></x-staf.isian>
                    </div>
                    <x-staf.isian nama="baru.tanggal_selesai" label="Tanggal selesai (bila beda hari)"><input type="date" wire:model="baru.tanggal_selesai" class="wt-input"></x-staf.isian>
                    <x-staf.isian nama="baru.peserta" label="Peserta"><input type="number" min="1" wire:model="baru.peserta" class="wt-input {{ $galat('baru.peserta') }}"></x-staf.isian>
                    <x-staf.isian nama="baru.keperluan" label="Jenis acara"><input type="text" wire:model="baru.keperluan" maxlength="150" class="wt-input {{ $galat('baru.keperluan') }}"></x-staf.isian>
                    <x-staf.isian nama="baru.harga" label="Harga usulan (opsional)"><input type="number" min="0" step="50000" wire:model="baru.harga" class="wt-input"></x-staf.isian>
                    <x-staf.isian nama="baru.catatan" label="Catatan (opsional)" class="sm:col-span-2"><input type="text" wire:model="baru.catatan" maxlength="200" class="wt-input"></x-staf.isian>
                    @error('baru.aula')<p class="m-0 text-[13px] text-status-dirty sm:col-span-2">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-none gap-2 border-t border-cream-300 px-6 py-4">
                    <button type="submit" class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600">Simpan</button>
                    <button type="button" wire:click="$set('formBaru', false)" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
