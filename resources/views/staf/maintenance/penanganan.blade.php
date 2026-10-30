@php
    use App\Enums\RoomStatus;
    use App\Models\MaintenanceRequest;
    use App\Services\Maintenance\MaintenanceService;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $tabs = MaintenanceRequest::STATUS;
    $pilihanJalur = \App\Http\Controllers\Staf\Maintenance\PenangananController::JALUR;
    $baris = $laporan->getCollection()->values()->map(function ($l) use ($rp, $lainTerbuka) {
        $kamar = $l->kamar();
        $lain = $lainTerbuka[$l->id] ?? ['menahan' => [], 'info' => []];

        return [
            'kode' => $l->request_code,
            'status' => $l->status,
            'lokasi' => $l->lokasi(),
            'deskripsi' => $l->damage_description,
            'prioritas' => MaintenanceRequest::PRIORITAS[$l->priority] ?? $l->priority,
            'pelapor' => $l->reporter?->name ?? '—',
            'dilaporkan' => $l->reported_at->translatedFormat('d M Y, H.i'),
            'pengerja' => $l->pengerja(),
            'mulai' => $l->started_at?->translatedFormat('d M Y, H.i'),
            'selesai' => $l->completed_at?->translatedFormat('d M Y, H.i'),
            'biaya' => $l->cost !== null ? $rp($l->cost) : ($l->isJalurCepat() ? 'Belum diisi' : null),
            'catatan' => $l->resolution_note,
            'jalurCepat' => $l->isJalurCepat(),
            // Laporan penahan di kamar Perbaikan: Manager bisa membuka kamar lewat jalur cepat
            'bisaJalurCepat' => $l->terbuka() && $l->blocks_room && $kamar?->status === RoomStatus::Maintenance,
            'lengkapi' => $l->isJalurCepat() ? [
                'pengerja' => $l->vendor_name ? 'vendor' : 'staf',
                'handled_by' => $l->vendor_name ? '' : (string) $l->handled_by,
                'vendor_name' => (string) $l->vendor_name,
                'cost' => $l->cost !== null ? number_format((float) $l->cost, 0, ',', '.') : '',
                'catatan' => (string) $l->catatanTambahan(),
            ] : null,
            'foto' => $l->fotoUrls(),
            'menahan' => (bool) $l->blocks_room,
            'kamar' => $kamar ? ['nomor' => $kamar->room_number, 'status' => $kamar->status->label(), 'perbaikan' => $kamar->status === RoomStatus::Maintenance,
                // Masih ada laporan lain yang membuat kamar tidak bisa dipakai: kamar tetap Perbaikan, pilihan status kamar tidak ditampilkan
                'tetap' => $kamar->status === RoomStatus::Maintenance && $lain['menahan']
                    ? MaintenanceService::pesanTetapPerbaikan($kamar, $lain['menahan']) : null,
                'info' => $lain['info']
                    ? 'Laporan '.implode(', ', $lain['info']).' di kamar ini juga belum selesai, tetapi tidak membuat kamar tidak bisa dipakai, jadi tidak menahan kamar.' : null] : null,
            'url' => [
                'kerjakan' => route('staf.maintenance.kerjakan', $l),
                'selesai' => route('staf.maintenance.selesaikan', $l),
                'batal' => route('staf.maintenance.batalkan', $l),
                'jalur' => route('staf.maintenance.jalur-cepat', $l),
                'lengkapi' => route('staf.maintenance.lengkapi', $l),
                'riwayat' => $kamar ? route('staf.maintenance.riwayat', ['kamar' => $kamar->id]) : null,
            ],
        ];
    });
@endphp

