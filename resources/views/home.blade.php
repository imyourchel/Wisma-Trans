@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $hariIni = now()->toDateString();
@endphp

<x-layouts.guest :title="$title ?? null">
<div class="animate-wt-masuk">

    {{-- Hero --}}
    <section class="relative flex min-h-[560px] items-center overflow-hidden bg-brown-900">
        <img src="{{ asset('ds/img/bangunan_depan.png') }}" alt="Bangunan Wisma Trans" class="absolute inset-0 h-full w-full object-cover opacity-[.42]">
        <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(62,42,28,.92),rgba(62,42,28,.35))]"></div>
        <div class="wt-container relative w-full pb-[88px]">
            <div class="font-display text-xs font-bold uppercase tracking-[.22em] text-sand-300">Penginapan keluarga · Malili</div>
            <h1 class="mt-4 mb-0 max-w-[720px] font-display text-[34px] leading-[1.12] font-extrabold uppercase tracking-[.04em] text-balance text-cream-150 sm:text-[46px]">Menginap nyaman di jantung Malili</h1>
            <p class="mt-4 mb-7 max-w-[520px] text-base text-[rgba(242,235,220,.82)]">{{ $roomCount }} kamar bersih, restoran, dan aula {{ $hallCapacity }} orang di Jln. Soekarno Hatta No. 17.</p>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('kamar') }}" class="wt-btn-primary h-12 px-7 text-[13px] tracking-[.08em]">Pesan kamar</a>
                <a href="#layanan" class="inline-flex h-12 items-center whitespace-nowrap rounded border border-[rgba(242,235,220,.28)] bg-[rgba(242,235,220,.12)] px-7 font-display text-[13px] font-semibold uppercase tracking-[.08em] text-cream-150 hover:bg-[rgba(242,235,220,.2)] hover:text-cream-150 hover:no-underline">Lihat fasilitas</a>
            </div>
        </div>
    </section>

    {{-- Bilah pencarian --}}
    <div class="wt-container relative z-[5] -mt-[52px]">
        <form method="GET" action="{{ route('kamar') }}"
              x-data="pencarianKamar(@js($hariIni))" @submit="kirim" @resize.window.debounce.150ms="lebar = window.innerWidth"
              class="flex flex-wrap items-stretch rounded-md border border-cream-300 bg-cream-50 p-2 shadow-wt-lg">
            {{-- Rentang tanggal: satu kotak check-in → check-out, kalender dua bulan (satu bulan di ponsel) --}}
            <div class="relative min-w-0 flex-[2_1_400px] p-1.5" @click.outside="tutupKalender()" @keydown.escape="tutupKalender()">
                <div class="grid h-full grid-cols-[1fr_auto_1fr] items-stretch gap-1">
                    @foreach ([['masuk', 'Check-in'], ['keluar', 'Check-out']] as [$ujung, $label])
                        @if ($ujung === 'keluar')
                            <div class="flex flex-col items-center justify-center gap-1.5 px-0.5 sm:px-1" aria-hidden="true">
                                <span class="h-px w-6 bg-cream-400"></span>
                                <span class="whitespace-nowrap rounded-full bg-cream-200 px-2 py-1 text-[10px] font-bold tabular-nums text-brown-800 sm:px-2.5 sm:text-[11px]" x-text="keluar ? malam + ' malam' : '—'"></span>
                                <span class="h-px w-6 bg-cream-400"></span>
                            </div>
                        @endif
                        <button type="button" @click="bukaKalender('{{ $ujung }}')" :aria-expanded="kalender"
                                class="flex min-w-0 flex-col items-start justify-center rounded-md border border-transparent px-2.5 py-2.5 text-left transition hover:bg-cream-200 sm:px-4"
                                :class="kalender && tahap === '{{ $ujung }}' && '!border-clay-500 bg-cream-200'">
                            <span class="wt-micro-label">{{ $label }}</span>
                            <span class="mt-1 flex max-w-full items-baseline gap-1.5 overflow-hidden">
                                <span class="truncate font-display text-[15px] font-bold tabular-nums text-ink-900 sm:text-[17px]" x-text="{{ $ujung }} ? teksTgl({{ $ujung }}) : 'Pilih tanggal'"></span>
                                <span x-show="{{ $ujung }}" class="flex-none whitespace-nowrap text-[13px] text-ink-500" x-text="{{ $ujung }} ? (lebar < 640 ? hariPendek({{ $ujung }}) : namaHari({{ $ujung }})) : ''"></span>
                            </span>
                        </button>
                    @endforeach
                </div>
                <input type="hidden" name="masuk" :value="masuk">
                <input type="hidden" name="keluar" :value="keluar">

                <div x-show="kalender" x-cloak
                     x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     class="absolute left-0 top-[calc(100%+8px)] z-[60] w-[660px] max-w-[calc(100vw-48px)] rounded-xl border border-cream-300 bg-cream-50 p-4 shadow-wt-lg sm:p-5"
                     role="dialog" aria-label="Pilih tanggal menginap">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <button type="button" @click="geser(-1)" :disabled="!bisaMundur" aria-label="Bulan sebelumnya"
                                class="flex h-9 w-9 items-center justify-center rounded-full border border-cream-300 bg-cream-50 text-brown-800 hover:bg-cream-200 disabled:cursor-not-allowed disabled:opacity-30">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        </button>
                        <div class="text-center text-[13px] text-ink-500" x-text="petunjuk"></div>
                        <button type="button" @click="geser(1)" :disabled="!bisaMaju" aria-label="Bulan berikutnya"
                                class="flex h-9 w-9 items-center justify-center rounded-full border border-cream-300 bg-cream-50 text-brown-800 hover:bg-cream-200 disabled:cursor-not-allowed disabled:opacity-30">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                    </div>

                    <div class="grid gap-8" :class="bulanTampil.length > 1 && 'grid-cols-2'" @mouseleave="arah = null">
                        <template x-for="b in bulanTampil" :key="b.kunci">
                            <div>
                                <div class="mb-3 text-center font-display text-[13px] font-bold uppercase tracking-[.1em] text-ink-900" x-text="b.judul"></div>
                                <div class="grid grid-cols-7 pb-1 text-center text-[11px] font-semibold uppercase tracking-[.04em] text-ink-500">
                                    <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                </div>
                                <div class="grid grid-cols-7 gap-y-1">
                                    <template x-for="h in b.hari" :key="h.kunci">
                                        <div class="relative flex h-10 items-center justify-center">
                                            <span x-show="h.pita" class="absolute inset-y-0.5 bg-cream-200" :class="h.pita"></span>
                                            <template x-if="h.iso">
                                                <button type="button" :disabled="h.lalu" @click="pilih(h.iso)" @mouseenter="arah = h.iso"
                                                        :aria-label="labelHari(h.iso)" :aria-pressed="h.ujung"
                                                        class="relative z-[1] flex h-10 w-10 items-center justify-center rounded-full border-0 text-sm tabular-nums"
                                                        :class="h.lalu ? 'cursor-not-allowed bg-transparent text-ink-500/35 line-through'
                                                            : h.ujung ? 'bg-brown-800 font-semibold text-cream-50 shadow-wt-xs'
                                                            : h.dalam ? 'bg-transparent text-brown-800 hover:bg-cream-300'
                                                            : 'bg-transparent text-ink-900 hover:bg-cream-200'">
                                                    <span x-text="h.tgl"></span>
                                                    <span x-show="h.hariIni && !h.ujung" class="absolute bottom-1 h-1 w-1 rounded-full bg-clay-500"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-cream-300 pt-4">
                        <span class="mr-1 text-xs text-ink-500">Lama menginap</span>
                        <template x-for="[n, label] in [[1, '1 malam'], [2, '2 malam'], [3, '3 malam'], [7, '1 minggu']]" :key="n">
                            <button type="button" @click="setDurasi(n)"
                                    class="h-8 rounded-full border px-3 text-xs transition"
                                    :class="keluar && malam === n ? 'border-brown-800 bg-brown-800 text-cream-50' : 'border-cream-300 bg-cream-50 text-brown-800 hover:bg-cream-200'"
                                    x-text="label"></button>
                        </template>
                        <button type="button" @click="tutupKalender()" :disabled="!keluar"
                                class="ml-auto h-9 rounded bg-clay-500 px-5 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-40">Selesai</button>
                    </div>
                </div>
            </div>
            <div class="relative min-w-[210px] flex-1 px-5 py-3 md:border-l md:border-cream-300" @click.outside="buka = false" @click="kalender = false">
                <span class="wt-micro-label mb-1.5 block">Kamar dan tamu</span>
                <button type="button" @click="buka = !buka" :aria-expanded="buka"
                        class="flex h-[42px] w-full items-center justify-between gap-2 rounded border border-cream-300 bg-cream-50 px-3 text-left text-[15px] text-ink-900">
                    <span class="min-w-0 truncate" x-text="ringkasTombol" :title="ringkas"></span>
                    <img src="{{ asset('ds/icons/chevron-up.svg') }}" alt="" width="14" height="14" class="flex-none opacity-60 transition-transform" :class="buka ? '' : 'rotate-180'">
                </button>
                <div x-cloak x-show="buka" x-transition.opacity.duration.150ms
                     class="absolute left-3 top-[92px] z-[60] flex w-[340px] max-w-[90vw] flex-col gap-3 rounded-md border border-cream-300 bg-cream-50 p-5 shadow-wt-lg">
                    <div class="text-[13px] text-ink-500">Maksimal 4 tamu per kamar.</div>
                    <template x-for="(k, i) in kamar" :key="i">
                        <div class="flex flex-col gap-3 rounded border border-cream-300 p-4">
                            <div class="flex items-center justify-between gap-2">
                                <span class="whitespace-nowrap font-display text-sm font-bold uppercase tracking-[.04em]" x-text="'Kamar ' + (i + 1)"></span>
                                <button type="button" x-show="kamar.length > 1" @click="kamar.splice(i, 1)" title="Hapus kamar"
                                        class="flex h-7 w-7 items-center justify-center rounded-md border border-cream-300 bg-cream-50"><img src="{{ asset('ds/icons/x-olive.svg') }}" alt="" width="12" height="12"></button>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm">Dewasa</span>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="ubah(i, 'dewasa', -1)" class="h-9 w-9 rounded border border-cream-300 bg-cream-50 text-[17px] text-brown-800 hover:bg-cream-200" aria-label="Kurangi dewasa">−</button>
                                    <span class="min-w-6 text-center font-display font-bold tabular-nums" x-text="k.dewasa"></span>
                                    <button type="button" @click="ubah(i, 'dewasa', 1)" class="h-9 w-9 rounded border border-cream-300 bg-cream-50 text-[17px] text-brown-800 hover:bg-cream-200" aria-label="Tambah dewasa">+</button>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm">Anak<span class="block text-xs text-ink-500">di bawah 8 tahun</span></span>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="ubah(i, 'anak', -1)" class="h-9 w-9 rounded border border-cream-300 bg-cream-50 text-[17px] text-brown-800 hover:bg-cream-200" aria-label="Kurangi anak">−</button>
                                    <span class="min-w-6 text-center font-display font-bold tabular-nums" x-text="k.anak"></span>
                                    <button type="button" @click="ubah(i, 'anak', 1)" class="h-9 w-9 rounded border border-cream-300 bg-cream-50 text-[17px] text-brown-800 hover:bg-cream-200" aria-label="Tambah anak">+</button>
                                </div>
                            </div>
                        </div>
                    </template>
                    <button type="button" @click="tambahKamar" class="h-11 rounded border border-dashed border-cream-300 bg-cream-50 font-display text-[13px] font-semibold text-brown-800 hover:bg-cream-200">Tambah kamar</button>
                    <div class="flex items-center justify-between gap-3 border-t border-cream-300 pt-3">
                        <span class="max-w-[150px] text-xs text-ink-500">Anak di bawah 8 tahun tidak dikenakan biaya.</span>
                        <button type="button" @click="terapkan" class="h-10 rounded bg-clay-500 px-5 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600">Terapkan</button>
                    </div>
                </div>
            </div>
            <div class="flex min-w-[190px] flex-col justify-center py-2 pr-2 pl-5 max-md:w-full max-md:pl-2 md:border-l md:border-cream-300">
                <button type="submit" class="wt-btn-primary h-[52px] w-full px-6 text-xs tracking-[.1em]">Cek ketersediaan</button>
            </div>
        </form>
    </div>

    {{-- Ringkasan fasilitas --}}
    <section class="mt-14 bg-cream-200">
        <div class="wt-container grid grid-cols-2 gap-y-8 py-9 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ([
                ['map-pin', 'Di pusat Malili', 'Jln. Soekarno Hatta No. 17'],
                ['bed-double', $roomCount.' kamar', 'AC, TV, air panas, kulkas mini'],
                ['utensils', 'Resto dan kafe', 'Sarapan termasuk, antar ke kamar'],
                ['users', 'Aula '.$hallCapacity.' orang', 'Rapat, pelatihan, acara keluarga'],
                ['sparkles', 'Laundry', $rp($laundryPrice).' per kilogram'],
            ] as [$ikon, $judul, $isi])
                <div @class(['flex flex-col items-center gap-2.5 px-4 text-center', 'lg:border-l lg:border-cream-300' => ! $loop->first])>
                    <img src="{{ asset("ds/icons/{$ikon}.svg") }}" alt="" width="24" height="24" class="block flex-none">
                    <div class="font-display text-xs font-bold uppercase tracking-[.12em] text-ink-900">{{ $judul }}</div>
                    <div class="text-[13px] text-ink-500">{{ $isi }}</div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Pilihan kamar --}}
    <section class="wt-container pt-16">
        <div class="mb-8 flex flex-col items-center gap-3 text-center">
            <div class="wt-eyebrow">Menginap dengan nyaman</div>
            <h2 class="wt-section-title">Pilihan kamar</h2>
            <div class="h-px w-16 bg-cream-300"></div>
            <p class="m-0 max-w-[480px] text-ink-500">Harga sudah termasuk pajak dan sarapan.</p>
        </div>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            @foreach ($roomTypes as $tipe)
                <a href="{{ route('kamar.tipe', $tipe->code) }}"
                   class="group flex flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 text-ink-900 shadow-wt-xs hover:-translate-y-0.5 hover:text-ink-900 hover:no-underline hover:shadow-wt-md">
                    <div class="relative flex aspect-[4/3] items-center justify-center border-b border-cream-300 bg-cream-200">
                        <span class="text-xs uppercase tracking-[.14em] text-ink-500">Foto kamar</span>
                        <span class="absolute top-3 left-3 whitespace-nowrap rounded-full bg-[rgba(78,52,35,.82)] px-2.5 py-[5px] font-display text-[11px] font-bold uppercase tracking-[.12em] text-cream-150">{{ $tipe->code }}</span>
                    </div>
                    <div class="flex flex-1 flex-col items-center gap-2.5 p-5 text-center">
                        <div class="font-display text-[17px] font-bold uppercase tracking-[.04em]">{{ $tipe->name }}</div>
                        <div class="text-[13px] text-pretty text-ink-500">{{ $tipe->facilities->take(4)->pluck('name')->join(' · ') }}</div>
                        <div class="mt-1 flex flex-col items-center gap-0.5">
                            <span class="wt-micro-label !tracking-[.16em]">Mulai dari</span>
                            <div class="flex items-baseline gap-[5px] whitespace-nowrap">
                                <span class="font-display text-[19px] font-bold tabular-nums text-clay-500">{{ $rp($tipe->base_price) }}</span>
                                <span class="text-xs text-ink-500">/ malam</span>
                            </div>
                        </div>
                        <span class="mt-auto flex h-10 w-full items-center justify-center rounded bg-brown-800 font-display text-[11px] font-semibold uppercase tracking-[.12em] text-cream-150 group-hover:bg-brown-600">Lihat detail</span>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-7 flex justify-center">
            <a href="{{ route('kamar') }}" class="wt-btn-outline h-11 px-7 text-[11px] tracking-[.12em]">Lihat semua kamar</a>
        </div>
    </section>

    {{-- Layanan --}}
    <section id="layanan" class="mt-16 scroll-mt-20 bg-cream-200">
        <div class="wt-container py-16">
            <div class="mb-8 flex flex-col items-center gap-3 text-center">
                <div class="wt-eyebrow">Selama Anda menginap</div>
                <h2 class="wt-section-title">Layanan kami</h2>
                <div class="h-px w-16 bg-cream-400"></div>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-4">
                @foreach ([
                    ['Foto restoran', 'utensils', 'Resto dan kafe', 'Sarapan termasuk. Pesanan dapat diantar ke kamar.', 'Lihat menu', route('menu')],
                    ['Foto laundry', 'sparkles', 'Laundry', 'Cuci kiloan '.$rp($laundryPrice).' per kg, selesai 1×24 jam.', 'Lihat tarif', route('laundry')],
                    ['Foto aula', 'users', 'Aula', 'Kapasitas '.$hallCapacity.' orang untuk rapat dan acara keluarga.', 'Ajukan sewa', route('aula')],
                    ['Foto lobi', 'clock', 'Resepsionis 24 jam', 'Check-in '.$wisma['checkIn'].' WITA, check-out '.$wisma['checkOut'].' WITA.', 'Cek pesanan', route('cek')],
                ] as [$foto, $ikon, $judul, $isi, $tautan, $url])
                    <div class="flex flex-col items-center text-center">
                        <div class="flex aspect-[16/10] w-full items-center justify-center rounded-md border border-cream-400 bg-cream-500 text-[11px] uppercase tracking-[.14em] text-ink-500">{{ $foto }}</div>
                        <div class="relative z-[2] -mt-6 flex h-12 w-12 items-center justify-center rounded-full border border-cream-300 bg-cream-50">
                            <img src="{{ asset("ds/icons/{$ikon}.svg") }}" alt="" width="22" height="22" class="block flex-none">
                        </div>
                        <div class="mt-3 font-display text-[13px] font-bold uppercase tracking-[.12em]">{{ $judul }}</div>
                        <div class="mt-1.5 max-w-[220px] text-sm text-pretty text-ink-500">{{ $isi }}</div>
                        <a href="{{ $url }}" class="pt-1 text-[13px] text-olive-600">{{ $tautan }}</a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Menu pilihan --}}
    <section class="wt-container pt-14">
        <div class="mb-8 flex flex-col items-center gap-3 text-center">
            <div class="wt-eyebrow">Dari dapur kami</div>
            <h2 class="wt-section-title">Menu pilihan</h2>
            <div class="h-px w-16 bg-cream-300"></div>
        </div>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            @foreach ($menus as $menu)
                <div class="overflow-hidden rounded-md border border-cream-300 bg-cream-50">
                    <div class="relative flex h-[104px] items-center justify-center bg-cream-200 text-xs uppercase tracking-[.14em] text-ink-500">
                        Foto menu
                        @if ($menu->isTerlaris())
                            <span title="Menu terlaris" class="absolute top-2.5 right-2.5 flex h-[26px] w-[26px] items-center justify-center rounded-full bg-[rgba(78,52,35,.82)]">
                                <img src="{{ asset('ds/icons/star.svg') }}" alt="Menu terlaris" width="14" height="14" class="block">
                            </span>
                        @endif
                    </div>
                    <div class="flex flex-col gap-1.5 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">{{ $menu->name }}</span>
                            @if ($menu->isNew())
                                <span class="whitespace-nowrap rounded-full bg-[rgba(92,158,103,.18)] px-2 py-0.5 text-[11px] font-bold text-status-available">Baru</span>
                            @endif
                        </div>
                        <div class="font-display font-bold tabular-nums text-clay-500">{{ $menu->price === null ? 'Menyesuaikan ukuran' : $rp($menu->price) }}</div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-7 flex justify-center">
            <a href="{{ route('menu') }}" class="wt-btn-outline h-11 px-7 text-[11px] tracking-[.12em]">Lihat menu lengkap</a>
        </div>
    </section>

    {{-- Ajakan untuk instansi --}}
    <section class="relative mt-16 flex min-h-[300px] items-center overflow-hidden bg-brown-900">
        <img src="{{ asset('ds/img/bangunan_samping.png') }}" alt="Bangunan Wisma Trans" class="absolute inset-0 h-full w-full object-cover opacity-[.42]">
        <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(62,42,28,.92),rgba(62,42,28,.35))]"></div>
        <div class="wt-container relative flex w-full flex-col items-start gap-3.5 py-14">
            <div class="font-display text-[11px] font-bold uppercase tracking-[.22em] text-sand-300">Untuk instansi dan perusahaan</div>
            <h2 class="m-0 max-w-[560px] font-display text-[26px] leading-[1.2] font-extrabold uppercase tracking-[.05em] text-balance text-cream-150 sm:text-[30px]">Rencanakan kunjungan kerja tim Anda</h2>
            <p class="m-0 max-w-[480px] text-[15px] text-[rgba(242,235,220,.82)]">Ajukan kebutuhan kamar tanpa pembayaran di muka. Tim kami menghubungi Anda dalam 1×24 jam.</p>
            <a href="{{ route('instansi') }}" class="wt-btn-primary mt-2 h-11 px-7 text-xs tracking-[.1em]">Ajukan reservasi instansi</a>
        </div>
    </section>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('pencarianKamar', (hariIni) => ({
            hariIni,
            masuk: hariIni,
            keluar: tambahHari(hariIni, 1),
            kamar: [{ dewasa: 2, anak: 0 }],
            buka: false,

            // Kalender rentang tanggal
            kalender: false,
            tahap: 'masuk',
            arah: null,
            bulanAwal: hariIni.slice(0, 7),
            lebar: window.innerWidth,
            get malam() { return this.keluar ? selisih(this.masuk, this.keluar) : 0 },
            // Kalender tetap terbuka setelah memilih; ditutup lewat tombol Selesai
            get petunjuk() {
                if (this.tahap === 'keluar') return 'Sekarang pilih tanggal check-out';
                if (!this.keluar) return 'Pilih tanggal check-in';
                const pendek = (iso) => tanggal(iso).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
                return pendek(this.masuk) + ' – ' + pendek(this.keluar) + ' · ' + this.malam + ' malam';
            },
            get bisaMundur() { return this.bulanAwal > this.hariIni.slice(0, 7) },
            get bisaMaju() { return this.bulanAwal < tambahBulan(this.hariIni.slice(0, 7), 11) },
            get bulanTampil() {
                const jumlah = this.lebar >= 768 ? 2 : 1;
                const akhir = this.keluar || (this.tahap === 'keluar' && this.arah > this.masuk ? this.arah : null);
                return Array.from({ length: jumlah }, (_, i) => {
                    const kunci = tambahBulan(this.bulanAwal, i);
                    const [y, m] = kunci.split('-').map(Number);
                    const kosong = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7; // minggu mulai Senin
                    const jumlahHari = new Date(Date.UTC(y, m, 0)).getUTCDate();
                    const hari = Array.from({ length: kosong }, (_, j) => ({ kunci: kunci + '-k' + j }));
                    for (let d = 1; d <= jumlahHari; d++) {
                        const iso = kunci + '-' + String(d).padStart(2, '0');
                        const ujung = iso === this.masuk || iso === akhir;
                        const dalam = akhir && iso > this.masuk && iso < akhir;
                        // Pita krem menyambung antara check-in dan check-out
                        const pita = !akhir ? null
                            : dalam ? 'inset-x-0'
                            : iso === this.masuk && akhir > this.masuk ? 'left-1/2 right-0'
                            : iso === akhir ? 'left-0 right-1/2' : null;
                        hari.push({ kunci: iso, iso, tgl: d, lalu: iso < this.hariIni, hariIni: iso === this.hariIni, ujung, dalam, pita });
                    }
                    return { kunci, judul: new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }), hari };
                });
            },
            // Di ponsel tahun disembunyikan agar tanggal dan nama hari muat satu baris
            teksTgl(iso) { return tanggal(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', timeZone: 'UTC', ...(this.lebar >= 640 && { year: 'numeric' }) }) },
            namaHari(iso) { return tanggal(iso).toLocaleDateString('id-ID', { weekday: 'long', timeZone: 'UTC' }) },
            hariPendek(iso) { return tanggal(iso).toLocaleDateString('id-ID', { weekday: 'short', timeZone: 'UTC' }) },
            labelHari(iso) { return tanggal(iso).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }) },
            bukaKalender(ujung) {
                this.buka = false;
                this.tahap = ujung === 'keluar' ? 'keluar' : 'masuk';
                this.bulanAwal = (ujung === 'keluar' && this.keluar ? this.keluar : this.masuk).slice(0, 7);
                if (this.lebar >= 768 && ujung === 'keluar' && this.bulanAwal > this.masuk.slice(0, 7)) this.bulanAwal = this.masuk.slice(0, 7);
                this.kalender = true;
            },
            tutupKalender() {
                if (!this.kalender) return;
                if (!this.keluar) this.keluar = tambahHari(this.masuk, 1);
                this.kalender = false;
                this.arah = null;
            },
            geser(n) {
                if ((n < 0 && !this.bisaMundur) || (n > 0 && !this.bisaMaju)) return;
                this.bulanAwal = tambahBulan(this.bulanAwal, n);
            },
            pilih(iso) {
                if (iso < this.hariIni) return;
                // Klik pertama = check-in; klik kedua setelahnya = check-out
                if (this.tahap === 'masuk' || iso <= this.masuk) {
                    this.masuk = iso;
                    this.keluar = null;
                    this.tahap = 'keluar';
                    return;
                }
                if (selisih(this.masuk, iso) > 30) {
                    Alpine.store('toasts').tampil('Maksimal 30 malam per pemesanan. Hubungi kami untuk menginap lebih lama.', '#E8AC45');
                    return;
                }
                this.keluar = iso;
                this.tahap = 'masuk';
            },
            setDurasi(n) {
                this.keluar = tambahHari(this.masuk, n);
                this.tahap = 'masuk';
            },
            // Versi pendek untuk tombol agar selalu satu baris; rincian lengkap di tooltip dan panel
            get ringkasTombol() {
                const dewasa = this.kamar.reduce((a, k) => a + k.dewasa, 0);
                const anak = this.kamar.reduce((a, k) => a + k.anak, 0);
                return this.kamar.length + ' kamar · ' + (anak ? (dewasa + anak) + ' tamu' : dewasa + ' dewasa');
            },
            get ringkas() {
                const dewasa = this.kamar.reduce((a, k) => a + k.dewasa, 0);
                const anak = this.kamar.reduce((a, k) => a + k.anak, 0);
                return this.kamar.length + ' kamar · ' + dewasa + ' dewasa' + (anak ? ', ' + anak + ' anak' : '');
            },
            ubah(i, jenis, delta) {
                const k = this.kamar[i], baru = k[jenis] + delta;
                if (jenis === 'dewasa' && baru < 1) return;
                if (jenis === 'anak' && baru < 0) return;
                if (delta > 0 && k.dewasa + k.anak >= 4) { Alpine.store('toasts').tampil('Maksimal 4 tamu per kamar', '#E8AC45'); return; }
                k[jenis] = baru;
            },
            tambahKamar() {
                if (this.kamar.length >= 8) { Alpine.store('toasts').tampil('Maksimal 8 kamar per pencarian', '#E8AC45'); return; }
                this.kamar.push({ dewasa: 2, anak: 0 });
            },
            terapkan() { this.buka = false; Alpine.store('toasts').tampil(this.ringkas + ' diterapkan') },
            // Susunan tamu per kamar dikirim sebagai kamar[i][dewasa|anak]
            kirim(e) {
                if (!this.keluar) this.keluar = tambahHari(this.masuk, 1);
                const form = e.target;
                form.querySelectorAll('input[data-tamu]').forEach(el => el.remove());
                this.kamar.forEach((k, i) => ['dewasa', 'anak'].forEach(j => {
                    const el = document.createElement('input');
                    el.type = 'hidden'; el.name = `kamar[${i}][${j}]`; el.value = k[j]; el.dataset.tamu = '';
                    form.appendChild(el);
                }));
            },
        }));

        function tanggal(iso) {
            const [y, m, d] = iso.split('-').map(Number);
            return new Date(Date.UTC(y, m - 1, d));
        }
        function selisih(a, b) { return Math.round((tanggal(b) - tanggal(a)) / 86400000) }
        function tambahBulan(ym, n) {
            const [y, m] = ym.split('-').map(Number);
            const t = new Date(Date.UTC(y, m - 1 + n, 1));
            return t.getUTCFullYear() + '-' + String(t.getUTCMonth() + 1).padStart(2, '0');
        }
        function tambahHari(iso, n) {
            const [y, m, d] = iso.split('-').map(Number);
            return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10);
        }
    });
</script>
@endpush

{{-- Langkah terakhir daftar dengan Google tampil di atas beranda --}}
@isset($googlePending)
    @include('auth.google-lengkapi')
@endisset
@isset($aturUlang)
    @include('auth.atur-ulang-sandi')
@endisset
</x-layouts.guest>
