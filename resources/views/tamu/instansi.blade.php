@php
    $isian = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $pengguna = auth()->user();
    $pelanggan = $pengguna?->customer;
    $wa = 'https://wa.me/'.($wisma['whatsapp'] ?? '');
    $tanyaWa = $wa.'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang reservasi instansi.');
    $galat = fn ($f) => $errors->has($f) ? '<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">'.e($errors->first($f)).'</span>' : '';
    $jumlahAwal = max(1, min(32, (int) old('jumlah', request('kamar')) ?: 1));
    $maksMalam = \App\Services\Reservasi\ReservasiService::MAKS_MALAM_INSTANSI;
    $ikonWa = fn (int $u) => '<span class="block flex-none bg-current" style="width:'.$u.'px; height:'.$u.'px; -webkit-mask: url('.asset('ds/icons/whatsapp.svg').') center/contain no-repeat; mask: url('.asset('ds/icons/whatsapp.svg').') center/contain no-repeat" aria-hidden="true"></span>';
@endphp
<x-layouts.guest title="Pengajuan reservasi instansi">
<div class="animate-wt-masuk">
    <div class="wt-container max-w-[1120px] pb-14 pt-8 sm:pb-20 sm:pt-10">
        <form method="POST" action="{{ route('instansi.store') }}" id="form-instansi" novalidate
              x-data="pengajuanInstansi(@js([
                  'hariIni' => today()->toDateString(),
                  'masuk' => old('masuk', request('masuk')),
                  'keluar' => old('keluar', request('keluar')),
                  'jumlah' => $jumlahAwal,
                  // Peserta bawaan = kamar × 2 dan ikut jumlah kamar selama belum diubah tamu
                  'peserta' => (int) old('peserta', min(200, $jumlahAwal * 2)),
                  'pesertaDiubah' => old('peserta') !== null,
                  'maksMalam' => $maksMalam,
                  'instansi' => old('instansi', ''),
                  'catatan' => old('catatan', ''),
              ]))" @resize.window.debounce.150ms="lebar = window.innerWidth"
              class="grid scroll-mt-24 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            @csrf
            <div class="wt-card min-w-0">
                {{-- ① Data instansi dan penanggung jawab --}}
                <fieldset class="m-0 min-w-0 border-0 p-5 sm:p-6">
                    <legend class="float-left mb-4 w-full p-0">
                        <span class="flex items-center gap-3">
                            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-brown-800 font-display text-sm font-bold text-cream-50" aria-hidden="true">1</span>
                            <span class="font-display text-[13px] font-bold uppercase tracking-[.08em] text-ink-900">Data instansi dan penanggung jawab</span>
                        </span>
                    </legend>
                    <div class="clear-left grid gap-4 sm:grid-cols-2">
                        <x-tawaran-masuk class="sm:col-span-2" manfaat="data narahubung terisi otomatis" />
                        <label class="block sm:col-span-2">
                            <span class="wt-field-label mb-1.5 block">Nama instansi atau perusahaan</span>
                            <input type="text" name="instansi" x-model="instansi" value="{{ old('instansi') }}" autocomplete="organization" placeholder="Nama resmi instansi atau perusahaan" class="wt-input h-11 {{ $isian('instansi') }}">
                            {!! $galat('instansi') !!}
                        </label>
                        @foreach ([
                            ['name', 'Nama penanggung jawab', 'text', 'name', 'Nama lengkap', '', $pelanggan?->full_name ?? $pengguna?->name],
                            ['phone', 'Nomor WhatsApp', 'tel', 'tel', '08xxxxxxxxxx', '', $pelanggan?->phone ?? $pengguna?->phone],
                            ['email', 'Email (opsional)', 'email', 'email', 'Untuk konfirmasi dan tagihan', 'sm:col-span-2', $pelanggan?->email ?? $pengguna?->email],
                        ] as [$f, $label, $type, $auto, $ph, $kolom, $bawaan])
                            <label class="block {{ $kolom }}">
                                <span class="wt-field-label mb-1.5 block">{{ $label }}</span>
                                <input type="{{ $type }}" name="{{ $f }}" value="{{ old($f, $bawaan) }}" autocomplete="{{ $auto }}" placeholder="{{ $ph }}" class="wt-input h-11 {{ $isian($f) }}">
                                {!! $galat($f) !!}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- ② Rencana menginap: kalender rentang yang sama dengan beranda (batas malam instansi), tetap mengirim masuk dan keluar --}}
                <fieldset class="m-0 min-w-0 border-0 border-t border-cream-300 p-5 sm:p-6">
                    <legend class="float-left mb-4 w-full p-0">
                        <span class="flex items-center gap-3">
                            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-brown-800 font-display text-sm font-bold text-cream-50" aria-hidden="true">2</span>
                            <span class="font-display text-[13px] font-bold uppercase tracking-[.08em] text-ink-900">Rencana menginap</span>
                        </span>
                    </legend>
                    <div class="clear-left grid gap-4">
                        <div>
                            <span class="wt-field-label mb-1.5 block">Tanggal menginap</span>
                            <x-tamu.rentang-tanggal :pilihan-cepat="[[1, '1 malam'], [2, '2 malam'], [3, '3 malam'], [7, '1 minggu'], [30, '1 bulan'], [90, '3 bulan']]" :class="Arr::toCssClasses(['rounded-md border bg-cream-50', 'border-cream-300' => ! $errors->hasAny(['masuk', 'keluar']), 'border-status-dirty' => $errors->hasAny(['masuk', 'keluar'])])" />
                            {!! $galat('masuk') ?: $galat('keluar') !!}
                        </div>
                        {{-- Jumlah kamar dan tamu bersampingan (juga di ponsel; tombol stepper sedikit lebih ramping) --}}
                        <div class="grid grid-cols-2 gap-3 sm:gap-6">
                        <div class="min-w-0">
                            <span class="wt-field-label mb-1.5 block" id="label-jumlah">Jumlah kamar</span>
                            <div>
                                <div class="inline-flex overflow-hidden rounded-md border border-cream-400" role="group" aria-labelledby="label-jumlah" data-stepper-jumlah>
                                    <button type="button" @click="ubahJumlah(-1)" :disabled="jumlah <= 1" aria-label="Kurangi kamar"
                                            class="flex h-11 w-10 items-center justify-center border-0 bg-cream-50 text-lg sm:w-11 text-brown-800 hover:bg-cream-200 disabled:cursor-not-allowed disabled:opacity-35">−</button>
                                    <input type="number" name="jumlah" x-model.number="jumlah" @blur="ubahJumlah(0)" value="{{ $jumlahAwal }}" min="1" max="32" inputmode="numeric" aria-labelledby="label-jumlah"
                                           class="h-11 w-12 border-0 border-x sm:w-14 border-cream-400 bg-brown-800 text-center font-display text-sm font-bold tabular-nums text-cream-50 [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
                                    <button type="button" @click="ubahJumlah(1)" :disabled="jumlah >= 32" aria-label="Tambah kamar"
                                            class="flex h-11 w-10 items-center justify-center border-0 bg-clay-500 text-lg sm:w-11 text-cream-50 hover:bg-clay-600 disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-clay-500">+</button>
                                </div>
                                <span class="mt-1.5 block text-xs text-ink-500">Perkiraan, bisa berubah</span>
                            </div>
                            {!! $galat('jumlah') !!}
                        </div>
                        <div class="min-w-0">
                            <span class="wt-field-label mb-1.5 block" id="label-peserta">Jumlah tamu</span>
                            <div>
                                <div @class(['inline-flex overflow-hidden rounded-md border', 'border-cream-400' => ! $errors->has('peserta'), 'border-status-dirty' => $errors->has('peserta')]) role="group" aria-labelledby="label-peserta" data-stepper-peserta>
                                    <button type="button" @click="ubahPeserta(-1)" :disabled="peserta <= 1" aria-label="Kurangi tamu"
                                            class="flex h-11 w-10 items-center justify-center border-0 bg-cream-50 text-lg sm:w-11 text-brown-800 hover:bg-cream-200 disabled:cursor-not-allowed disabled:opacity-35">−</button>
                                    <input type="number" name="peserta" x-model.number="peserta" @input="pesertaDiubah = true" @blur="ubahPeserta(0)" value="{{ (int) old('peserta', min(200, $jumlahAwal * 2)) }}" min="1" max="200" inputmode="numeric" aria-labelledby="label-peserta"
                                           class="h-11 w-12 border-0 border-x sm:w-14 border-cream-400 bg-brown-800 text-center font-display text-sm font-bold tabular-nums text-cream-50 [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
                                    <button type="button" @click="ubahPeserta(1)" :disabled="peserta >= 200" aria-label="Tambah tamu"
                                            class="flex h-11 w-10 items-center justify-center border-0 bg-clay-500 text-lg sm:w-11 text-cream-50 hover:bg-clay-600 disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-clay-500">+</button>
                                </div>
                                <span class="mt-1.5 block text-xs text-ink-500">Perkiraan, bisa berubah</span>
                            </div>
                            {!! $galat('peserta') !!}
                        </div>
                        </div>
                        <label class="block">
                            <span class="wt-field-label mb-1.5 block">Nama kegiatan (opsional)</span>
                            <input type="text" name="kegiatan" value="{{ old('kegiatan') }}" maxlength="150" class="wt-input h-11 {{ $isian('kegiatan') }}">
                            {!! $galat('kegiatan') !!}
                        </label>
                    </div>
                </fieldset>

                {{-- ③ Catatan tambahan: chip menambah atau menghapus satu baris di catatan yang sama --}}
                <fieldset class="m-0 min-w-0 border-0 border-t border-cream-300 p-5 sm:p-6">
                    <legend class="float-left mb-4 w-full p-0">
                        <span class="flex items-center gap-3">
                            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-brown-800 font-display text-sm font-bold text-cream-50" aria-hidden="true">3</span>
                            <span class="font-display text-[13px] font-bold uppercase tracking-[.08em] text-ink-900">Catatan tambahan</span>
                        </span>
                    </legend>
                    <div class="clear-left mb-3 flex flex-wrap gap-2" data-chip-catatan>
                        @foreach (\App\Models\Reservation::KEBUTUHAN_INSTANSI as $chip)
                            <button type="button" @click="chip(@js($chip))" :aria-pressed="adaChip(@js($chip))"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] transition"
                                    :class="adaChip(@js($chip)) ? 'border-brown-800 bg-brown-800 text-cream-50' : 'border-cream-300 bg-cream-50 text-brown-800 hover:bg-cream-200'">
                                <span aria-hidden="true" x-text="adaChip(@js($chip)) ? '✓' : '+'">+</span>{{ $chip }}
                            </button>
                        @endforeach
                    </div>
                    <label class="block">
                        <span class="wt-field-label mb-1.5 block">Catatan (opsional)</span>
                        <textarea name="catatan" x-model="catatan" rows="3" maxlength="500" placeholder="Tuliskan kebutuhan lain bila ada" class="wt-input {{ $isian('catatan') }}">{{ old('catatan') }}</textarea>
                        {!! $galat('catatan') !!}
                    </label>
                </fieldset>

                {{-- Ponsel: ringkasan di atas tombol kirim; tombol menempel di bawah layar selama kartu formulir terlihat --}}
                <div class="px-5 sm:px-6 lg:hidden">@include('tamu.partials.ringkasan-instansi')</div>
                <div class="sticky bottom-0 z-10 rounded-b-[6px] border-t border-cream-300 bg-cream-50/95 px-5 pb-4 pt-3 backdrop-blur-sm max-lg:mt-5 sm:px-6 lg:static lg:border-0 lg:bg-transparent lg:pb-6 lg:pt-0 lg:backdrop-blur-none">
                    <button class="wt-btn-primary h-12 w-full text-[13px] tracking-[.08em]" data-kirim-instansi>Kirim pengajuan</button>
                    <p class="mb-0 mt-2 text-center text-xs text-ink-500">Tidak ada pembayaran sekarang. Kami menghubungi Anda lewat WhatsApp dalam 1×24 jam.</p>
                </div>
            </div>

            <aside class="flex min-w-0 flex-col gap-4 lg:sticky lg:top-24">
                <div class="hidden lg:block">@include('tamu.partials.ringkasan-instansi')</div>

                <div class="rounded-lg bg-cream-200 p-5 text-sm leading-relaxed" data-alur-instansi>
                    <div class="font-display text-[13px] font-bold uppercase tracking-[.08em]">Alurnya</div>
                    <ol class="m-0 mt-4 flex list-none flex-col p-0">
                        @foreach ([
                            ['formulir', 'Kirim pengajuan ini.'],
                            ['orang', 'Manager memeriksa ketersediaan, lalu menetapkan kamar dan harga.'],
                            ['wa', 'Anda menerima kabar lewat WhatsApp; statusnya bisa dicek kapan saja.'],
                        ] as [$ikon, $t])
                            <li class="relative flex gap-3 pb-5 last:pb-0">
                                @unless ($loop->last)
                                    <span class="absolute bottom-0 left-[17px] top-9 border-l-2 border-dashed border-cream-400" aria-hidden="true"></span>
                                @endunless
                                <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-cream-50 text-clay-600">
                                    @if ($ikon === 'wa')
                                        {!! $ikonWa(17) !!}
                                    @else
                                        <x-tamu.ikon :nama="$ikon" ukuran="17" />
                                    @endif
                                </span>
                                <span class="pt-1.5">{{ $t }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
                <p class="m-0 flex items-center gap-2 text-[13px] text-ink-500">
                    <span class="text-clay-600">{!! $ikonWa(16) !!}</span>
                    <span>Butuh cepat? Hubungi kami di <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="font-semibold">WhatsApp</a>.</span>
                </p>
            </aside>
        </form>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        // Kalender dari partials/tamu-rentang-skrip; di sini jumlah kamar, ringkasan, dan chip catatan
        Alpine.data('pengajuanInstansi', (cfg) => wtGabung(wtRentangInap({
            hariIni: cfg.hariIni, masuk: cfg.masuk, keluar: cfg.keluar, maksMalam: cfg.maksMalam,
            pesanMaks: 'Maksimal ' + cfg.maksMalam + ' malam per pengajuan. Hubungi kami untuk masa menginap lebih lama.',
        }), {
            jumlah: cfg.jumlah,
            peserta: cfg.peserta,
            pesertaDiubah: cfg.pesertaDiubah,
            instansi: cfg.instansi,
            catatan: cfg.catatan,
            init() {
                // Peserta bawaan = kamar × 2, ikut berubah selama tamu belum mengubahnya sendiri
                this.$watch('jumlah', (j) => {
                    if (!this.pesertaDiubah) this.peserta = Math.min(200, Math.max(1, (parseInt(j, 10) || 1) * 2));
                });
            },
            ubahJumlah(n) {
                const j = parseInt(this.jumlah, 10);
                this.jumlah = Math.min(32, Math.max(1, (Number.isNaN(j) ? 1 : j) + n));
            },
            ubahPeserta(n) {
                const p = parseInt(this.peserta, 10);
                if (n !== 0) this.pesertaDiubah = true;
                this.peserta = Math.min(200, Math.max(1, (Number.isNaN(p) ? 1 : p) + n));
            },
            baris() { return this.catatan.split('\n').map((b) => b.trim()).filter(Boolean); },
            adaChip(teks) { return this.baris().includes(teks); },
            chip(teks) {
                const baris = this.baris();
                const baru = baris.includes(teks) ? baris.filter((b) => b !== teks) : [...baris, teks];
                if (baru.join('\n').length <= 500) this.catatan = baru.join('\n');
            },
        }));
    });
</script>
@endpush
</x-layouts.guest>
