@php
    use App\Http\Requests\Maintenance\LaporRequest;
    use App\Models\MaintenanceRequest;
@endphp

<x-layouts.staf title="Lapor Kerusakan">
<div class="mx-auto max-w-[760px]"
     x-data="formStaf({
        lokasi: 'kamar', room_id: '', area_label: '', unit: '', deskripsi: '', priority: 'medium', tidakBisa: false, tetapKirim: false, foto: [],
        kamar: @js($kamar), unitKamar: @js($unitKamar), unitArea: @js($unitArea), unitTerbuka: @js($unitTerbuka),
        init() {
            this.$watch('room_id', () => { this.unit = ''; this.hapus('room_id'); });
            this.$watch('area_label', () => { this.unit = ''; this.hapus('area_label'); });
            this.$watch('unit', () => { this.tetapKirim = false; this.hapus('tetap_kirim'); });
        },
        get daftarUnit() { return this.lokasi === 'kamar' ? (this.unitKamar[this.room_id] || []) : (this.unitArea[this.area_label] || []); },
        // Pilihan dropdown unit: mengikuti kamar/area terpilih, ditutup pilihan Lainnya
        get opsiUnit() {
            if (!(this.lokasi === 'kamar' ? this.room_id : this.area_label)) return [{ v: '', l: 'Pilih lokasi dulu' }];
            return [{ v: '', l: 'Pilih unit' }, ...this.daftarUnit.map((u) => ({ v: String(u.id), l: u.label })), { v: 'lainnya', l: 'Lainnya / tidak ada di daftar' }];
        },
        // Laporan yang masih terbuka untuk unit terpilih (peringatan laporan ganda)
        get ganda() { return this.unitTerbuka[this.unit] || []; },
        get kamarDipilih() { return this.kamar.find((k) => String(k.id) === String(this.room_id)); },
        get terisi() { return ['occupied_clean', 'occupied_dirty'].includes(this.kamarDipilih?.status); },
        pilihFoto(ev) {
            for (const file of ev.target.files) if (this.foto.length < {{ LaporRequest::MAKS_FOTO }}) this.foto.push({ file, url: URL.createObjectURL(file) });
            this.syncFoto(); this.hapus('foto');
        },
        buangFoto(i) { this.foto.splice(i, 1); this.syncFoto(); },
        syncFoto() { const dt = new DataTransfer(); this.foto.forEach((f) => dt.items.add(f.file)); this.$refs.foto.files = dt.files; },
     })">
    <p class="mb-5 mt-0 text-[13px] text-ink-500">Laporan langsung diterima Manager. Lengkapi lokasi dan jelaskan kerusakannya agar cepat ditangani.</p>

    <form action="{{ route('staf.maintenance.lapor.store') }}" method="POST" enctype="multipart/form-data" novalidate
          @submit.prevent="kirim($el)" class="wt-card flex flex-col gap-5 p-5 sm:p-6">
        @csrf
        <input type="hidden" name="lokasi" :value="lokasi">

        {{-- Lokasi --}}
        <x-staf.field nama="lokasi" label="Lokasi" tag="div">
            <div class="inline-flex rounded-md border border-cream-300 bg-cream-100 p-0.5" role="radiogroup" aria-label="Lokasi kerusakan">
                @foreach (['kamar' => 'Kamar', 'area' => 'Area umum'] as $v => $l)
                    <button type="button" role="radio" :aria-checked="lokasi === '{{ $v }}'" @click="lokasi = '{{ $v }}'; unit = ''; hapus('unit')"
                            :class="lokasi === '{{ $v }}' ? 'bg-brown-800 text-cream-50 shadow-wt-xs' : 'bg-transparent text-ink-500 hover:text-ink-900'"
                            class="h-9 rounded border-0 px-4 text-[13px] font-semibold transition">{{ $l }}</button>
                @endforeach
            </div>
        </x-staf.field>

        <div class="grid gap-4 sm:grid-cols-2">
            <div x-show="lokasi === 'kamar'">
                <x-staf.field nama="room_id" label="Nomor kamar" tag="div">
                    <x-staf.pilih name="room_id" model="room_id" label="Nomor kamar" galat="pesan('room_id')"
                                  :pilihan="['' => 'Pilih kamar'] + collect($kamar)->mapWithKeys(fn ($k) => [$k['id'] => 'Kamar '.$k['nomor'].' · '.$k['label']])->all()"
