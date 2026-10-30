@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $q = $queryCari ? '?'.http_build_query($queryCari) : '';
    $keTanggal = "document.getElementById('pesan').scrollIntoView({ behavior: 'smooth', block: 'start' })";
    $kePilihKasur = "document.getElementById('pilih-kasur').scrollIntoView({ behavior: 'smooth', block: 'start' })";
    $adaPilihan = $ringkasan['jumlah'] > 0;
    $kodeTipe = Str::upper($tipe->code);
    $penuh = $sisaTipe === 0;
    $adaFoto = $tipe->photoUrls() !== [];
    $hariBatal = (int) \App\Models\Setting::get('cancellation_min_days', 1);
    $persenKembali = (int) \App\Models\Setting::get('cancellation_refund_percent', 50);
    $dendaTelat = $rp(\App\Models\Setting::get('late_checkout_fee', 250000));
    $waTanya = 'https://wa.me/'.$wisma['whatsapp'].'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang '.$tipe->name.($cari->aktif() ? ' untuk '.$cari->rentang() : '').'.');
    $sorotan = [
        ['orang', 'Kapasitas', 'Maks. '.\App\Models\RoomType::teksBatas($tipe->kapasitas)],
        ['kasur', 'Pilihan kasur', $tipe->kasur ? count($tipe->kasur).' jenis' : '-'],
        ['gedung', 'Tipe ini', $tipe->rooms->count().' kamar'],
        ['jam', 'Check-in / out', $ketentuan['checkIn'].' / '.$ketentuan['checkOut']],
    ];
    $layanan = [
        ['makanan', 'Restoran', 'Pesan makanan dan minuman, diantar ke kamar atau ditagihkan ke kamar.', route('menu'), 'Lihat menu'],
        ['laundry', 'Laundry kiloan', 'Cucian dijemput dari kamar dan diantar kembali setelah selesai.', route('laundry.pesan'), 'Pesan laundry'],
        ['gedung', 'Sewa aula', 'Aula untuk rapat, pelatihan, atau acara keluarga.', route('aula.ajukan'), 'Ajukan sewa'],
    ];
@endphp

