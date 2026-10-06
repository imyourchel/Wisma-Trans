@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $q = $cari->aktif() ? '?'.http_build_query($cari->query()) : '';
    $bisaPesan = $ini && in_array($ini['jenis'], ['tersedia', 'kurang', 'sebagian'], true);
    $tidakMuat = $ini && $ini['jenis'] === 'tidak-muat';
    $dp = $bisaPesan ? round($ini['total'] * $ketentuan['dpPersen'] / 100) : 0;
    $urlPesan = $bisaPesan ? route('kamar.pesan', Str::lower($tipe->code)).'?'.http_build_query($cari->query($ini['kamarDipesan'])) : null;
    $labelPesan = $bisaPesan && $ini['jenis'] !== 'tersedia' ? 'Pesan '.$ini['dipesan'].' kamar' : 'Pesan Sekarang';
    $adaFoto = $tipe->photoUrls() !== [];
    $hariBatal = (int) \App\Models\Setting::get('cancellation_min_days', 1);
    $persenKembali = (int) \App\Models\Setting::get('cancellation_refund_percent', 50);
    $dendaTelat = $rp(\App\Models\Setting::get('late_checkout_fee', 250000));
    $waTanya = 'https://wa.me/'.$wisma['whatsapp'].'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang '.$tipe->name.($cari->aktif() ? ' untuk '.$cari->rentang() : '').'.');
    $sorotan = [
        ['orang', 'Kapasitas', 'Maks. '.$tipe->max_capacity.' orang'],
        ['kasur', 'Jenis kasur', $tipe->kasur ? implode(' / ', $tipe->kasur) : '-'],
        ['gedung', 'Tipe ini', $tipe->rooms->count().' kamar'],
        ['jam', 'Check-in / out', $ketentuan['checkIn'].' / '.$ketentuan['checkOut']],
    ];
    $layanan = [
        ['makanan', 'Restoran', 'Pesan makanan dan minuman, diantar ke kamar atau ditagihkan ke kamar.', route('menu'), 'Lihat menu'],
        ['laundry', 'Laundry kiloan', 'Cucian dijemput dari kamar dan diantar kembali setelah selesai.', route('laundry'), 'Pesan laundry'],
        ['gedung', 'Sewa aula', 'Aula untuk rapat, pelatihan, atau acara keluarga.', route('aula'), 'Ajukan sewa'],
    ];
@endphp

