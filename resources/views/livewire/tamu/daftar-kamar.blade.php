@php
    use App\Support\PilihanKamar;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $wa = 'https://wa.me/'.$wisma['whatsapp'].'?text='.rawurlencode('Halo Wisma Trans, saya ingin menanyakan ketersediaan kamar'.($cari->aktif() ? ' untuk '.$cari->rentang() : '').'.');
    $jumlahKamar = $tipe->sum(fn ($t) => $t->rooms->count());
    $adaPilihan = $ringkasan['jumlah'] > 0;
    // Latar kepala halaman: semua foto di public/ds/img/kamar (urut nama berkas), bergantian; cadangan kamar-hotel.jpg
    $latar = collect(glob(public_path('ds/img/kamar/*')) ?: [])
        ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true))
        ->map(fn ($f) => asset('ds/img/kamar/'.basename($f)))->sort()->values();
    if ($latar->isEmpty()) {
        $latar = collect([asset('ds/img/kamar-hotel.jpg')]);
    }
@endphp

<div @class(['animate-wt-masuk', 'pb-36 md:pb-28' => $adaPilihan])
     x-data="cariKamar(@js(['hariIni' => today()->toDateString(), 'masuk' => $cari->masuk?->toDateString(), 'keluar' => $cari->keluar?->toDateString(), 'kamar' => $susunanTamu, 'batasTamu' => $batasTamu]))"
     @resize.window.debounce.150ms="lebar = window.innerWidth">

    {{-- Kepala halaman --}}
    <section class="relative isolate overflow-hidden bg-brown-900">
        {{-- Foto kamar bergantian pelan (crossfade); diam bila pengguna memilih kurangi gerakan --}}
        <div class="absolute inset-0 -z-10" aria-hidden="true" data-latar-kamar="{{ $latar->count() }}"
             x-data="{ i: 0, n: {{ $latar->count() }} }"
             x-init="if (n > 1 && ! matchMedia('(prefers-reduced-motion: reduce)').matches) setInterval(() => { if (! document.hidden) i = (i + 1) % n }, 6000)">
            @foreach ($latar as $url)
                <img src="{{ $url }}" alt="" @if (! $loop->first) loading="lazy" @endif
                     :class="i === {{ $loop->index }} ? 'opacity-40' : 'opacity-0'"
                     @class(['absolute inset-0 h-full w-full object-cover object-[center_45%] transition-opacity duration-[1500ms] ease-in-out', 'opacity-40' => $loop->first, 'opacity-0' => ! $loop->first])>
            @endforeach
        </div>
        <div class="absolute inset-0 -z-10 bg-[linear-gradient(90deg,rgba(62,42,28,.94),rgba(62,42,28,.55))]"></div>
        <div class="wt-container pb-20 pt-10 sm:pb-24 sm:pt-14">
            <div class="font-display text-[11px] font-semibold uppercase tracking-[.26em] text-sand-300">Menginap di Wisma Trans</div>
            <h1 class="mb-0 mt-3 font-display text-[30px] font-extrabold uppercase leading-tight tracking-[.04em] text-cream-50 sm:text-[40px]">Pilih kamar</h1>
            <p class="mb-0 mt-2 max-w-[560px] text-[15px] text-[rgba(242,235,220,.82)]">
                {{ $tipe->count() }} tipe, {{ $jumlahKamar }} kamar. Check-in mulai {{ $ketentuan['checkIn'] }}, check-out paling lambat {{ $ketentuan['checkOut'] }} WITA.
            </p>
        </div>
    </section>

    {{-- Pencarian --}}
    <div class="wt-container relative z-30 -mt-12" x-ref="kotakCari">
        <form @submit.prevent="cek()" novalidate class="flex flex-wrap items-stretch rounded-md border border-cream-300 bg-cream-50 p-2 shadow-wt-lg" data-form-cari>
            <x-tamu.rentang-tanggal class="flex-[2_1_400px]" />
            {{-- Kamar dan tamu: sama seperti di beranda --}}
            <div class="relative min-w-[260px] flex-[1_1_340px] px-5 py-3 md:border-l lg:max-w-[380px] md:border-cream-300" @click.outside="buka = false" @click="kalender = false">
                <span class="wt-micro-label mb-1.5 block">Kamar dan tamu</span>
                <button type="button" @click="buka = !buka" :aria-expanded="buka"
                        class="flex h-[42px] pointer-coarse:h-11 w-full items-center justify-between gap-2 rounded border border-cream-300 bg-cream-50 px-3 text-left text-[15px] text-ink-900">
                    <span class="min-w-0 truncate" x-text="ringkasTombol">{{ PilihanKamar::teksPencarian($susunanTamu) }}</span>
                    <img src="{{ asset('ds/icons/chevron-up.svg') }}" alt="" width="14" height="14" class="flex-none opacity-60 transition-transform" :class="buka ? '' : 'rotate-180'">
                </button>
                <x-tamu.pilih-tamu />
            </div>
            <div class="flex min-w-[190px] flex-col justify-center py-2 pl-5 pr-2 max-md:w-full max-md:pl-2 md:border-l md:border-cream-300">
                <button type="submit" wire:loading.attr="disabled" wire:target="cek" class="wt-btn-primary h-[52px] w-full px-6 text-xs tracking-[.1em] disabled:cursor-wait disabled:opacity-80">
                    <span wire:loading.remove wire:target="cek">Cek ketersediaan</span>
                    {{-- Tanpa elipsis supaya tidak terbaca sebagai teks terpotong; ikon berputar menandakan sedang memuat --}}
                    <span wire:loading.flex wire:target="cek" class="hidden items-center gap-2.5" role="status">
                        <span class="h-4 w-4 flex-none animate-spin rounded-full border-2 border-cream-50 border-r-transparent" aria-hidden="true"></span>Mengecek
                    </span>
                </button>
            </div>
        </form>
    </div>

    <div class="wt-container pb-16 pt-6 sm:pt-8">
        @if ($cari->galat)
            <div class="mb-5 flex items-start gap-3 rounded-md border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] px-4 py-3 text-sm text-ink-900" role="alert">
                <x-tamu.ikon nama="info" ukuran="18" class="mt-0.5 text-status-dirty" />
                <span>{{ $cari->galat }}</span>
            </div>
        @endif

        {{-- Gagal memuat (jaringan atau server) --}}
        <div x-show="gagal" x-cloak class="mb-5 flex flex-wrap items-center gap-3 rounded-md border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] px-4 py-3 text-sm" role="alert">
            <x-tamu.ikon nama="info" ukuran="18" class="text-status-dirty" />
            <span class="min-w-0 flex-1">Tidak bisa memuat data kamar. Periksa koneksi lalu coba lagi.</span>
            <button type="button" @click="cek()" class="h-9 rounded border border-cream-400 bg-cream-50 px-4 text-xs font-semibold text-brown-800 hover:bg-cream-200">Coba lagi</button>
        </div>

        {{-- Judul hasil --}}
        <div class="mb-5 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 class="m-0 font-display text-lg font-extrabold uppercase tracking-[.04em]">{{ $cari->aktif() ? 'Ketersediaan kamar' : 'Tipe kamar' }}</h2>
                <p class="mb-0 mt-1 text-sm text-ink-500">
                    @if ($cari->aktif())
                        {{ $cari->masuk->translatedFormat('D, j M') }} – {{ $cari->keluar->translatedFormat('D, j M Y') }} · {{ $cari->malam() }} malam · {{ PilihanKamar::teksPencarian($susunanTamu) }}
                    @else
                        Pilih tanggal menginap untuk melihat ketersediaan. Jenis kasur dipilih di halaman detail tiap tipe.
                    @endif
                </p>
                @if ($saran)
                    <p class="mb-0 mt-1 flex items-start gap-1.5 text-[13px] text-clay-600" data-saran-tipe><x-tamu.ikon nama="info" ukuran="15" class="mt-px flex-none" />{{ $saran }}</p>
                @endif
            </div>
            <span class="text-xs text-ink-500">Harga per kamar per malam, termasuk pajak &amp; sarapan</span>
        </div>

        @if ($semuaPenuh)
            <div class="mb-5 rounded-md border border-cream-300 bg-cream-200 px-5 py-5 sm:px-6" wire:loading.remove wire:target="cek">
                <p class="m-0 font-semibold text-ink-900">Semua kamar penuh untuk {{ $cari->rentang() }}.</p>
                <p class="mb-0 mt-1 text-sm text-ink-500">Coba tanggal lain atau hubungi kami.</p>
                <div class="mt-4 flex flex-wrap gap-2.5">
                    <button type="button" @click="ubahTanggal()" class="wt-btn-primary h-10 px-5 text-xs tracking-[.08em]">Ubah tanggal</button>
                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="wt-btn-outline h-10 gap-2 px-5 text-xs tracking-[.08em]">
                        <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16">Hubungi via WhatsApp
                    </a>
                </div>
            </div>
        @endif

        @if ($rekomendasi)
            {{-- Rekomendasi untuk pencarian beberapa kamar; tombolnya mengisi keranjang dengan kombinasi ini.
                 ≥ lg: kiri isi (flex-1), kanan total + tombol (lebar tetap, tidak pernah turun); < lg: total dan tombol di bawah. --}}
            @php $duaKolom = count($rekomendasi['kamar']) > 3; @endphp
            <section class="relative mb-5 overflow-hidden rounded-xl border border-[rgba(181,113,74,.35)] bg-cream-50 shadow-wt-xs" wire:loading.remove wire:target="cek" data-rekomendasi>
                <span class="absolute inset-y-0 left-0 w-1 bg-clay-500" aria-hidden="true"></span>
                <div class="flex flex-col lg:flex-row">
                    <div class="min-w-0 flex-1 p-5 sm:p-6">
                        <div class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(181,113,74,.1)] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[.1em] text-clay-600">
                            <img src="{{ asset('ds/icons/sparkles.svg') }}" alt="" width="12" height="12">Rekomendasi untuk {{ count($rekomendasi['kamar']) }} kamar
                        </div>
                        <h3 class="mb-0 mt-2.5 font-display text-lg font-extrabold uppercase tracking-[.03em]">{{ $rekomendasi['judul'] }}</h3>
                        <p class="mb-0 mt-1 text-sm text-ink-500">Kombinasi termurah yang muat untuk semua tamu dan masih tersedia di tanggal ini.</p>
                        <ul @class(['m-0 mt-4 grid list-none grid-cols-1 border-t border-dashed border-cream-300 p-0', 'xl:grid-cols-2 xl:gap-x-8' => $duaKolom]) data-rekomendasi-kamar>
                            @foreach ($rekomendasi['kamar'] as $k)
                                {{-- Kiri: kamar dan penghuni; kanan: tipe · kasur, jenis kasur lain yang juga muat di baris kecil --}}
                                <li class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-4 border-b border-dashed border-cream-300 py-2.5 text-[13px]">
                                    <span class="leading-snug">
                                        <span class="block font-semibold text-ink-900">Kamar {{ $k['no'] }}</span>
                                        <span class="block text-ink-500">{{ $k['tamu'] }}</span>
                                    </span>
                                    <span class="min-w-0 text-right leading-snug">
                                        <span class="inline-flex items-center gap-1.5 text-ink-900"><x-tamu.ikon nama="kasur" ukuran="14" class="flex-none text-clay-600" />{{ $k['tipe'] }} · {{ $k['kasur'] }}</span>
                                        @if ($k['lain'])
                                            <span class="block text-xs text-ink-500">atau {{ implode(', ', $k['lain']) }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="flex flex-col gap-3 border-t border-cream-300 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6 lg:w-[260px] lg:flex-none lg:flex-col lg:items-stretch lg:justify-start lg:border-l lg:border-t-0">
                        <div class="flex flex-col sm:items-start lg:items-end">
                            <span class="text-xs text-ink-500">Total {{ $cari->malam() }} malam</span>
                            <span class="font-display text-[26px] font-extrabold leading-tight tabular-nums text-ink-900">{{ $rp($rekomendasi['total']) }}</span>
                        </div>
                        <button type="button" wire:click="isiOtomatis" wire:loading.attr="disabled" wire:target="isiOtomatis"
                                class="wt-btn-primary wt-muat-tengah h-11 w-full px-6 text-xs tracking-[.08em] sm:w-auto lg:w-full" data-isi-otomatis>Masukkan ke keranjang</button>
                    </div>
                </div>
            </section>
        @endif

        {{-- Kerangka kartu saat mengecek --}}
        <div wire:loading.flex wire:target="cek" class="hidden flex-col gap-5" aria-hidden="true">
            @for ($k = 0; $k < min(3, max(1, $tipe->count())); $k++)
                <div class="grid animate-pulse overflow-hidden rounded-lg border border-cream-300 bg-cream-50 lg:grid-cols-[minmax(0,360px)_minmax(0,1fr)]">
                    <div class="aspect-[16/10] bg-cream-200 lg:aspect-auto lg:min-h-[240px]"></div>
                    <div class="flex flex-col gap-3 p-5 sm:p-6">
                        <div class="h-5 w-44 rounded bg-cream-200"></div>
                        <div class="h-3.5 w-full max-w-[420px] rounded bg-cream-200"></div>
                        <div class="h-3.5 w-3/5 rounded bg-cream-200"></div>
                        <div class="mt-2 flex gap-2"><div class="h-7 w-16 rounded-full bg-cream-200"></div><div class="h-7 w-16 rounded-full bg-cream-200"></div><div class="h-7 w-16 rounded-full bg-cream-200"></div></div>
                        <div class="mt-auto h-10 w-40 rounded bg-cream-200"></div>
                    </div>
                </div>
            @endfor
        </div>

        {{-- Kartu tipe kamar: badge karakter, info ringkas di tengah, harga + jenis kasur + satu tombol di kanan.
             Urut: muat, perlu extra bed, lalu tidak muat (diredupkan, tersembunyi di balik "Tampilkan n tipe lain"). --}}
        @php $jumlahTidakMuat = $tipe->where('cocok', \App\Models\RoomType::TIDAK_MUAT)->count(); @endphp
        <div wire:loading.remove wire:target="cek" class="flex flex-col gap-5" x-data="{ lainTerbuka: false }">
            @forelse ($tipe as $t)
                @php
                    $urlDetail = route('kamar.tipe', ['kode' => Str::lower($t->code)] + $queryCari);
                    $fasilitas = $t->facilities;
                    $vip = Str::upper($t->code) === 'VIP';
                    $badge = $t->badge();
                    $tidakMuat = $t->cocok === \App\Models\RoomType::TIDAK_MUAT;
                    $redup = $t->habis || $tidakMuat;
                @endphp
                <article wire:key="tipe-{{ $t->id }}" data-tipe-kamar="{{ $t->code }}" data-cocok="{{ $t->cocok }}" @if ($tidakMuat) x-show="lainTerbuka" x-cloak @endif
                         @class(['group/kartu relative grid overflow-hidden rounded-lg border bg-cream-50 shadow-wt-xs transition-shadow duration-200 lg:grid-cols-[minmax(0,360px)_minmax(0,1fr)]',
                             'border-sand-300' => $vip, 'border-cream-300' => ! $vip, 'hover:shadow-wt-lg' => ! $redup])>
                    @if ($vip)
                        <span class="absolute inset-x-0 top-0 z-20 h-[3px] bg-sand-300" aria-hidden="true"></span>
                    @endif
                    <x-tamu.galeri :tipe="$t" :tautan="$urlDetail" :redup="$redup" zoom :class="Arr::toCssClasses(['aspect-[16/10] lg:aspect-auto lg:min-h-[260px]', 'opacity-60' => $redup])" />

                    <div class="flex min-w-0 flex-col p-5 sm:p-6 lg:flex-row lg:gap-7">
                        <div @class(['min-w-0 flex-1', 'opacity-60' => $redup])>
                            @if ($badge)
                                <span @class(['mb-2 inline-flex rounded-full px-2.5 py-1 font-display text-[10px] font-bold uppercase tracking-[.16em]',
                                    'bg-brown-800 text-sand-300' => $vip, 'bg-cream-200 text-clay-600' => ! $vip]) data-badge-tipe>{{ $badge }}</span>
                            @endif
                            <h3 class="m-0 font-display text-lg font-extrabold uppercase leading-snug tracking-[.04em]">
                                <a href="{{ $urlDetail }}" class="text-ink-900 hover:text-clay-600 hover:no-underline">{{ $t->name }}</a>
                            </h3>
                            @if ($t->description)
                                <p class="mb-0 mt-1.5 line-clamp-3 text-sm leading-relaxed text-ink-500">{{ $t->description }}</p>
                            @endif
                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px] text-ink-900">
                                <span class="inline-flex items-center gap-1.5"><x-tamu.ikon nama="orang" ukuran="16" class="text-clay-600" />Maks. {{ \App\Models\RoomType::teksBatas($t->kapasitas) }}</span>
                                @if ($t->barisKasur)
                                    <span class="inline-flex items-center gap-1.5"><x-tamu.ikon nama="kasur" ukuran="16" class="text-clay-600" />{{ count($t->barisKasur) }} pilihan kasur</span>
                                @endif
                            </div>
                            @if ($fasilitas->isNotEmpty())
                                <div class="mt-3.5 flex flex-wrap gap-1.5">
                                    @foreach ($fasilitas->take(4) as $f)
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-cream-300 bg-cream-100 px-2.5 py-1 text-xs text-ink-900">
                                            <x-tamu.ikon :fasilitas="$f->name" ukuran="14" class="text-clay-600" />{{ $f->name }}
                                        </span>
                                    @endforeach
                                    @if ($fasilitas->count() > 4)
                                        <span class="inline-flex items-center rounded-full px-1.5 py-1 text-xs text-ink-500">+{{ $fasilitas->count() - 4 }} lainnya</span>
                                    @endif
                                </div>
                            @endif
                            <x-tamu.keunggulan :tipe="$t" class="mt-3.5" />
                        </div>

                        {{-- Harga, jenis kasur (Tinggal 1-2 / Habis setelah tanggal dipilih), satu tombol utama, tautan detail --}}
                        <div class="mt-5 flex flex-col border-t border-cream-300 pt-4 lg:mt-0 lg:w-[236px] lg:flex-none lg:border-l lg:border-t-0 lg:pl-7 lg:pt-0">
                            <span class="wt-micro-label">Mulai dari</span>
                            <div class="mt-1 flex items-baseline gap-1.5">
                                <span @class(['font-display text-[24px] font-extrabold leading-tight tabular-nums', 'text-ink-500' => $redup, 'text-clay-600' => ! $redup])>{{ $rp($t->base_price) }}</span>
                                <span class="text-xs text-ink-500">/ malam</span>
                            </div>
                            @if ($t->diKeranjang > 0)
                                <span class="mt-1 text-xs font-semibold text-clay-600" data-di-keranjang>{{ $t->diKeranjang }} kamar di keranjang · {{ $rp((float) $t->base_price * $t->diKeranjang * max(1, $cari->malam())) }}</span>
                            @endif

                            <ul class="m-0 mt-3.5 flex list-none flex-col gap-2 border-t border-cream-300 p-0 pt-3.5 text-[13px]" data-kasur-kartu>
                                @foreach ($t->barisKasur as $k)
                                    @php
                                        $kasurTidakMuat = $k['cocok'] === \App\Models\RoomType::TIDAK_MUAT;
                                        $kasurRedup = $kasurTidakMuat || $k['sisa'] === 0;
                                    @endphp
                                    <li class="flex items-start gap-2" data-kasur="{{ $k['kode'] }}" data-cocok="{{ $k['cocok'] }}">
                                        <x-tamu.ikon nama="kasur" ukuran="14" @class(['mt-0.5 flex-none', 'text-clay-600' => ! $kasurRedup, 'text-ink-500 opacity-60' => $kasurRedup]) />
                                        <span class="min-w-0 flex-1">
                                            <span @class(['block truncate', 'text-ink-900' => ! $kasurRedup, 'text-ink-500 line-through opacity-60' => $kasurRedup])>{{ $k['nama'] }}</span>
                                            @if ($k['cocok'] === \App\Models\RoomType::MUAT_EXTRA && ! $kasurRedup)
                                                <span class="block whitespace-nowrap text-[11px] leading-tight text-ink-500" data-perlu-extra>perlu extra bed</span>
                                            @endif
                                        </span>
                                        @if ($kasurTidakMuat)
                                            <span class="flex-none text-xs text-ink-500 opacity-70" data-kasur-tidak-muat>Tidak muat</span>
                                        @elseif ($k['sisa'] === 0)
                                            <span class="flex-none text-xs text-ink-500 line-through opacity-60" data-sisa-kasur="0">Habis</span>
                                        @elseif ($k['sisa'] !== null && $k['sisa'] <= 2)
                                            {{-- Angka sisa hanya bila tinggal 1-2 --}}
                                            <span class="flex-none text-xs font-bold text-clay-600" data-sisa-kasur="{{ $k['sisa'] }}">Tinggal {{ $k['sisa'] }} kamar!</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            <div class="mt-4 flex flex-col lg:mt-auto lg:pt-4">
                                @if ($tidakMuat)
                                    <button type="button" disabled class="h-11 w-full cursor-not-allowed rounded border border-cream-300 bg-cream-200 px-3 text-xs font-semibold text-ink-500" data-tipe-tidak-muat>{{ $teksTidakMuat }}</button>
                                @elseif (! $cari->aktif())
                                    <button type="button" @click="ubahTanggal()" class="wt-btn-primary h-11 w-full px-4 text-xs tracking-[.08em]" data-pilih-tanggal>Pilih tanggal dulu</button>
                                    <span class="mt-1.5 text-center text-[11px] text-ink-500">Pilih tanggal untuk cek ketersediaan</span>
                                @elseif ($t->habis)
                                    <button type="button" disabled class="wt-btn-primary h-11 w-full cursor-not-allowed px-4 text-xs tracking-[.08em] opacity-50" data-tipe-penuh>Penuh</button>
                                @else
                                    {{-- Jenis kasur dipilih di halaman detail --}}
                                    <a href="{{ $urlDetail }}#pilih-kasur" class="wt-btn-primary h-11 w-full px-4 text-xs tracking-[.08em]" data-pilih-kamar>Pilih kamar</a>
                                @endif
                                <a href="{{ $urlDetail }}" class="mt-3 inline-flex items-center justify-center gap-1 text-[13px] font-semibold text-clay-600 hover:text-clay-600 hover:underline" data-lihat-detail>Lihat detail &amp; foto <span aria-hidden="true">→</span></a>
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-md border border-cream-300 bg-cream-50 px-6 py-12 text-center text-ink-500">Belum ada kamar yang dapat dipesan online. Silakan hubungi kami.</div>
            @endforelse

            @if ($jumlahTidakMuat > 0)
                <button type="button" @click="lainTerbuka = ! lainTerbuka" :aria-expanded="lainTerbuka"
                        class="mx-auto inline-flex h-11 items-center gap-2 rounded-full border border-cream-300 bg-cream-50 px-5 text-[13px] font-semibold text-brown-800 hover:bg-cream-200" data-tampilkan-tipe-lain>
                    <span x-text="lainTerbuka ? 'Sembunyikan tipe yang tidak muat' : @js('Tampilkan '.$jumlahTidakMuat.' tipe lain')">Tampilkan {{ $jumlahTidakMuat }} tipe lain</span>
                    <img src="{{ asset('ds/icons/chevron-up.svg') }}" alt="" width="12" height="12" class="opacity-60 transition-transform" :class="lainTerbuka ? '' : 'rotate-180'">
                </button>
            @endif
        </div>

        {{-- Informasi dan jalur instansi --}}
        <div class="mt-10 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="rounded-lg border border-cream-300 bg-cream-50 p-5 sm:p-6">
                <h2 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.1em] text-ink-900">Sebelum memesan</h2>
                <ul class="m-0 mt-3 flex list-none flex-col gap-2.5 p-0 text-sm text-ink-900">
                    <li class="flex gap-2.5"><x-tamu.ikon nama="jam" ukuran="18" class="mt-px text-clay-600" />Check-in mulai {{ $ketentuan['checkIn'] }} WITA, check-out paling lambat {{ $ketentuan['checkOut'] }} WITA.</li>
                    <li class="flex gap-2.5"><x-tamu.ikon nama="dompet" ukuran="18" class="mt-px text-clay-600" />Uang muka {{ $ketentuan['dpPersen'] }}% dibayar online dalam {{ $ketentuan['dpJam'] }} jam, sisanya saat check-out.</li>
                    <li class="flex gap-2.5"><x-tamu.ikon nama="kasur" ukuran="18" class="mt-px text-clay-600" />Jenis kasur yang Anda pilih dijamin saat menginap.</li>
                </ul>
            </div>
            <div class="relative overflow-hidden rounded-lg bg-brown-800 p-5 text-cream-50 sm:p-6">
                <div class="pointer-events-none absolute inset-2.5 border border-[rgba(255,253,246,.16)]" aria-hidden="true"></div>
                <div class="relative flex h-full flex-col items-start gap-3">
                    <x-tamu.ikon nama="gedung" ukuran="24" class="text-sand-300" />
                    <p class="m-0 text-[15px] leading-relaxed text-[rgba(242,235,220,.9)]">Memesan untuk instansi atau rombongan?</p>
                    <a href="{{ route('instansi') }}" class="mt-auto inline-flex h-11 items-center gap-2 rounded bg-clay-500 px-5 font-display text-xs font-semibold uppercase tracking-[.1em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">
                        Ajukan pemesanan instansi<x-tamu.ikon nama="kanan" ukuran="16" />
                    </a>
                </div>
            </div>
        </div>
    </div>

    @include('livewire.tamu.partials.bar-pilihan', ['r' => $ringkasan, 'urlKamar' => null])

    @include('livewire.tamu.skrip-cari-kamar')
</div>
