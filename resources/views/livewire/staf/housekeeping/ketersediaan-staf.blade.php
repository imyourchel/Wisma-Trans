<div>
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="block">
            <span class="wt-field-label mb-1.5 block">Tanggal</span>
            <x-staf.tanggal wire:model.live="tanggal" :nilai="$tanggal" :max="$maks" label="Tanggal" class="w-[210px]" />
        </div>
        {{-- Tombol cepat; tanggal bawaan hari ini (WITA) --}}
        <div class="flex gap-2" data-tanggal-cepat>
            <button type="button" wire:click="$set('tanggal', '{{ today()->toDateString() }}')"
                    @class(['wt-btn-sm h-10', 'bg-cream-200 font-semibold' => $hariIni]) @if ($hariIni) aria-pressed="true" @endif>Hari ini</button>
            <button type="button" wire:click="$set('tanggal', '{{ $besok }}')"
                    @class(['wt-btn-sm h-10', 'bg-cream-200 font-semibold' => $tanggal === $besok]) @if ($tanggal === $besok) aria-pressed="true" @endif>Besok</button>
        </div>
        @if ($lewat)
            <span class="wt-lencana wt-lencana-nonaktif mb-2.5" data-tanggal-lewat>Tanggal sudah lewat</span>
        @endif
    </div>
    @if ($sudahDibagi && ! $lewat)
        <div class="mb-3 rounded-md border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] px-4 py-3 text-[13px] text-[#7A520C]" role="status" data-sudah-dibagi>
            Tugas tanggal ini sudah dibagi. Perubahan baru berlaku bila pembagian tugas diulang.
        </div>
    @endif
    {{-- Angka batas dari settings (halaman Pengaturan) dan poin dari config/housekeeping.php, tidak ditulis di sini --}}
    <div class="mb-4 rounded-md border border-cream-300 bg-cream-100 px-4 py-3 text-[13px] leading-relaxed text-ink-900" data-bantuan>
        Kosongkan bila tidak ada perubahan. Batas otomatis: HK murni {{ $otomatis['murni'] }} poin, multi-role {{ $otomatis['multirole'] }} poin.
        <span class="mt-1 block text-[12px] text-ink-500">Poin per kamar: kosong kotor {{ $poin['vacant_dirty'] ?? '' }} poin, terisi kotor {{ $poin['occupied_dirty'] ?? '' }} poin.</span>
    </div>

    @foreach ($peringatan as $p)
        <div wire:key="peringatan-{{ $loop->index }}" class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] px-4 py-3 text-[13px] text-[#7A520C]" role="status">
            <span class="flex-1">{{ $p }}</span>
            @if ($hariIni)
                <a href="{{ route('staf.housekeeping.pembagian') }}" class="wt-btn-sm hover:no-underline">Ke Pembagian Tugas</a>
            @endif
        </div>
    @endforeach

    <form wire:submit="simpan" novalidate>
        <div class="wt-card overflow-x-auto">
            <table class="w-full min-w-[920px] border-collapse">
                <thead>
                    <tr>
                        <th class="wt-th">Staf</th>
                        <th class="wt-th">Hadir</th>
                        <th class="wt-th">Tugas rangkap</th>
                        <th class="wt-th">Batas beban <span class="font-normal normal-case tracking-normal">(opsional)</span></th>
                        <th class="wt-th">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($staf as $s)
                        @php
                            $otomatisStaf = $batasStaf[$s->id];
                            $angkaOtomatis = \App\Services\SmartCleaning\PembagianTugasService::angka($otomatisStaf['batas']);
                            $hadirKini = (bool) ($baris[$s->id]['hadir'] ?? true);
                            $isianKini = trim((string) ($baris[$s->id]['batas'] ?? ''));
                            $manualKini = $isianKini !== '' && abs((float) str_replace(',', '.', $isianKini) - $otomatisStaf['batas']) > 1e-9;
                        @endphp
                        {{-- Semua sel rata atas dengan padding sama; isi tiap sel setinggi isian (h-10) supaya sejajar --}}
                        <tr wire:key="staf-{{ $s->id }}-{{ $tanggal }}" data-baris-staf="{{ $s->id }}"
                            x-data="{
                                otomatis: {{ $otomatisStaf['batas'] }},
                                get b() { return this.$wire.baris[{{ $s->id }}] },
                                get hadir() { return !! this.b.hadir },
                                get manual() {
                                    const v = String(this.b.batas ?? '').trim().replace(',', '.');
                                    return v !== '' && Number(v) !== this.otomatis;
                                },
                            }"
                            @class(['align-top', 'bg-[rgba(155,139,120,.10)]' => ! $hadirKini]) :class="! hadir && 'bg-[rgba(155,139,120,.10)]'">
                            <td class="wt-td align-top">
                                <span @class(['flex h-10 items-center font-semibold', 'text-ink-500' => ! $hadirKini]) :class="! hadir && 'text-ink-500'">{{ $s->name }}</span>
                            </td>
                            <td class="wt-td align-top">
                                <label class="flex h-10 cursor-pointer items-center gap-2 text-[13px]">
                                    <input type="checkbox" wire:model="baris.{{ $s->id }}.hadir" @disabled($lewat) class="h-4 w-4 accent-brown-800 disabled:cursor-not-allowed">
                                    Hadir
                                </label>
                            </td>
                            <td class="wt-td align-top">
                                {{-- Dari peran staf (user_roles), bukan isian --}}
                                <span class="flex h-10 items-center text-ink-900" data-rangkap="{{ $s->id }}">{{ $rangkap[$s->id] }}</span>
                            </td>
                            <td class="wt-td align-top">
                                <div class="flex h-10 items-center gap-2">
                                    <input type="text" wire:model="baris.{{ $s->id }}.batas" inputmode="decimal" placeholder="{{ $angkaOtomatis }} (otomatis)" aria-label="Batas beban {{ $s->name }}"
                                           x-show="hadir" @style(['display: none' => ! $hadirKini]) @disabled($lewat)
                                           @class(['wt-input w-[112px] tabular-nums', 'is-invalid' => $errors->has('baris.'.$s->id.'.batas')])>
                                    {{-- Tidak hadir: batas beban tidak berlaku --}}
                                    <input type="text" value="—" disabled aria-label="Batas beban {{ $s->name }} (tidak hadir)"
                                           x-show="! hadir" @style(['display: none' => $hadirKini])
                                           class="wt-input w-[112px] cursor-not-allowed text-center">
                                    <span class="text-[13px] text-ink-500">poin</span>
                                    {{-- Badge sumber batas; Alpine menggantinya saat isian berubah sebelum disimpan --}}
                                    @php $awal = ! $hadirKini ? 'tidak-hadir' : ($manualKini ? 'manual' : 'otomatis'); @endphp
                                    <span class="flex items-center gap-2 whitespace-nowrap" data-sumber-batas="{{ $s->id }}" data-awal="{{ $awal }}">
                                        <span class="wt-lencana wt-lencana-nonaktif" x-show="! hadir" @style(['display: none' => $awal !== 'tidak-hadir'])>Tidak hadir</span>
                                        <span class="wt-lencana bg-[rgba(181,113,74,.16)] text-clay-600" x-show="hadir && manual" @style(['display: none' => $awal !== 'manual'])>Manual</span>
                                        <span class="wt-lencana wt-lencana-netral" x-show="hadir && ! manual" @style(['display: none' => $awal !== 'otomatis'])>Otomatis · {{ $otomatisStaf['singkat'] }}</span>
                                        @if (! $lewat)
                                        <button type="button" x-show="hadir && manual" @style(['display: none' => $awal !== 'manual'])
                                                x-on:click="$wire.$set('baris.{{ $s->id }}.batas', '', false)" class="cursor-pointer border-0 bg-transparent p-0 text-[12px] text-clay-600 underline hover:text-clay-500">kembalikan ke otomatis</button>
                                        @endif
                                    </span>
                                </div>
                                @error('baris.'.$s->id.'.batas')
                                    <p class="m-0 mt-1.5 text-[11px] font-medium leading-snug text-status-dirty" data-galat>{{ $message }}</p>
                                @enderror
                            </td>
                            <td class="wt-td align-top">
                                <input type="text" wire:model="baris.{{ $s->id }}.catatan" maxlength="255" placeholder="Contoh: izin sakit" aria-label="Catatan {{ $s->name }}" @disabled($lewat)
                                       @class(['wt-input min-w-[200px]', 'is-invalid' => $errors->has('baris.'.$s->id.'.catatan')])>
                                @error('baris.'.$s->id.'.catatan')
                                    <p class="m-0 mt-1.5 text-[11px] font-medium leading-snug text-status-dirty" data-galat>{{ $message }}</p>
                                @enderror
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="wt-td py-10 text-center text-sm text-ink-500">Belum ada akun Housekeeping yang aktif.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{-- Tanggal lewat hanya dilihat: tombol Simpan disembunyikan --}}
        @unless ($lewat)
        <button type="submit" wire:loading.attr="disabled" wire:target="simpan" class="mt-4 h-10 rounded border-0 bg-clay-500 px-5 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">Simpan ketersediaan</button>
        @endunless
    </form>
</div>