<div @class(['animate-wt-masuk', 'pb-36 md:pb-28' => $adaPilihan, 'pb-28 lg:pb-0' => ! $adaPilihan])
     x-data="cariKamar(@js(['hariIni' => today()->toDateString(), 'masuk' => $cari->masuk?->toDateString(), 'keluar' => $cari->keluar?->toDateString(), 'kamar' => $susunanTamu, 'batasTamu' => $batasTamu]))"
     @resize.window.debounce.150ms="lebar = window.innerWidth">

    <div class="wt-container pt-5 sm:pt-8">
        <nav class="flex items-center gap-1.5 text-[13px] text-ink-500" aria-label="Lokasi halaman">
            <a href="{{ route('kamar', $queryCari) }}" class="text-ink-500 hover:text-clay-600">Kamar</a>
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
        <x-tamu.keunggulan :tipe="$tipe" class="mt-3" />
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

            {{-- Pilih jenis kasur: tombol Pilih menambah satu kamar, lalu stepper − n + (sama dengan bar bawah, ?pilih=), tanpa pindah halaman --}}
            @if ($kartuKasur)
                <section id="pilih-kasur" class="mt-10 scroll-mt-24" aria-labelledby="judul-kasur" data-pilih-kasur>
                    <h2 id="judul-kasur" class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.08em]">Pilih jenis kasur</h2>
                    <p class="mb-0 mt-1 text-[13px] text-ink-500">Jenis kasur yang Anda pilih dijamin saat menginap. Tekan Pilih lalu atur jumlah kamarnya; bisa digabung dengan tipe lain.</p>
                    <div class="mt-4 flex flex-col gap-3">
                        @foreach ($kartuKasur as $kk)
                            @php
                                $habis = $kk['sisa'] === 0;
                                $dipilih = $kk['jumlah'] > 0;
                                $kasurTidakMuat = $kk['cocok'] === \App\Models\RoomType::TIDAK_MUAT;
                            @endphp
                            <article wire:key="kasur-{{ $kk['kode'] }}" data-kartu-kasur="{{ $kk['kode'] }}"
                                     @class(['overflow-hidden rounded-lg border transition',
                                        'border-clay-500 bg-[rgba(181,113,74,.05)] shadow-wt-md ring-1 ring-clay-500' => $dipilih,
                                        'border-cream-300 bg-cream-50' => ! $dipilih,
                                        'opacity-60' => $habis || $kasurTidakMuat])>
                                <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:p-5">
                                    @if ($kk['foto'])
                                        <img src="{{ $kk['foto'] }}" alt="Contoh {{ $kk['nama'] }}" loading="lazy" data-foto-kasur
                                             @class(['aspect-[3/2] w-full flex-none rounded-md object-cover sm:w-[150px]', 'grayscale' => $habis])>
                                    @else
                                        <span @class(['flex h-12 w-12 flex-none items-center justify-center rounded-lg', 'bg-clay-500 text-cream-50' => $dipilih, 'bg-cream-200 text-clay-600' => ! $dipilih]) aria-hidden="true">
                                            <x-tamu.ikon nama="kasur" ukuran="22" />
                                        </span>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <h3 class="m-0 text-base font-semibold text-ink-900">{{ $kk['nama'] }}</h3>
                                        <p class="mb-0 mt-0.5 text-[13px] text-ink-500">{{ $kk['info'] }}</p>
                                        <ul class="m-0 mt-2.5 flex list-none flex-wrap gap-x-4 gap-y-1.5 p-0 text-xs text-ink-900">
                                            <li class="flex items-center gap-1.5"><x-tamu.ikon nama="orang" ukuran="14" class="text-clay-600" /><span data-kapasitas-kasur>Maks. {{ \App\Models\RoomType::teksBatas($kk['kapasitas']) }}</span></li>
                                            <li class="flex items-center gap-1.5"><x-tamu.ikon nama="makanan" ukuran="14" class="text-clay-600" />Termasuk sarapan {{ $tipe->sarapan() }} orang</li>
                                            @if ($kk['cocok'] === \App\Models\RoomType::MUAT_EXTRA && ! $kasurTidakMuat)
                                                <li class="flex items-center gap-1.5 text-ink-500" data-extra-tanpa-sarapan>Extra bed belum termasuk sarapan</li>
                                            @endif
                                        </ul>
                                    </div>
                                    <div class="flex flex-wrap items-end justify-between gap-3 border-t border-dashed border-cream-300 pt-3 sm:flex-col sm:flex-nowrap sm:items-end sm:border-0 sm:pt-0 sm:text-right">
                                        <div>
                                            @if ($kasurTidakMuat)
                                                <span class="mb-1 inline-block text-xs font-semibold text-ink-500" data-kasur-tidak-muat>Tidak muat untuk {{ $teksTamuTerkecil }}</span>
                                            @elseif ($habis)
                                                <span class="mb-1 inline-block text-xs font-semibold text-status-dirty" data-sisa-kasur>Habis untuk tanggal ini</span>
                                            @elseif ($kk['sisa'] !== null)
                                                <span class="mb-1 inline-block text-xs font-semibold text-status-available" data-sisa-kasur>Tersedia{{ $kk['cocok'] === \App\Models\RoomType::MUAT_EXTRA ? ' · perlu extra bed' : '' }}</span>
                                            @endif
                                            <div><span class="font-display text-lg font-extrabold tabular-nums text-clay-600">{{ $rp($tipe->base_price) }}</span><span class="text-xs text-ink-500"> / malam</span></div>
                                        </div>
                                        <div class="flex items-center gap-2.5">
                                            @if ($kasurTidakMuat && ! $dipilih)
                                                <button type="button" disabled class="wt-btn-outline h-10 cursor-not-allowed px-4 text-xs tracking-[.06em] opacity-60">Tidak muat</button>
                                            @elseif ($kk['sisa'] === null)
                                                <button type="button" @click="{{ $keTanggal }}; setTimeout(() => bukaKalender('masuk'), 350)" class="wt-btn-outline h-10 px-4 text-xs tracking-[.06em]">Pilih tanggal dulu</button>
                                            @elseif ($dipilih)
                                                {{-- Stepper: maks = sisa jenis kasur ini (sisa tipe dibagi bersama jenis kasur lain) --}}
                                                <div class="flex flex-none overflow-hidden rounded-md border border-cream-400" role="group" aria-label="Jumlah kamar {{ $kk['nama'] }}" data-stepper>
                                                    <button type="button" wire:click="kurangiKasur('{{ $kodeTipe }}', '{{ $kk['kode'] }}')" wire:loading.attr="disabled" wire:target="kurangiKasur,pilihKasur" aria-label="Kurangi {{ $kk['nama'] }}"
                                                            class="flex h-10 w-10 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center border-0 bg-cream-50 text-lg text-brown-800 hover:bg-cream-200 disabled:opacity-35">−</button>
                                                    <span class="flex w-10 items-center justify-center border-x border-cream-400 bg-brown-800 font-display text-sm font-bold tabular-nums text-cream-50" aria-live="polite" data-jumlah-kasur>{{ $kk['jumlah'] }}</span>
                                                    <button type="button" wire:click="pilihKasur('{{ $kodeTipe }}', '{{ $kk['kode'] }}')" wire:loading.attr="disabled" wire:target="kurangiKasur,pilihKasur" @disabled($kk['jumlah'] >= $kk['maks'])
                                                            @if ($kk['jumlah'] >= $kk['maks']) title="Tinggal {{ $kk['maks'] }} kamar" @endif aria-label="Tambah {{ $kk['nama'] }}"
                                                            class="flex h-10 w-10 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center border-0 bg-clay-500 text-lg text-cream-50 hover:bg-clay-600 disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-clay-500">+</button>
                                                </div>
                                            @elseif ($habis || $kk['maks'] === 0)
                                                <button type="button" disabled class="wt-btn-outline h-10 cursor-not-allowed px-4 text-xs tracking-[.06em] opacity-60">Habis</button>
                                            @else
                                                <button type="button" wire:click="pilihKasur('{{ $kodeTipe }}', '{{ $kk['kode'] }}')" wire:loading.attr="disabled" wire:target="pilihKasur"
                                                        class="wt-btn-primary h-10 px-5 text-xs tracking-[.08em]" data-tombol-pilih>Pilih</button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
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
                        ['kasur', 'Jenis kasur', 'Jenis kasur yang Anda pilih dijamin saat menginap. Nomor kamar dikirim bersama konfirmasi.'],
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
                                <span class="wt-micro-label block">Tamu</span>
                                <span class="mt-1 block truncate text-[15px] text-ink-900" x-text="ringkasTamu">{{ \App\Support\PilihanKamar::teksTamu($jumlahTamu['dewasa'], $jumlahTamu['anak']) }}</span>
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
                    <div class="mt-4 h-12 w-full rounded bg-cream-200"></div>
                    <span class="sr-only">Mengecek ketersediaan…</span>
                </div>

                <div wire:loading.remove wire:target="cek">
                    {{-- Kunci berbeda per keadaan: elemen diganti saat dirender ulang, sehingga x-show dibaca ulang Alpine --}}
                    <div @if ($sisaTipe !== null) x-show="berubah || gagal" x-cloak wire:key="cek-ulang" @else wire:key="cek-awal" @endif class="mt-5">
                        <button type="button" @click="cek()" class="wt-btn-primary h-12 w-full text-xs tracking-[.1em]" x-text="gagal ? 'Coba lagi' : 'Cek ketersediaan'">Cek ketersediaan</button>
                        <p class="mb-0 mt-2.5 text-center text-xs text-ink-500">Pilih tanggal dan jumlah tamu untuk melihat sisa kamar.</p>
                    </div>

                    @if ($sisaTipe !== null)
                        <div x-show="!berubah && !gagal" x-cloak class="mt-5" data-sisa-tipe="{{ $sisaTipe }}">
                            @if ($penuh)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(212,81,74,.1)] px-2.5 py-1 text-xs font-semibold text-status-dirty">
                                    <span class="h-1.5 w-1.5 rounded-full bg-status-dirty"></span>Penuh untuk tanggal ini
                                </span>
                                @php $tersedia = $lain->filter(fn ($t) => $t->sisaTanggal > 0); @endphp
                                @if ($tersedia->isNotEmpty())
                                    <p class="mb-2 mt-4 text-[13px] text-ink-500">Masih tersedia di tanggal yang sama:</p>
                                    <ul class="m-0 flex list-none flex-col gap-2 p-0">
                                        @foreach ($tersedia as $t)
                                            <li>
                                                <a href="{{ route('kamar.tipe', ['kode' => Str::lower($t->code)] + $queryCari) }}"
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
                                @endif
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(92,158,103,.14)] px-2.5 py-1 text-xs font-semibold text-status-available">
                                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>Tersedia untuk {{ $cari->malam() }} malam
                                </span>
                                @unless ($adaPilihan)
                                    <p class="mb-0 mt-3 text-[13px] text-ink-500">Pilih jenis kasur di bawah. Anda bisa menambah kamar dari tipe lain dalam satu pemesanan.</p>
                                    <button type="button" @click="{{ $kePilihKasur }}" class="wt-btn-primary mt-4 h-12 w-full text-xs tracking-[.1em]" data-tombol-pilih-kasur>Pilih jenis kasur</button>
                                @endunless
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
                    @php $st = $t->sisaTanggal; $url = route('kamar.tipe', ['kode' => Str::lower($t->code)] + $queryCari); @endphp
                    <a href="{{ $url }}" wire:key="lain-{{ $t->id }}"
                       class="group flex flex-col overflow-hidden rounded-lg border border-cream-300 bg-cream-50 text-ink-900 shadow-wt-xs transition hover:-translate-y-0.5 hover:text-ink-900 hover:no-underline hover:shadow-wt-md">
                        <x-tamu.galeri :tipe="$t" :redup="$st === 0" class="aspect-[16/10]" />
                        <div class="flex flex-1 flex-col gap-1.5 p-4">
                            <span class="font-display text-[15px] font-bold uppercase tracking-[.04em]">{{ $t->name }}</span>
                            <span class="text-xs text-ink-500">Maks. {{ \App\Models\RoomType::teksBatas($t->kapasitas) }}{{ $t->kasur ? ' · '.implode(' atau ', $t->kasur) : '' }}</span>
                            <div class="mt-auto flex items-center justify-between gap-2 pt-2">
                                <span class="text-sm"><span class="font-display font-bold tabular-nums text-clay-600">{{ $rp($t->base_price) }}</span><span class="text-xs text-ink-500"> / malam</span></span>
                                @if ($st !== null)
                                    <span @class(['whitespace-nowrap text-xs font-semibold', 'text-status-dirty' => $st === 0, 'text-status-available' => $st > 0])>
                                        {{ $st === 0 ? 'Penuh' : 'Tersedia' }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($adaPilihan)
        @include('livewire.tamu.partials.bar-pilihan', ['r' => $ringkasan, 'urlKamar' => $urlKamar])
    @else
        {{-- Belum ada pilihan: bilah ringkas di bawah layar ponsel --}}
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-cream-300 bg-cream-50 px-4 pb-[max(12px,env(safe-area-inset-bottom))] pt-3 shadow-[0_-10px_28px_-14px_rgba(62,42,28,.35)] lg:hidden">
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="text-[11px] uppercase tracking-[.12em] text-ink-500">{{ $penuh ? 'Penuh untuk tanggal ini' : 'Mulai dari' }}</div>
                    <div class="text-sm"><span class="font-display text-base font-extrabold tabular-nums text-clay-600">{{ $rp($tipe->base_price) }}</span> <span class="text-xs text-ink-500">/ malam</span></div>
                </div>
                @if ($sisaTipe === null || $penuh)
                    <button type="button" @click="{{ $keTanggal }}" class="h-12 flex-none rounded border-0 bg-brown-800 px-5 font-display text-xs font-semibold uppercase tracking-[.1em] text-cream-50">{{ $penuh ? 'Ubah tanggal' : 'Cek tanggal' }}</button>
                @else
                    <button type="button" @click="{{ $kePilihKasur }}" class="wt-btn-primary h-12 flex-none px-5 text-xs tracking-[.08em]">Pilih kasur</button>
                @endif
            </div>
        </div>
    @endif

    @include('livewire.tamu.skrip-cari-kamar')
</div>
