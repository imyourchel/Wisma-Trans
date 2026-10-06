@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $hariIni = now()->toDateString();
@endphp

<x-layouts.guest :title="$title ?? null">
<div class="animate-wt-masuk">

    {{-- Hero --}}
    <section class="relative flex min-h-[440px] items-center overflow-hidden bg-brown-900">
        <img src="{{ asset('ds/img/bangunan_depan.png') }}" alt="Bangunan Wisma Trans" class="absolute inset-0 h-full w-full object-cover opacity-[.42]">
        <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(62,42,28,.92),rgba(62,42,28,.35))]"></div>
        <div class="wt-container relative w-full pb-[84px] pt-12">
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
            <x-tamu.rentang-tanggal class="flex-[2_1_400px]" />
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
                    ['Foto lobi', 'clock', 'Resepsionis 24 jam', 'Check-in '.$wisma['checkIn'].' WITA, check-out '.$wisma['checkOut'].' WITA.', ...(auth()->check() ? ['Pesanan saya', route('pesanan')] : ['Cek pesanan', route('cek')])],
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
        // Kalender dari partials/tamu-rentang-skrip; di sini hanya susunan kamar dan tamu
        Alpine.data('pencarianKamar', (hariIni) => wtGabung(wtRentangInap({ hariIni }), {
            kamar: [{ dewasa: 2, anak: 0 }],
            buka: false,
            saatBukaKalender() { this.buka = false; },
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
            // Susunan tamu dikirim sebagai tamu=dewasa-anak per kamar ("2-0_3-1"); 1 kamar 2 dewasa tidak perlu dikirim
            kirim(e) {
                this.pastikanKeluar();
                const form = e.target;
                form.querySelectorAll('input[data-tamu]').forEach(el => el.remove());
                const tamu = this.kamar.map(k => k.dewasa + '-' + k.anak).join('_');
                if (tamu === '2-0') return;
                const el = document.createElement('input');
                el.type = 'hidden'; el.name = 'tamu'; el.value = tamu; el.dataset.tamu = '';
                form.appendChild(el);
            },
        }));
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