<div class="animate-wt-masuk pb-28 lg:pb-0"
     x-data="cariKamar(@js(['hariIni' => today()->toDateString(), 'masuk' => $cari->masuk?->toDateString(), 'keluar' => $cari->keluar?->toDateString(), 'kamar' => $cari->kamar, 'maksTamu' => $tipe->max_capacity]))"
     @resize.window.debounce.150ms="lebar = window.innerWidth">

    <div class="wt-container pt-5 sm:pt-8">
        <nav class="flex items-center gap-1.5 text-[13px] text-ink-500" aria-label="Lokasi halaman">
            <a href="{{ route('kamar').$q }}" class="text-ink-500 hover:text-clay-600">Kamar</a>
            <x-tamu.ikon nama="kanan" ukuran="14" />
            <span class="truncate text-ink-900" aria-current="page">{{ $tipe->name }}</span>
        </nav>
    </div>

    {{-- Judul --}}
    <header class="wt-container mt-4 sm:mt-5">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="font-display text-[11px] font-semibold uppercase tracking-[.24em] text-clay-500">Tipe kamar</span>
            <span class="rounded-sm bg-cream-200 px-1.5 py-0.5 font-display text-[10px] font-bold tracking-[.14em] text-brown-800">{{ $tipe->code }}</span>
        </div>
        <h1 class="mb-0 mt-2 font-display text-[28px] font-extrabold uppercase leading-tight tracking-[.04em] sm:text-[38px]">{{ $tipe->name }}</h1>
        @if ($tipe->description)
            <p class="mb-0 mt-2 max-w-[640px] whitespace-pre-line text-[15px] leading-relaxed text-ink-500">{{ $tipe->description }}</p>
        @endif
    </header>

    {{-- Foto. Bila tipe ini belum punya foto, tampil foto bangunan dengan keterangan yang jujur. --}}
    <div class="wt-container mt-5 sm:mt-6">
        @if ($adaFoto)
            <x-tamu.galeri :tipe="$tipe" layar-penuh class="-mx-4 aspect-[4/3] sm:mx-0 sm:aspect-[16/9] sm:rounded-lg lg:aspect-[21/9]" />
        @else
            <div class="-mx-4 grid gap-2 sm:mx-0 sm:h-[400px] sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] sm:grid-rows-2 lg:h-[460px]">
                <figure class="relative m-0 aspect-[4/3] overflow-hidden bg-brown-800 sm:row-span-2 sm:aspect-auto sm:rounded-l-lg">
                    <img src="{{ asset('ds/img/bangunan_depan.jpg') }}" alt="Bangunan Wisma Trans tampak depan" class="h-full w-full object-cover">
                    <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,transparent_45%,rgba(40,27,18,.78))]"></div>
                    <figcaption class="absolute inset-x-0 bottom-0 flex flex-wrap items-end justify-between gap-3 p-4 sm:p-6">
                        <span>
                            <span class="block font-display text-[11px] font-semibold uppercase tracking-[.24em] text-sand-300">Wisma Trans Malili</span>
                            <span class="mt-1 block font-display text-lg font-extrabold uppercase tracking-[.04em] text-cream-50 sm:text-[22px]">{{ $tipe->name }}</span>
                        </span>
                        <span class="rounded-full bg-[rgba(40,27,18,.6)] px-3 py-1 text-[11px] font-semibold text-cream-50 backdrop-blur-sm">Foto kamar segera kami tambahkan</span>
                    </figcaption>
                    <div class="pointer-events-none absolute inset-2.5 border border-[rgba(255,253,246,.28)]" aria-hidden="true"></div>
                </figure>
                <figure class="relative m-0 hidden overflow-hidden bg-brown-800 sm:block sm:rounded-tr-lg">
                    <img src="{{ asset('ds/img/bangunan_samping.jpg') }}" alt="Bangunan Wisma Trans tampak samping" loading="lazy" class="h-full w-full object-cover">
                </figure>
                <div class="relative hidden flex-col justify-between overflow-hidden bg-brown-900 p-5 text-cream-50 sm:flex sm:rounded-br-lg lg:p-6">
                    <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-[radial-gradient(circle,rgba(228,185,143,.28),transparent_70%)]" aria-hidden="true"></div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-full border border-[rgba(228,185,143,.45)] text-sand-300"><x-tamu.ikon nama="kasur" ukuran="20" /></span>
                    <div>
                        <div class="font-display text-[26px] font-extrabold leading-none tabular-nums lg:text-[30px]">{{ $rp($tipe->base_price) }}</div>
                        <div class="mt-1.5 text-xs text-[rgba(242,235,220,.75)]">per kamar per malam · {{ $tipe->rooms->count() }} kamar tipe ini</div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="wt-container mt-8 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_380px] lg:gap-12">
        {{-- Isi --}}
        <div class="min-w-0">
            {{-- Sorotan --}}
            <dl class="m-0 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($sorotan as [$ikon, $label, $nilai])
                    <div class="rounded-lg border border-cream-300 bg-cream-50 p-4">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-cream-200 text-clay-600"><x-tamu.ikon :nama="$ikon" ukuran="17" /></span>
                        <dt class="mt-3 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">{{ $label }}</dt>
                        <dd class="m-0 mt-0.5 text-[15px] font-semibold text-ink-900">{{ $nilai }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($tipe->facilities->isNotEmpty())
                <section class="mt-10" aria-labelledby="judul-fasilitas">
                    <h2 id="judul-fasilitas" class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.08em]">Fasilitas kamar</h2>
                    <ul class="m-0 mt-4 grid list-none grid-cols-2 gap-2.5 p-0 sm:grid-cols-3">
                        @foreach ($tipe->facilities as $f)
                            <li class="flex items-center gap-3 rounded-md bg-cream-150 px-3.5 py-3 text-sm font-medium text-ink-900">
                                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-cream-50 text-clay-600 shadow-wt-xs"><x-tamu.ikon :fasilitas="$f->name" ukuran="16" /></span>
                                {{ $f->name }}
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Layanan selama menginap --}}
            <section class="mt-10" aria-labelledby="judul-layanan">
                <h2 id="judul-layanan" class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.08em]">Selama menginap</h2>
                <p class="mb-0 mt-1 text-[13px] text-ink-500">Layanan yang bisa dipesan tamu Wisma Trans.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach ($layanan as [$ikon, $judul, $teks, $url, $aksi])
                        <a href="{{ $url }}" class="group flex flex-col rounded-lg border border-cream-300 bg-cream-50 p-4 text-ink-900 transition hover:-translate-y-0.5 hover:border-clay-500 hover:text-ink-900 hover:no-underline hover:shadow-wt-md">
                            <span class="flex h-10 w-10 items-center justify-center rounded-md bg-brown-900 text-sand-300"><x-tamu.ikon :nama="$ikon" ukuran="19" /></span>
                            <span class="mt-3 font-display text-[13px] font-bold uppercase tracking-[.06em]">{{ $judul }}</span>
                            <span class="mt-1 flex-1 text-[13px] leading-relaxed text-ink-500">{{ $teks }}</span>
                            <span class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-clay-600">{{ $aksi }}<x-tamu.ikon nama="kanan" ukuran="14" class="transition group-hover:translate-x-0.5" /></span>
                        </a>
                    @endforeach
                </div>
            </section>

            {{-- Ketentuan: angka dari pengaturan --}}
            <section class="mt-10" aria-labelledby="judul-ketentuan">
                <h2 id="judul-ketentuan" class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.08em]">Ketentuan menginap</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['jam', 'Check-in dan check-out', 'Check-in mulai '.$ketentuan['checkIn'].' WITA, check-out paling lambat '.$ketentuan['checkOut'].' WITA. Terlambat keluar dikenai biaya '.$dendaTelat.'.'],
                        ['dompet', 'Uang muka '.$ketentuan['dpPersen'].'%', 'Dibayar online (QRIS atau virtual account) paling lambat '.$ketentuan['dpJam'].' jam setelah memesan. Sisanya dilunasi saat check-in.'],
                        ['kalender', 'Ubah tanggal dan pembatalan', 'Bisa lebih dari '.$hariBatal.' hari sebelum kedatangan; uang muka kembali '.$persenKembali.'% bila batal. Setelah itu, atau bila tidak datang, uang muka hangus.'],
                        ['kasur', 'Nomor kamar', 'Ditentukan resepsionis saat check-in sesuai tipe yang Anda pesan.'],
                    ] as [$ikon, $judul, $teks])
                        <div class="flex gap-3.5 rounded-lg border border-cream-300 bg-cream-50 p-4">
                            <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-cream-200 text-clay-600"><x-tamu.ikon :nama="$ikon" ukuran="17" /></span>
                            <div class="min-w-0">
                                <h3 class="m-0 text-sm font-semibold">{{ $judul }}</h3>
                                <p class="mb-0 mt-1 text-[13px] leading-relaxed text-ink-500">{{ $teks }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Lokasi --}}
            <section class="mt-10" aria-labelledby="judul-lokasi">
                <h2 id="judul-lokasi" class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.08em]">Lokasi</h2>
                <div class="mt-4 grid overflow-hidden rounded-lg border border-cream-300 bg-cream-50 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                    <iframe title="Lokasi Wisma Trans di peta" src="https://www.google.com/maps?q={{ rawurlencode('Wisma Trans, '.implode(', ', $wisma['alamat'])) }}&output=embed"
                            class="block h-[220px] w-full border-0 bg-cream-200 sm:h-full sm:min-h-[220px]" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    <div class="flex flex-col gap-3 p-5">
                        <address class="text-sm not-italic leading-relaxed text-ink-900">
                            <span class="font-semibold">Wisma Trans</span><br>
                            @foreach ($wisma['alamat'] as $baris){{ $baris }}<br>@endforeach
                        </address>
                        <div class="mt-auto flex flex-wrap gap-2">
                            <a href="{{ $wisma['maps'] }}" target="_blank" rel="noopener" class="wt-btn-outline h-10 px-4 text-xs">Petunjuk arah</a>
                            <a href="{{ $waTanya }}" target="_blank" rel="noopener" class="wt-btn-outline h-10 gap-2 px-4 text-xs"><img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="14" height="14" class="brightness-0 opacity-70">Tanya resepsionis</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        {{-- Kotak pemesanan: di layar lebar menempel di kolom kanan --}}
        <aside id="pesan" class="scroll-mt-24 lg:sticky lg:top-[96px]">
            <div class="rounded-lg border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="relative overflow-hidden rounded-t-lg bg-brown-900 px-5 py-4 text-cream-50 sm:px-6">
                    <div class="pointer-events-none absolute -right-8 -top-12 h-36 w-36 rounded-full bg-[radial-gradient(circle,rgba(228,185,143,.25),transparent_70%)]" aria-hidden="true"></div>
                    <div class="font-display text-[11px] font-semibold uppercase tracking-[.2em] text-sand-300">Pesan kamar</div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-display text-[26px] font-extrabold tabular-nums">{{ $rp($tipe->base_price) }}</span>
                        <span class="text-xs text-[rgba(242,235,220,.75)]">/ kamar / malam</span>
                    </div>
                </div>

                <div class="p-5 sm:p-6">
                <form @submit.prevent="cek()" novalidate class="rounded-md border border-cream-300">
                    <x-tamu.rentang-tanggal panel="left-0 lg:left-auto lg:right-0" ringkas />
                    <div class="relative border-t border-cream-300" @click.outside="buka = false" @click="kalender = false">
                        <button type="button" @click="buka = !buka" :aria-expanded="buka"
                                class="flex w-full items-center justify-between gap-3 border-0 bg-transparent px-4 py-3 text-left">
                            <span class="min-w-0">
                                <span class="wt-micro-label block">Kamar dan tamu</span>
                                <span class="mt-1 block truncate text-[15px] text-ink-900" x-text="ringkas">{{ $cari->ringkasTamu() }}</span>
                            </span>
                            <img src="{{ asset('ds/icons/chevron-up.svg') }}" alt="" width="14" height="14" class="flex-none opacity-60 transition-transform" :class="buka ? '' : 'rotate-180'">
                        </button>
                        <x-tamu.pilih-tamu panel="inset-x-0 top-[calc(100%+6px)] w-auto" />
                    </div>
                </form>

                @if ($cari->galat)
                    <p class="mb-0 mt-3 text-[13px] text-status-dirty" x-show="!berubah" role="alert">{{ $cari->galat }}</p>
                @endif
                <p x-show="gagal" x-cloak class="mb-0 mt-3 text-[13px] text-status-dirty" role="alert">Tidak bisa memuat data kamar. Periksa koneksi lalu coba lagi.</p>

                {{-- Sedang mengecek --}}
                <div wire:loading.block wire:target="cek" class="mt-5 hidden animate-pulse" aria-live="polite">
                    <div class="h-6 w-28 rounded-full bg-cream-200"></div>
                    <div class="mt-4 h-4 w-full rounded bg-cream-200"></div>
                    <div class="mt-2.5 h-4 w-4/5 rounded bg-cream-200"></div>
                    <div class="mt-5 h-12 w-full rounded bg-cream-200"></div>
                    <span class="sr-only">Mengecek ketersediaan…</span>
                </div>

                <div wire:loading.remove wire:target="cek">
                    {{-- Belum dicek, atau tanggal di kotak sudah diubah --}}
                    {{-- Kunci berbeda per keadaan: elemen diganti saat dirender ulang, sehingga x-show dibaca ulang Alpine --}}
                    <div @if ($ini) x-show="berubah || gagal" x-cloak wire:key="cek-ulang" @else wire:key="cek-awal" @endif class="mt-5">
                        <button type="button" @click="cek()" class="wt-btn-primary h-12 w-full text-xs tracking-[.1em]" x-text="gagal ? 'Coba lagi' : 'Cek ketersediaan'">Cek ketersediaan</button>
                        <p class="mb-0 mt-2.5 text-center text-xs text-ink-500">Pilih tanggal dan jumlah tamu untuk melihat total harga.</p>
                    </div>

                    @if ($ini)
                        <div x-show="!berubah && !gagal" x-cloak class="mt-5">
                            @if ($ini['jenis'] === 'penuh' || $tidakMuat)
                                @if ($tidakMuat)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-cream-200 px-2.5 py-1 text-xs font-semibold text-ink-900">
                                        <x-tamu.ikon nama="orang" ukuran="14" class="text-clay-600" />Maks. {{ $tipe->max_capacity }} orang per kamar
                                    </span>
                                    <p class="mb-0 mt-3 text-[13px] leading-relaxed text-ink-500">{{ $tipe->name }} tidak muat untuk {{ $cari->tamuTerbanyak() }} orang dalam satu kamar. Tambah kamar untuk membagi tamu.</p>
                                    <button type="button" @click.stop="buka = true" class="wt-btn-outline mt-3 h-10 w-full text-xs tracking-[.08em]">Ubah susunan tamu</button>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(212,81,74,.1)] px-2.5 py-1 text-xs font-semibold text-status-dirty">
                                        <span class="h-1.5 w-1.5 rounded-full bg-status-dirty"></span>Penuh untuk tanggal ini
                                    </span>
                                @endif
                                @if ($saranLain->isNotEmpty())
                                    <p class="mb-2 mt-4 text-[13px] text-ink-500">{{ $tidakMuat ? 'Tipe yang muat dan tersedia di tanggal yang sama:' : 'Masih tersedia di tanggal yang sama:' }}</p>
                                    <ul class="m-0 flex list-none flex-col gap-2 p-0">
                                        @foreach ($saranLain as $t)
                                            <li>
                                                <a href="{{ route('kamar.tipe', Str::lower($t->code)).$q }}"
                                                   class="flex items-center justify-between gap-3 rounded-md border border-cream-300 px-3.5 py-2.5 text-sm text-ink-900 hover:border-clay-500 hover:bg-row-hover hover:text-ink-900 hover:no-underline">
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-semibold">{{ $t->name }}</span>
                                                        <span class="text-xs tabular-nums text-ink-500">{{ $rp($t->base_price) }} / malam</span>
                                                    </span>
                                                    <x-tamu.ikon nama="kanan" ukuran="16" class="text-clay-600" />
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @elseif (! $tidakMuat)
                                    <p class="mb-0 mt-3 text-[13px] text-ink-500">Semua tipe kamar penuh untuk tanggal ini. Coba tanggal lain atau hubungi kami.</p>
                                @endif
                            @else
                                @if ($ini['jenis'] === 'sebagian')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(232,172,69,.18)] px-2.5 py-1 text-xs font-semibold text-[#8A5A0B]">
                                        <span class="h-1.5 w-1.5 flex-none rounded-full bg-status-maintenance"></span>Muat untuk {{ $ini['muat'] }} dari {{ $cari->jumlah }} kamar
                                    </span>
                                    <p class="mb-0 mt-2.5 text-xs leading-relaxed text-ink-500">Kamar lain berisi lebih dari {{ $tipe->max_capacity }} orang. Pesan kamar itu dengan tipe yang lebih besar, atau ubah susunan tamu.</p>
                                @elseif ($ini['jenis'] === 'kurang')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(232,172,69,.18)] px-2.5 py-1 text-xs font-semibold text-[#8A5A0B]">
                                        <span class="h-1.5 w-1.5 flex-none rounded-full bg-status-maintenance"></span>Hanya tersedia {{ $ini['sisa'] }} kamar untuk tanggal ini
                                    </span>
                                @else
                                    <div class="flex flex-wrap gap-1.5">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(92,158,103,.14)] px-2.5 py-1 text-xs font-semibold text-status-available">
                                            <span class="h-1.5 w-1.5 rounded-full bg-status-available"></span>Tersedia
                                        </span>
                                        @if ($ini['sisa'] <= 2)
                                            <span class="inline-flex items-center rounded-full bg-[rgba(232,172,69,.18)] px-2.5 py-1 text-xs font-semibold text-[#8A5A0B]">Tersisa {{ $ini['sisa'] }} kamar</span>
                                        @endif
                                    </div>
                                @endif

                                <dl class="m-0 mt-4 flex flex-col gap-2 text-sm">
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-ink-500">{{ $tipe->name }} × {{ $ini['dipesan'] }} kamar × {{ $cari->malam() }} malam</dt>
                                        <dd class="m-0 flex-none tabular-nums">{{ $rp($ini['total']) }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3 border-t border-cream-300 pt-2 font-semibold">
                                        <dt>Total</dt>
                                        <dd class="m-0 tabular-nums">{{ $rp($ini['total']) }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt>Uang muka {{ $ketentuan['dpPersen'] }}%</dt>
                                        <dd class="m-0 font-display text-base font-extrabold tabular-nums text-clay-600">{{ $rp($dp) }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-ink-500">Sisa dibayar saat check-in</dt>
                                        <dd class="m-0 tabular-nums">{{ $rp($ini['total'] - $dp) }}</dd>
                                    </div>
                                </dl>

                                <p class="mb-0 mt-4 flex gap-2 rounded-md bg-cream-200 px-3 py-2.5 text-xs leading-relaxed text-ink-900">
                                    <x-tamu.ikon nama="jam" ukuran="15" class="mt-px text-clay-600" />
                                    Uang muka dibayar online paling lambat {{ $ketentuan['dpJam'] }} jam setelah memesan.
                                </p>
                                <a href="{{ $urlPesan }}" class="wt-btn-primary mt-4 h-12 w-full text-xs tracking-[.1em]">{{ $labelPesan }}</a>
                                <p class="mb-0 mt-2.5 text-center text-xs text-ink-500">Belum dikenakan biaya. Pembayaran di langkah berikutnya.</p>
                            @endif
                        </div>
                    @endif
                </div>
                </div>

                <ul class="m-0 flex list-none flex-col gap-2 rounded-b-lg border-t border-cream-300 bg-cream-100 px-5 py-4 text-xs text-ink-900 sm:px-6">
                    @foreach (['Cukup bayar uang muka '.$ketentuan['dpPersen'].'% secara online', 'Batal lebih dari '.$hariBatal.' hari sebelumnya, uang muka kembali '.$persenKembali.'%', 'Kode pesanan dan nota dikirim lewat WhatsApp'] as $poin)
                        <li class="flex items-start gap-2"><x-tamu.ikon nama="cek" ukuran="15" class="mt-px text-status-available" />{{ $poin }}</li>
                    @endforeach
                </ul>
            </div>

            <a href="{{ $waTanya }}" target="_blank" rel="noopener"
               class="mt-3 flex items-center gap-3 rounded-lg border border-cream-300 bg-cream-50 px-4 py-3 text-ink-900 hover:border-clay-500 hover:text-ink-900 hover:no-underline">
                <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-status-available"><img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="17" height="17" class="brightness-0 invert"></span>
                <span class="min-w-0 flex-1 leading-tight">
                    <span class="block text-sm font-semibold">Ada pertanyaan?</span>
                    <span class="text-xs text-ink-500">Chat resepsionis, siap 24 jam</span>
                </span>
                <x-tamu.ikon nama="kanan" ukuran="16" class="text-clay-600" />
            </a>
        </aside>
    </div>

    {{-- Tipe kamar lain --}}
    @if ($lain->isNotEmpty())
        <section class="wt-container mt-12 border-t border-cream-300 pb-16 pt-10">
            <h2 class="m-0 font-display text-lg font-extrabold uppercase tracking-[.04em]">Tipe kamar lain</h2>
            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                @foreach ($lain as $t)
                    @php $st = $status[$t->id] ?? null; $url = route('kamar.tipe', Str::lower($t->code)).$q; @endphp
                    <a href="{{ $url }}" wire:key="lain-{{ $t->id }}"
                       class="group flex flex-col overflow-hidden rounded-lg border border-cream-300 bg-cream-50 text-ink-900 shadow-wt-xs transition hover:-translate-y-0.5 hover:text-ink-900 hover:no-underline hover:shadow-wt-md">
                        <x-tamu.galeri :tipe="$t" :redup="$st && in_array($st['jenis'], ['penuh', 'tidak-muat'], true)" class="aspect-[16/10]" />
                        <div class="flex flex-1 flex-col gap-1.5 p-4">
                            <span class="font-display text-[15px] font-bold uppercase tracking-[.04em]">{{ $t->name }}</span>
                            <span class="text-xs text-ink-500">Maks. {{ $t->max_capacity }} orang{{ $t->kasur ? ' · '.implode(' atau ', $t->kasur) : '' }}</span>
                            <div class="mt-auto flex items-center justify-between gap-2 pt-2">
                                <span class="text-sm"><span class="font-display font-bold tabular-nums text-clay-600">{{ $rp($t->base_price) }}</span><span class="text-xs text-ink-500"> / malam</span></span>
                                @if ($st)
                                    <span @class(['whitespace-nowrap text-xs font-semibold',
                                        'text-status-dirty' => $st['jenis'] === 'penuh', 'text-status-available' => $st['jenis'] === 'tersedia', 'text-[#8A5A0B]' => in_array($st['jenis'], ['kurang', 'sebagian'], true), 'text-ink-500' => $st['jenis'] === 'tidak-muat'])>
                                        {{ ['penuh' => 'Penuh', 'tersedia' => 'Tersedia', 'kurang' => 'Sisa '.$st['sisa'].' kamar', 'sebagian' => 'Muat '.$st['muat'].' kamar', 'tidak-muat' => 'Tidak muat'][$st['jenis']] }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Bilah pemesanan menempel di bawah layar ponsel --}}
    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-cream-300 bg-cream-50 px-4 pb-[max(12px,env(safe-area-inset-bottom))] pt-3 shadow-[0_-10px_28px_-14px_rgba(62,42,28,.35)] lg:hidden">
        <div class="flex items-center gap-3">
            <div class="min-w-0 flex-1 leading-tight">
                @if ($bisaPesan)
                    <div x-show="!berubah && !gagal">
                        <div class="font-display text-lg font-extrabold tabular-nums">{{ $rp($ini['total']) }}</div>
                        <div class="text-xs text-ink-500">{{ $ini['dipesan'] }} kamar · {{ $cari->malam() }} malam · DP {{ $rp($dp) }}</div>
                    </div>
                @elseif ($tidakMuat)
                    <div x-show="!berubah && !gagal" class="text-sm font-semibold text-ink-900">Maks. {{ $tipe->max_capacity }} orang per kamar</div>
                @elseif ($ini)
                    <div x-show="!berubah && !gagal" class="text-sm font-semibold text-status-dirty">Penuh untuk tanggal ini</div>
                @endif
                <div @if ($bisaPesan) x-show="berubah || gagal" x-cloak wire:key="bilah-ulang" @else wire:key="bilah-awal" @endif>
                    @unless ($ini && ! $bisaPesan)
                        <div class="text-[11px] uppercase tracking-[.12em] text-ink-500">Mulai dari</div>
                        <div class="text-sm"><span class="font-display text-base font-extrabold tabular-nums text-clay-600">{{ $rp($tipe->base_price) }}</span> <span class="text-xs text-ink-500">/ malam</span></div>
                    @endunless
                </div>
            </div>
            @if ($bisaPesan)
                <a x-show="!berubah && !gagal" href="{{ $urlPesan }}" class="wt-btn-primary h-12 flex-none px-6 text-xs tracking-[.1em]">{{ $labelPesan === 'Pesan Sekarang' ? 'Pesan' : $labelPesan }}</a>
            @endif
            <button type="button" @if ($bisaPesan) x-show="berubah || gagal" x-cloak wire:key="tombol-ulang" @else wire:key="tombol-awal" @endif
                    @click.stop="document.getElementById('pesan').scrollIntoView({ behavior: 'smooth', block: 'start' }); {{ $tidakMuat ? 'buka = true' : '' }}"
                    class="h-12 flex-none rounded border-0 bg-brown-800 px-5 font-display text-xs font-semibold uppercase tracking-[.1em] text-cream-50">
                {{ $tidakMuat ? 'Ubah tamu' : ($ini && $ini['jenis'] === 'penuh' ? 'Ubah tanggal' : 'Cek tanggal') }}
            </button>
        </div>
    </div>

    @include('livewire.tamu.skrip-cari-kamar')
</div>