<x-layouts.staf title="Penanganan Maintenance">
<div x-data="formStaf({ baris: @js($baris), pilih: null, aksi: '', pengerja: 'staf', isi: {},
        get b() { return this.pilih === null ? null : this.baris[this.pilih]; },
        buka(i) { this.pilih = i; this.aksi = ''; this.galat = {}; },
        tutup() { if (!this.proses) this.pilih = null; },
        mulai(a) {
            this.aksi = a; this.galat = {};
            if (a === 'lengkapi') { this.isi = { ...this.b.lengkapi }; this.pengerja = this.isi.pengerja; }
            this.$nextTick(() => this.$refs['f-' + a]?.querySelector('input:not([type=hidden]), textarea, select')?.focus());
        },
     })" @keydown.escape.window="pilih !== null && tutup()">

    {{-- Tab status --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <nav class="inline-flex flex-wrap rounded-lg border border-cream-300 bg-cream-50 p-0.5" aria-label="Status laporan">
            @foreach ($tabs as $v => $l)
                <a href="{{ route('staf.maintenance.index', array_filter(['tab' => $v] + $saring)) }}" @if ($tab === $v) aria-current="page" @endif
                   @class(['inline-flex h-8 items-center gap-2 rounded-md px-3.5 text-[13px] font-semibold hover:no-underline',
                       'bg-brown-800 text-cream-50 hover:text-cream-50' => $tab === $v, 'text-ink-500 hover:text-ink-900' => $tab !== $v])>
                    {{ $l }}
                    <span @class(['rounded-full px-1.5 text-[11px] tabular-nums', 'bg-[rgba(242,235,220,.2)]' => $tab === $v, 'bg-cream-200' => $tab !== $v])>{{ $jumlah[$v] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>
    </div>

    {{-- Saringan --}}
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-3">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label class="relative block min-w-0 flex-[1_1_200px] sm:max-w-[240px]">
            <span class="sr-only">Cari kode</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" name="cari" value="{{ $saring['cari'] }}" placeholder="Cari kode, mis. MNT-0003" class="wt-input pl-9">
        </label>
        <x-staf.pilih name="prioritas" kirim label="Saring prioritas" class="w-[170px]" :nilai="$saring['prioritas']"
                      :pilihan="['' => 'Semua prioritas'] + MaintenanceRequest::PRIORITAS" />
        <x-staf.pilih name="lokasi" kirim label="Saring lokasi" class="w-[170px]" :nilai="$saring['lokasi']"
                      :pilihan="['' => 'Semua lokasi', 'kamar' => 'Kamar', 'area' => 'Area umum']" />
        @if ($tab === 'completed')
            <x-staf.pilih name="jalur" kirim label="Saring jalur cepat" class="w-[240px]" :nilai="$saring['jalur']"
                          :pilihan="['' => 'Semua cara selesai'] + $pilihanJalur" />
        @endif
        @if (array_filter($saring))
            <a href="{{ route('staf.maintenance.index', ['tab' => $tab]) }}" class="text-[13px] font-semibold">Hapus saringan</a>
        @endif
    </form>

    <div class="wt-card overflow-x-auto">
        <table class="wt-tabel-kartu w-full min-w-[920px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Kerusakan</th>
                    <th class="wt-th">Prioritas</th>
                    <th class="wt-th">{{ $tab === 'reported' ? 'Dilaporkan' : ($tab === 'in_progress' ? 'Dikerjakan' : 'Pengerja') }}</th>
                    <th class="wt-th">{{ $tab === 'completed' ? 'Biaya' : 'Pelapor' }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($laporan as $i => $l)
                    <tr @click="buka({{ $loop->index }})" class="cursor-pointer hover:bg-row-hover">
                        <td class="wt-td wt-kartu-judul whitespace-nowrap font-semibold tabular-nums">
                            {{ $l->request_code }}
                            @if ($l->isJalurCepat())<div class="mt-1"><x-staf.lencana-mnt jalur-cepat /></div>@endif
                        </td>
                        <td class="wt-td max-w-[420px]" data-label="Kerusakan">
                            <div class="font-semibold">{{ $l->lokasi() }}</div>
                            <div class="mt-0.5 text-[13px] text-ink-500">{{ Str::limit($l->damage_description, 80) }}</div>
                            @if (($k = $l->kamar()) && $k->status === RoomStatus::Maintenance)
                                <div class="mt-0.5 text-[11px] text-status-dirty">Kamar ditutup (Perbaikan)</div>
                            @endif
                        </td>
                        <td class="wt-td" data-label="Prioritas"><x-staf.lencana-mnt :prioritas="$l->priority" /></td>
                        <td class="wt-td whitespace-nowrap text-ink-500" data-label="{{ $tab === 'reported' ? 'Dilaporkan' : ($tab === 'in_progress' ? 'Dikerjakan' : 'Pengerja') }}">
                            @if ($tab === 'reported')
                                {{ $l->reported_at->translatedFormat('d M Y, H.i') }}
                                <div class="text-[11px]">{{ $l->reported_at->diffForHumans() }}</div>
                            @elseif ($tab === 'in_progress')
                                <span class="text-ink-900">{{ $l->pengerja() }}</span>
                                <div class="text-[11px]">sejak {{ $l->started_at?->translatedFormat('d M, H.i') }}</div>
                            @else
                                {{ $l->pengerja() ?? '—' }}
                            @endif
                        </td>
                        <td class="wt-td whitespace-nowrap {{ $tab === 'completed' ? 'tabular-nums' : 'text-ink-500' }}" data-label="{{ $tab === 'completed' ? 'Biaya' : 'Pelapor' }}">
                            @if ($tab !== 'completed')
                                {{ $l->reporter?->name ?? '—' }}
                            @elseif ($l->cost !== null)
                                {{ $rp($l->cost) }}
                            @else
                                <span class="text-ink-500">{{ $l->isJalurCepat() ? 'Belum diisi' : '—' }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada laporan {{ Str::lower($tabs[$tab]) }}{{ array_filter($saring) ? ' yang cocok dengan saringan' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $laporan->links('partials.halaman') }}

    {{-- Panel detail dan aksi --}}
    <div x-show="pilih !== null" x-cloak @click="tutup()" x-transition.opacity class="fixed inset-0 z-[340] bg-[rgba(62,42,28,.30)]"></div>
    <aside x-show="pilih !== null" x-cloak role="dialog" aria-modal="true" aria-labelledby="mnt-judul"
           x-transition:enter="transition duration-200 ease-wt" x-transition:enter-start="translate-x-8 opacity-0" x-transition:enter-end="translate-x-0 opacity-100"
           class="fixed inset-y-0 right-0 z-[345] flex w-[520px] max-w-full flex-col border-l pb-[env(safe-area-inset-bottom)] sm:max-w-[94vw] border-cream-300 bg-cream-50 shadow-wt-lg">
        <template x-if="b">
            <div class="flex min-h-0 flex-1 flex-col">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="mnt-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]" x-text="b.kode"></div>
                        <div class="mt-0.5 text-[13px] text-ink-500" x-text="b.lokasi + ' · Prioritas ' + b.prioritas"></div>
                        <div x-show="b.jalurCepat" class="mt-1.5"><x-staf.lencana-mnt jalur-cepat /></div>
                    </div>
                    <button type="button" @click="tutup()" class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <p class="m-0 whitespace-pre-line text-sm" x-text="b.deskripsi"></p>
                    <div x-show="b.foto.length" class="mt-3 flex flex-wrap gap-2">
                        <template x-for="u in b.foto" :key="u"><a :href="u" target="_blank" rel="noopener"><img :src="u" alt="Foto kerusakan" class="h-20 w-20 rounded-md border border-cream-300 object-cover"></a></template>
                    </div>

                    <dl class="m-0 mt-5 grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
                        <div><dt class="wt-field-label">Pelapor</dt><dd class="m-0 mt-1" x-text="b.pelapor + ', ' + b.dilaporkan"></dd></div>
                        <div x-show="b.kamar"><dt class="wt-field-label">Status kamar</dt><dd class="m-0 mt-1" :class="b.kamar?.perbaikan && 'font-semibold text-status-dirty'" x-text="b.kamar ? 'Kamar ' + b.kamar.nomor + ' · ' + b.kamar.status : ''"></dd>
                            <dd x-show="b.menahan" class="m-0 mt-0.5 text-[11px] text-ink-500">Dilaporkan: kamar tidak bisa dipakai</dd></div>
                        <div x-show="b.pengerja"><dt class="wt-field-label">Dikerjakan oleh</dt><dd class="m-0 mt-1" x-text="b.pengerja + (b.mulai ? ', sejak ' + b.mulai : '')"></dd></div>
                        <div x-show="b.selesai"><dt class="wt-field-label">Selesai</dt><dd class="m-0 mt-1" x-text="b.selesai"></dd></div>
                        <div x-show="b.biaya"><dt class="wt-field-label">Biaya</dt><dd class="m-0 mt-1 tabular-nums" :class="b.biaya === 'Belum diisi' && 'text-ink-500'" x-text="b.biaya"></dd></div>
                        <div x-show="b.catatan" class="col-span-2"><dt class="wt-field-label" x-text="b.status === 'cancelled' ? 'Alasan pembatalan' : 'Catatan perbaikan'"></dt><dd class="m-0 mt-1 whitespace-pre-line" x-text="b.catatan"></dd></div>
                    </dl>
                    <a x-show="b.url.riwayat" :href="b.url.riwayat" class="mt-3 inline-block text-[13px] font-semibold">Riwayat perawatan kamar ini</a>

                    {{-- Tombol aksi sesuai status --}}
                    <div x-show="['reported', 'in_progress'].includes(b.status) && !aksi" class="mt-5 flex flex-wrap gap-2 border-t border-cream-300 pt-5">
                        <button type="button" x-show="b.status === 'reported'" @click="mulai('kerjakan')" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Kerjakan</button>
                        <button type="button" x-show="b.status === 'in_progress'" @click="mulai('selesai')" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Selesai</button>
                        <button type="button" x-show="b.bisaJalurCepat" @click="mulai('jalur')" class="wt-btn-sm h-9">Selesaikan jalur cepat</button>
                        <button type="button" @click="mulai('batal')" class="wt-btn-sm h-9 !text-status-dirty">Batalkan</button>
                    </div>
                    {{-- Laporan jalur cepat: pengerja, biaya, dan catatan dilengkapi Manager; laporan selesai biasa tidak bisa diedit --}}
                    <div x-show="b.jalurCepat && !aksi" class="mt-5 flex flex-wrap items-center gap-3 border-t border-cream-300 pt-5">
                        <button type="button" @click="mulai('lengkapi')" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Lengkapi</button>
                        <span class="text-xs text-ink-500">Ditutup lewat jalur cepat. Lengkapi pengerja dan biayanya.</span>
                    </div>
                    <p x-show="['completed', 'cancelled'].includes(b.status) && !b.jalurCepat" class="mb-0 mt-5 border-t border-cream-300 pt-4 text-xs text-ink-500">Laporan yang sudah selesai atau dibatalkan tidak bisa diubah lagi.</p>

                    {{-- Jalur cepat: konfirmasi kata sandi Manager yang sedang masuk --}}
                    <form x-show="aksi === 'jalur'" x-ref="f-jalur" :action="b.url.jalur" @submit.prevent="kirim($el)" novalidate class="mt-5 flex flex-col gap-4 rounded-md border border-cream-300 bg-cream-100 p-4">
                        @csrf
                        <p class="m-0 text-[13px]">Semua laporan yang membuat <span x-text="'Kamar ' + b.kamar?.nomor"></span> tidak bisa dipakai ditutup sekarang, lalu kamar menjadi Siap. Biaya dan pengerja dilengkapi nanti lewat Lengkapi.</p>
                        <x-staf.field nama="sandi_manager" label="Kata sandi Anda">
                            <input type="password" name="sandi_manager" autocomplete="current-password" class="wt-input" :class="pesan('sandi_manager') && 'is-invalid'" @input="hapus('sandi_manager')">
                        </x-staf.field>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="proses" :aria-busy="proses" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">Buka kamar</button>
                            <button type="button" @click="aksi = ''" class="wt-btn-sm h-10">Batal</button>
                        </div>
                    </form>

                    {{-- Lengkapi laporan jalur cepat --}}
                    <form x-show="aksi === 'lengkapi'" x-ref="f-lengkapi" :action="b.url.lengkapi" @submit.prevent="kirim($el)" novalidate class="mt-5 flex flex-col gap-4 rounded-md border border-cream-300 bg-cream-100 p-4">
                        @csrf
                        <x-staf.field nama="pengerja" label="Dikerjakan oleh" tag="div">
                            <input type="hidden" name="pengerja" :value="pengerja">
                            <div class="inline-flex rounded-md border border-cream-300 bg-cream-50 p-0.5">
                                @foreach (['staf' => 'Staf internal', 'vendor' => 'Vendor luar'] as $v => $l)
                                    <button type="button" @click="pengerja = '{{ $v }}'; hapus('pengerja')" :class="pengerja === '{{ $v }}' ? 'bg-brown-800 text-cream-50' : 'bg-transparent text-ink-500'"
                                            class="h-8 rounded border-0 px-3.5 text-[13px] font-semibold">{{ $l }}</button>
                                @endforeach
                            </div>
                        </x-staf.field>
                        <x-staf.field nama="handled_by" label="Staf" tag="div" x-show="pengerja === 'staf'">
                            <select name="handled_by" x-model="isi.handled_by" class="wt-input" :class="pesan('handled_by') && 'is-invalid'" @change="hapus('handled_by')">
                                <option value="">Pilih staf</option>
                                @foreach ($staf as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </select>
                        </x-staf.field>
                        <x-staf.field nama="vendor_name" label="Nama vendor" x-show="pengerja === 'vendor'">
                            <input type="text" name="vendor_name" x-model="isi.vendor_name" maxlength="100" placeholder="Contoh: CV Sejuk Teknik" class="wt-input" :class="pesan('vendor_name') && 'is-invalid'" @input="hapus('vendor_name')">
                        </x-staf.field>
                        <x-staf.field nama="cost" label="Biaya" bantuan="Tulis 0 bila tanpa biaya.">
                            <span class="relative block">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">Rp</span>
                                <input type="text" name="cost" x-model="isi.cost" inputmode="numeric" placeholder="250.000" class="wt-input pl-9 tabular-nums" :class="pesan('cost') && 'is-invalid'" @input="hapus('cost')">
                            </span>
                        </x-staf.field>
                        <x-staf.field nama="catatan" label="Catatan tambahan (opsional)" bantuan="Disimpan setelah penanda Jalur cepat.">
                            <textarea name="catatan" x-model="isi.catatan" rows="3" maxlength="1000" placeholder="Contoh: freon diisi ulang oleh CV Sejuk Teknik." class="wt-input" :class="pesan('catatan') && 'is-invalid'" @input="hapus('catatan')"></textarea>
                        </x-staf.field>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="proses" :aria-busy="proses" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">Simpan</button>
                            <button type="button" @click="aksi = ''" class="wt-btn-sm h-10">Batal</button>
                        </div>
                    </form>

                    {{-- Kerjakan --}}
                    <form x-show="aksi === 'kerjakan'" x-ref="f-kerjakan" :action="b.url.kerjakan" @submit.prevent="kirim($el)" novalidate class="mt-5 flex flex-col gap-4 rounded-md border border-cream-300 bg-cream-100 p-4">
                        @csrf
                        <x-staf.field nama="pengerja" label="Dikerjakan oleh" tag="div">
                            <input type="hidden" name="pengerja" :value="pengerja">
                            <div class="inline-flex rounded-md border border-cream-300 bg-cream-50 p-0.5">
                                @foreach (['staf' => 'Staf internal', 'vendor' => 'Vendor luar'] as $v => $l)
                                    <button type="button" @click="pengerja = '{{ $v }}'; hapus('pengerja')" :class="pengerja === '{{ $v }}' ? 'bg-brown-800 text-cream-50' : 'bg-transparent text-ink-500'"
                                            class="h-8 rounded border-0 px-3.5 text-[13px] font-semibold">{{ $l }}</button>
                                @endforeach
                            </div>
                        </x-staf.field>
                        <x-staf.field nama="handled_by" label="Staf" tag="div" x-show="pengerja === 'staf'">
                            <select name="handled_by" class="wt-input" :class="pesan('handled_by') && 'is-invalid'" @change="hapus('handled_by')">
                                <option value="">Pilih staf</option>
                                @foreach ($staf as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </select>
                        </x-staf.field>
                        <x-staf.field nama="vendor_name" label="Nama vendor" x-show="pengerja === 'vendor'">
                            <input type="text" name="vendor_name" maxlength="100" placeholder="Contoh: CV Sejuk Teknik" class="wt-input" :class="pesan('vendor_name') && 'is-invalid'" @input="hapus('vendor_name')">
                        </x-staf.field>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="proses" :aria-busy="proses" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">Mulai kerjakan</button>
                            <button type="button" @click="aksi = ''" class="wt-btn-sm h-10">Batal</button>
                        </div>
                    </form>

                    {{-- Selesai --}}
                    <form x-show="aksi === 'selesai'" x-ref="f-selesai" :action="b.url.selesai" @submit.prevent="kirim($el)" novalidate class="mt-5 flex flex-col gap-4 rounded-md border border-cream-300 bg-cream-100 p-4">
                        @csrf
                        <x-staf.field nama="resolution_note" label="Catatan perbaikan">
                            <textarea name="resolution_note" rows="3" maxlength="1000" placeholder="Contoh: freon diisi ulang dan saluran air dibersihkan." class="wt-input" :class="pesan('resolution_note') && 'is-invalid'" @input="hapus('resolution_note')"></textarea>
                        </x-staf.field>
                        <x-staf.field nama="cost" label="Biaya (opsional)" bantuan="Kosongkan bila tanpa biaya.">
                            <span class="relative block">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">Rp</span>
                                <input type="text" name="cost" inputmode="numeric" placeholder="250.000" class="wt-input pl-9 tabular-nums" :class="pesan('cost') && 'is-invalid'" @input="hapus('cost')">
                            </span>
                        </x-staf.field>
                        <x-staf.field nama="status_kamar" label="Status kamar setelah perbaikan" tag="div" x-show="b.kamar?.perbaikan && !b.kamar?.tetap">
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach (['vacant_dirty' => ['Perlu dibersihkan', 'Masuk antrean pembersihan'], 'available' => ['Siap dipakai', 'Langsung bisa dijual']] as $v => [$l, $k])
                                    <label class="flex cursor-pointer items-start gap-2.5 rounded-md border border-cream-300 bg-cream-50 p-3 has-[:checked]:border-clay-500 has-[:checked]:bg-[rgba(181,113,74,.06)]">
                                        <input type="radio" name="status_kamar" value="{{ $v }}" class="mt-0.5 accent-clay-500" @change="hapus('status_kamar')">
                                        <span><span class="block text-[13px] font-semibold">{{ $l }}</span><span class="block text-[11px] text-ink-500">{{ $k }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </x-staf.field>
                        <p x-show="b.kamar?.tetap" x-text="b.kamar?.tetap" class="m-0 rounded-md border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] px-3 py-2 text-xs text-[#7A520C]"></p>
                        <p x-show="b.kamar?.info" x-text="b.kamar?.info" class="m-0 text-xs text-ink-500"></p>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="proses" :aria-busy="proses" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">Tandai selesai</button>
                            <button type="button" @click="aksi = ''" class="wt-btn-sm h-10">Batal</button>
                        </div>
                    </form>

                    {{-- Batalkan --}}
                    <form x-show="aksi === 'batal'" x-ref="f-batal" :action="b.url.batal" @submit.prevent="kirim($el)" novalidate class="mt-5 flex flex-col gap-4 rounded-md border border-cream-300 bg-cream-100 p-4">
                        @csrf
                        <x-staf.field nama="alasan" label="Alasan pembatalan">
                            <input type="text" name="alasan" maxlength="500" placeholder="Contoh: laporan ganda dengan MNT-0003" class="wt-input" :class="pesan('alasan') && 'is-invalid'" @input="hapus('alasan')">
                        </x-staf.field>
                        <p x-show="b.kamar?.perbaikan && !b.kamar?.tetap" class="m-0 text-xs text-ink-500">Bila kamar ditutup karena laporan kerusakan, kamar dibuka kembali (Siap).</p>
                        <p x-show="b.kamar?.tetap" x-text="b.kamar?.tetap" class="m-0 rounded-md border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] px-3 py-2 text-xs text-[#7A520C]"></p>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="proses" :aria-busy="proses" class="h-10 rounded border-0 bg-status-dirty px-4 font-display text-xs font-bold text-cream-50 disabled:opacity-70">Batalkan laporan</button>
                            <button type="button" @click="aksi = ''" class="wt-btn-sm h-10">Tidak</button>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    </aside>
</div>
</x-layouts.staf>
