@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $hariIni = now()->toDateString();
@endphp

<x-layouts.guest :title="$title ?? null">
<div class="animate-wt-masuk">

    {{-- Hero --}}
    <section class="relative flex min-h-[440px] items-center overflow-hidden bg-brown-900" style="min-height: clamp(480px, 62vh, 560px)">
        <img src="{{ asset('ds/img/bangunan_depan.png') }}" alt="Bangunan Wisma Trans" class="absolute inset-0 h-full w-full object-cover opacity-[.42]">
        <div class="absolute inset-0" style="background: linear-gradient(90deg, rgba(40,27,18,.95) 0%, rgba(52,35,23,.82) 42%, rgba(62,42,28,.35) 100%)"></div>
        <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(40,27,18,0) 60%, rgba(40,27,18,.6) 100%)"></div>
        <div class="wt-container relative w-full pt-12" style="padding-bottom: 112px">
            <div class="flex items-center gap-3 font-display text-xs font-bold uppercase text-sand-300" style="letter-spacing: .28em">
                <span aria-hidden="true" class="bg-sand-300" style="display:block; width:40px; height:1px; opacity:.7"></span>Wisma Trans · Malili
            </div>
            <h1 class="mt-4 mb-0 max-w-[720px] font-display text-[28px] leading-[1.12] font-extrabold uppercase tracking-[.04em] text-balance text-cream-150 sm:text-[40px] lg:text-[46px]">Menginap nyaman di jantung Malili</h1>
            <span aria-hidden="true" class="mt-4 block bg-sand-300" style="width:56px; height:2px"></span>
            <p class="mt-4 mb-7 max-w-[520px] text-base text-[rgba(242,235,220,.82)]" style="line-height:1.7">Kamar yang bersih dan tenang, sarapan setiap pagi, dan pelayanan yang hangat. Tempat singgah yang tepat untuk perjalanan dinas maupun liburan keluarga.</p>
            {{-- HP: tombol bertumpuk selebar layar --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <a href="{{ route('kamar') }}" class="wt-btn-primary h-12 px-7 text-[13px] tracking-[.08em]">Pesan kamar</a>
                <a href="#layanan" class="inline-flex h-12 items-center justify-center gap-2 whitespace-nowrap rounded border border-[rgba(242,235,220,.28)] px-7 font-display text-[13px] font-semibold uppercase tracking-[.08em] text-cream-150 hover:bg-[rgba(242,235,220,.12)] hover:text-cream-150 hover:no-underline">Lihat fasilitas <span aria-hidden="true">→</span></a>
            </div>
            <ul class="mb-0 flex list-none flex-wrap p-0 text-[13px] text-[rgba(242,235,220,.82)]" style="margin-top:24px; column-gap:24px; row-gap:8px">
                @foreach (['Sarapan termasuk', 'Check-in '.$wisma['checkIn'].' WITA', 'Resepsionis 24 jam'] as $poin)
                    <li class="flex items-center gap-2"><span aria-hidden="true" class="bg-sand-300" style="display:block; width:6px; height:6px; border-radius:9999px"></span>{{ $poin }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Bilah pencarian --}}
    <div class="wt-container relative z-[5] -mt-[52px]">
        <form method="GET" action="{{ route('kamar') }}"
              x-data="pencarianKamar(@js($hariIni))" @submit="kirim" @resize.window.debounce.150ms="lebar = window.innerWidth"
              class="flex flex-wrap items-stretch rounded-md border border-cream-300 bg-cream-50 p-2 shadow-wt-lg">
            <x-tamu.rentang-tanggal class="flex-[2_1_400px]" />
            <div class="relative min-w-[260px] flex-[1_1_340px] px-5 py-3 md:border-l lg:max-w-[380px] md:border-cream-300" @click.outside="buka = false" @click="kalender = false">
                <span class="wt-micro-label mb-1.5 block">Kamar dan tamu</span>
                <button type="button" @click="buka = !buka" :aria-expanded="buka"
                        class="flex h-[42px] pointer-coarse:h-11 w-full items-center justify-between gap-2 rounded border border-cream-300 bg-cream-50 px-3 text-left text-[15px] text-ink-900">
                    <span class="min-w-0 truncate" x-text="ringkasTombol">1 kamar · 2 dewasa</span>
                    <img src="{{ asset('ds/icons/chevron-up.svg') }}" alt="" width="14" height="14" class="flex-none opacity-60 transition-transform" :class="buka ? '' : 'rotate-180'">
                </button>
                <x-tamu.pilih-tamu />
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
                ['users', 'Aula '.$hallCapacity.' orang', 'Mulai '.$rp($tarifAula['min']).' per hari'],
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
                    <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden border-b border-cream-300 bg-cream-200">
                        @if ($foto = $tipe->photoUrls()[0] ?? null)
                            <img src="{{ $foto }}" alt="{{ $tipe->name }}" loading="lazy" class="absolute inset-0 h-full w-full object-cover transition-transform duration-500 motion-safe:group-hover:scale-105">
                        @else
                            <span class="text-xs uppercase tracking-[.14em] text-ink-500">Foto kamar</span>
                        @endif
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
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ([
                    ['ds/img/resto-kafe-wisma.jpg', 'utensils', 'Resto dan kafe', 'Sarapan termasuk. Pesanan dapat diantar ke kamar.', 'Lihat menu', route('menu')],
                    ['ds/img/laundry.jpg', 'sparkles', 'Laundry', 'Cuci kiloan '.$rp($laundryPrice).' per kg, selesai 1×24 jam.', 'Lihat tarif', route('laundry')],
                    ['ds/img/resepsionis.jpg', 'clock', 'Resepsionis 24 jam', 'Check-in '.$wisma['checkIn'].' WITA, check-out '.$wisma['checkOut'].' WITA.', ...(auth()->check() ? ['Pesanan saya', route('pesanan')] : ['Cek pesanan', route('cek')])],
                ] as [$foto, $ikon, $judul, $isi, $tautan, $url])
                    <div class="flex flex-col items-center text-center">
                        <div class="aspect-[16/10] w-full overflow-hidden rounded-md border border-cream-400 bg-cream-500">
                            <img src="{{ asset($foto) }}" alt="{{ $judul }}" loading="lazy" class="h-full w-full object-cover">
                        </div>
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

    {{-- Sewa aula: tarif per hari dari Pengaturan (Hall::tarif), yang termasuk dari Hall::TERMASUK --}}
    <section id="aula" class="relative scroll-mt-20 overflow-hidden bg-brown-900 text-cream-150" data-promo-aula>
        <div class="pointer-events-none absolute -top-24 -right-24 h-72 w-72 rounded-full bg-sand-300/10 blur-3xl" aria-hidden="true"></div>
        <div class="wt-container relative grid items-center gap-10 py-16 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:gap-14">
            <a href="{{ route('aula') }}" class="group relative block overflow-hidden rounded-md shadow-wt-lg hover:no-underline" aria-label="Lihat aula">
                <img src="{{ $fotoAula }}" alt="Aula Wisma Trans" loading="lazy" class="aspect-[4/3] w-full object-cover transition-transform duration-700 motion-safe:group-hover:scale-105">
                <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(40,27,18,0)_45%,rgba(40,27,18,.75)_100%)]"></div>
                <div class="absolute top-4 left-4 flex h-[92px] w-[92px] flex-col items-center justify-center rounded-full bg-sand-300 text-brown-900 shadow-wt-md sm:h-[108px] sm:w-[108px]" data-kapasitas-aula>
                    <span class="font-display text-[30px] leading-none font-extrabold sm:text-[36px]">{{ $hallCapacity }}</span>
                    <span class="mt-1 font-display text-[10px] font-bold uppercase tracking-[.16em]">orang</span>
                </div>
                <div class="absolute right-4 bottom-4 left-4 flex flex-wrap gap-2">
                    @foreach (['Rapat', 'Pelatihan', 'Syukuran', 'Acara keluarga'] as $acara)
                        <span class="rounded-full bg-[rgba(242,235,220,.16)] px-3 py-1 text-xs text-cream-50 backdrop-blur-sm">{{ $acara }}</span>
                    @endforeach
                </div>
            </a>

            <div class="flex flex-col items-start">
                <div class="font-display text-[11px] font-bold uppercase tracking-[.22em] text-sand-300">Sewa aula</div>
                <h2 class="mt-3 mb-0 max-w-[460px] font-display text-[28px] leading-[1.15] font-extrabold uppercase tracking-[.04em] text-balance text-cream-50 sm:text-[34px]">Satu tempat untuk acara besar Anda</h2>

                <div class="mt-6 flex flex-wrap items-end gap-x-4 gap-y-2" data-harga-aula>
                    <div>
                        <div class="font-display text-[11px] font-bold uppercase tracking-[.18em] text-[rgba(242,235,220,.7)]">Mulai dari</div>
                        <div class="font-display text-[40px] leading-none font-extrabold tabular-nums text-sand-300 sm:text-[48px]">{{ $rp($tarifAula['min']) }}</div>
                    </div>
                    <div class="pb-1 text-sm text-[rgba(242,235,220,.75)]">/ hari · {{ \App\Models\Hall::KETERANGAN_HARGA }}</div>
                </div>

                <div class="mt-7 w-full max-w-[480px]">
                    <div class="font-display text-[11px] font-bold uppercase tracking-[.18em] text-[rgba(242,235,220,.7)]">Sudah termasuk</div>
                    <ul class="m-0 mt-3 grid list-none grid-cols-2 gap-x-4 gap-y-2.5 p-0 text-[15px]">
                        @foreach (\App\Models\Hall::TERMASUK as $f)
                            <li class="flex items-center gap-2">
                                <span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-sand-300/20 text-sand-300">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                </span>{{ $f }}
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 mb-0 text-[13px] text-[rgba(242,235,220,.6)]">{{ \App\Models\Hall::CATATAN_HARGA }} Ajukan dulu tanpa bayar di muka, staf kami menghubungi Anda.</p>
                </div>

                <div class="mt-8 flex w-full flex-wrap gap-3">
                    <a href="{{ route('aula.ajukan') }}" class="wt-btn-primary h-12 px-7 text-[13px] tracking-[.08em] max-sm:flex-1">Ajukan sewa</a>
                    <a href="https://wa.me/{{ $wisma['whatsapp'] }}?text={{ rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang sewa aula.') }}" target="_blank" rel="noopener"
                       class="inline-flex h-12 items-center justify-center gap-2.5 whitespace-nowrap rounded border border-[rgba(242,235,220,.28)] px-6 font-display text-[13px] font-semibold uppercase tracking-[.08em] text-cream-150 hover:bg-[rgba(242,235,220,.1)] hover:text-cream-150 hover:no-underline max-sm:flex-1">
                        <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Tanya dulu
                    </a>
                </div>
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
        <div class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fit,minmax(200px,1fr))]">
            @foreach ($menus as $menu)
                <div class="overflow-hidden rounded-md border border-cream-300 bg-cream-50">
                    {{-- Rasio 4:3 sama dengan foto menu, supaya foto tampil utuh tanpa terpotong --}}
                    <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-cream-200 text-xs uppercase tracking-[.14em] text-ink-500">
                        @if ($foto = $menu->fotoUrl())
                            <img src="{{ $foto }}" alt="{{ $menu->name }}" loading="lazy" class="absolute inset-0 h-full w-full object-cover">
                        @else
                            Foto menu
                        @endif
                        @if ($menu->isTerlaris())
                            <span title="Menu terlaris" class="absolute top-2.5 right-2.5 flex h-[26px] w-[26px] items-center justify-center rounded-full bg-[rgba(78,52,35,.82)]">
                                <img src="{{ asset('ds/icons/star.svg') }}" alt="Menu terlaris" width="14" height="14" class="block">
                            </span>
                        @endif
                    </div>
                    <div class="flex flex-col gap-1.5 p-3 sm:p-4">
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
        <img src="{{ asset('ds/img/bangunan_samping.png') }}" alt="Bangunan Wisma Trans" loading="lazy" class="absolute inset-0 h-full w-full object-cover opacity-[.42]">
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
        // Kalender dari partials/tamu-rentang-skrip; di sini susunan tamu per kamar (panel x-tamu.pilih-tamu)
        Alpine.data('pencarianKamar', (hariIni) => wtGabung(wtRentangInap({ hariIni }), {
            kamar: [{ dewasa: 2, anak: 0 }],
            batasTamu: @js(\App\Models\RoomType::batasPencarian()), // { dewasa, anak } per kamar
            buka: false,
            saatBukaKalender() { this.buka = false; },
            get ringkasTombol() {
                const dewasa = this.kamar.reduce((a, k) => a + k.dewasa, 0);
                const anak = this.kamar.reduce((a, k) => a + k.anak, 0);
                return this.kamar.length + ' kamar · ' + dewasa + ' dewasa' + (anak ? ' · ' + anak + ' anak' : '');
            },
            ubahTamu(i, jenis, delta) {
                const k = this.kamar[i], baru = k[jenis] + delta;
                if (baru < (jenis === 'dewasa' ? 1 : 0)) return;
                // Dewasa dan anak dibatasi terpisah (RoomType::ATURAN_KAPASITAS); anak tidak dihitung ke kapasitas dewasa
                if (delta > 0 && baru > this.batasTamu[jenis]) return;
                k[jenis] = baru;
            },
            tambahKamar() {
                if (this.kamar.length < {{ \App\Support\PencarianInap::MAKS_KAMAR }}) this.kamar.push({ dewasa: 2, anak: 0 });
            },
            terapkanTamu() { this.buka = false; },
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