/>
                </x-staf.field>
            </div>
            <div x-show="lokasi === 'area'" x-cloak>
                <x-staf.field nama="area_label" label="Area" tag="div">
                    <x-staf.pilih name="area_label" model="area_label" label="Area" galat="pesan('area_label')"
                                  :pilihan="['' => 'Pilih area'] + collect($area)->mapWithKeys(fn ($a) => [$a => $a])->all()" />
                </x-staf.field>
            </div>

            <x-staf.field nama="unit" label="Unit fasilitas" tag="div">
                <x-staf.pilih name="unit" model="unit" label="Unit fasilitas" galat="pesan('unit')" sumber="opsiUnit"
                              x-on:change="hapus('unit')" />
            </x-staf.field>
        </div>

        <div x-show="ganda.length || pesan('tetap_kirim')" x-cloak class="rounded-md border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] p-4" role="alert">
            <div x-show="ganda.length" class="text-[13px] text-[#7A520C]">
                <p class="m-0 font-semibold">Unit ini sudah punya laporan yang belum selesai:</p>
                <ul class="m-0 mt-1.5 flex list-none flex-col gap-1 p-0">
                    <template x-for="l in ganda" :key="l.kode">
                        <li class="flex gap-1.5"><span aria-hidden="true">•</span>
                            <span><span class="font-semibold tabular-nums" x-text="l.kode"></span> · <span x-text="'“' + l.deskripsi + '”'"></span>
                                · <span x-text="l.pelapor + ', ' + l.waktu"></span> · <span x-text="l.status"></span></span>
                        </li>
                    </template>
                </ul>
                <p class="mb-0 mt-1.5">Kalau kerusakan yang Anda temukan sama, tidak perlu dikirim. Kalau berbeda, centang “Tetap kirim”.</p>
            </div>
            <label class="mt-3 flex cursor-pointer items-start gap-3">
                <input type="checkbox" name="tetap_kirim" value="1" x-model="tetapKirim" @change="hapus('tetap_kirim')" class="mt-0.5 h-4 w-4 accent-clay-500">
                <span class="text-[13px] font-semibold">Tetap kirim, kerusakannya berbeda</span>
            </label>
            {{-- Daftar dari server ditampilkan bila halaman belum tahu laporan itu (misalnya dilaporkan staf lain barusan) --}}
            <p x-show="pesan('tetap_kirim')" x-text="ganda.length ? 'Centang “Tetap kirim” bila kerusakannya berbeda.' : pesan('tetap_kirim')" data-galat
               class="mb-0 mt-2 whitespace-pre-line text-xs font-medium text-status-dirty"></p>
        </div>

        <x-staf.field nama="deskripsi" label="Deskripsi kerusakan">
            <textarea name="deskripsi" x-model="deskripsi" @input="hapus('deskripsi')" rows="4" maxlength="1000"
                      placeholder="Tulis gejalanya, misalnya: AC tidak dingin, AC menetes air, AC tidak menyala." class="wt-input" :class="pesan('deskripsi') && 'is-invalid'"></textarea>
            <span class="mt-1 block text-right text-[11px] tabular-nums text-ink-500" x-text="deskripsi.length + ' / 1000'"></span>
        </x-staf.field>

        <x-staf.field nama="priority" label="Prioritas" tag="div">
            <input type="hidden" name="priority" :value="priority">
            <div class="grid grid-cols-3 gap-2 sm:max-w-[420px]" role="radiogroup" aria-label="Prioritas">
                @foreach (['low' => ['Rendah', 'Bisa menunggu'], 'medium' => ['Sedang', 'Perlu segera'], 'high' => ['Tinggi', 'Mengganggu tamu']] as $v => [$l, $k])
                    <button type="button" role="radio" :aria-checked="priority === '{{ $v }}'" @click="priority = '{{ $v }}'"
                            :class="priority === '{{ $v }}' ? '{{ $v === 'high' ? 'border-status-dirty bg-[rgba(212,81,74,.08)] text-status-dirty' : 'border-clay-500 bg-[rgba(181,113,74,.08)] text-clay-600' }}' : 'border-cream-300 text-ink-900 hover:border-cream-400'"
                            class="rounded-md border bg-cream-50 px-3 py-2 text-left transition">
                        <span class="block text-[13px] font-semibold">{{ $l }}</span>
                        <span class="block text-[11px] text-ink-500">{{ $k }}</span>
                    </button>
                @endforeach
            </div>
        </x-staf.field>

        <x-staf.field nama="foto" label="Foto (opsional, maks. {{ LaporRequest::MAKS_FOTO }})" tag="div" bantuan="JPG, PNG, atau WebP, maksimal 2 MB per foto.">
            <input type="file" name="foto[]" x-ref="foto" multiple accept="image/jpeg,image/png,image/webp" class="hidden" @change="pilihFoto($event)">
            <div class="flex flex-wrap gap-2">
                <template x-for="(f, i) in foto" :key="f.url">
                    <div class="relative h-20 w-20 overflow-hidden rounded-md border border-cream-300">
                        <img :src="f.url" alt="" class="h-full w-full object-cover">
                        <button type="button" @click="buangFoto(i)" class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full border-0 bg-[rgba(40,27,18,.7)] text-cream-50" aria-label="Hapus foto"><x-staf.ikon nama="tutup" ukuran="12" /></button>
                    </div>
                </template>
                <button type="button" x-show="foto.length < {{ LaporRequest::MAKS_FOTO }}" @click="$refs.foto.click()"
                        class="flex h-20 w-20 flex-col items-center justify-center gap-1 rounded-md border border-dashed border-cream-400 bg-cream-100 text-[11px] text-ink-500 hover:border-clay-500 hover:text-clay-600">
                    <x-staf.ikon nama="foto" ukuran="20" />Tambah
                </button>
            </div>
        </x-staf.field>

        <div x-show="lokasi === 'kamar'" class="rounded-md border border-cream-300 bg-cream-100 p-4">
            <label class="flex cursor-pointer items-start gap-3">
                <input type="checkbox" name="tidak_bisa_dipakai" value="1" x-model="tidakBisa" class="mt-0.5 h-4 w-4 accent-clay-500">
                <span>
                    <span class="block text-[13px] font-semibold">Kamar tidak bisa dipakai</span>
                    <span class="block text-xs text-ink-500">Kamar yang kosong langsung ditandai Perbaikan sehingga tidak dijual sampai selesai diperbaiki.</span>
                </span>
            </label>
            <p x-show="tidakBisa && terisi" x-cloak class="mb-0 mt-3 rounded bg-[rgba(232,172,69,.16)] px-3 py-2 text-xs text-[#7A520C]">
                Kamar <span x-text="kamarDipilih?.nomor"></span> sedang terisi tamu. Status kamar tidak akan diubah; Front Office diberi tahu untuk memindahkan tamu.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-cream-300 pt-5">
            <button type="submit" :disabled="proses" :aria-busy="proses" class="h-11 rounded border-0 bg-clay-500 px-6 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70"
                    x-text="proses ? 'Mengirim…' : 'Kirim laporan'">Kirim laporan</button>
            <a href="{{ route('staf.maintenance.saya', ['tab' => 'saya']) }}" class="text-[13px] font-semibold">Lihat laporan saya</a>
        </div>
    </form>
</div>
</x-layouts.staf>
