@php
    use Illuminate\Support\Str;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $harga = fn ($m) => $m->price === null ? null : $rp($m->price);
    $minuman = ['Rekomendasi', 'Jus Buah', 'Teh', 'Kopi', 'Non Kafein'];
    // Keterangan singkat per kelompok, mengikuti menu cetak
    $catatan = [
        'Teh' => 'Tersedia panas atau dingin.',
        'Kopi' => 'Tersedia panas atau dingin.',
        'Paket' => 'Lengkap dengan nasi, sup, dan sambal.',
        'Tambahan' => 'Pelengkap untuk menu utama.',
    ];
    // Isi penampil foto (ketuk foto menu): foto besar, nama, deskripsi, harga, dan tombol pesan bila bisa dipesan
    $dataFoto = fn ($m) => ['id' => $m->id, 'url' => $m->fotoUrl(), 'nama' => $m->name, 'ket' => (string) $m->description,
        'harga' => $harga($m) ?? 'Sesuai ukuran', 'pesan' => ! $m->habis() && $m->price !== null, 'habis' => $m->habis()];
    $tanyaWa = 'https://wa.me/'.$wisma['whatsapp'].'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang menu restoran.');
    // Teks yang dicocokkan pencarian: nama dan deskripsi menu (sama dengan x-show tiap menu)
    $daftarGrup = $grup->map(fn ($isi, $nama) => ['nama' => $nama, 'slug' => Str::slug($nama), 'menu' => $isi->map(fn ($m) => Str::lower($m->name.' '.$m->description))->values()])->values();
@endphp

<x-layouts.guest :title="$title">
<div class="animate-wt-masuk" x-data="menuResto(@js($daftarGrup), @js($katalog), @js(['pemesan' => $pemesan, 'urlPesan' => route('resto.pesan'), 'bayarOnline' => $bayarOnline,
        'kamarTamu' => $kamarTamu, 'jenisAwal' => $jenisAwal, 'kodeGalat' => $kodeGalat,
        'urlKodeKamar' => route('resto.kode-kamar'), 'urlLupakan' => route('resto.kode-kamar.lupakan'), 'tamu' => auth()->guest()]))"
     x-effect="document.body.style.overflow = (panel || foto) ? 'hidden' : ''"
     @tawar-akun-buka.window="tawarAkun = true" @tawar-akun-tutup.window="tawarAkun = false" @tanpa-akun.window="lanjutTanpaAkun($event.detail.varian)">

    {{-- Datang lewat tautan kode kamar --}}
    @if ($kodeGalat)
        <div class="bg-[rgba(212,81,74,.08)] px-4 py-2.5 text-center text-[13px] font-medium text-status-dirty" role="alert">{{ $kodeGalat }}</div>
    @elseif ($jenisAwal === 'menginap' && $kamarTamu->isNotEmpty())
        <div class="bg-[rgba(92,158,103,.1)] px-4 py-2.5 text-center text-[13px] font-medium text-status-available" role="status">
            Kode kamar cocok · Kamar {{ $kamarTamu->pluck('nomor')->join(', ') }}. Pesanan bisa diantar dan ditagihkan ke kamar.
        </div>
    @endif

    {{-- Kepala halaman --}}
    <section class="relative isolate overflow-hidden bg-brown-900">
        <img src="{{ asset('ds/img/resto-header.jpg') }}" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover opacity-45">
        <div class="absolute inset-0 -z-10 bg-[linear-gradient(180deg,rgba(40,27,18,.55)_0%,rgba(40,27,18,.72)_55%,rgba(40,27,18,.94)_100%)]"></div>
        <div class="wt-container flex min-h-[300px] flex-col items-center justify-center py-10 text-center sm:min-h-[340px] sm:py-12">
            <div class="flex items-center gap-3 font-display text-[11px] font-semibold uppercase tracking-[.32em] text-sand-300">
                <span class="h-px w-8 bg-sand-300/60"></span>Wisma Trans · Malili<span class="h-px w-8 bg-sand-300/60"></span>
            </div>
            <h1 class="mb-0 mt-5 font-display text-[40px] font-extrabold uppercase leading-[1.05] tracking-[.04em] text-cream-50 sm:text-[60px]">
                Resto <span class="text-sand-300">&amp;</span> Kafe
            </h1>
            <x-resto.ornamen terang class="mt-4" />
            <p class="mb-0 mt-4 max-w-[560px] text-[15px] leading-relaxed text-[rgba(242,235,220,.82)] sm:text-base">
                Kopi, jus, dan masakan rumahan khas Malili. Pesan dan bayar online, lalu ambil pesanan Anda atau nikmati langsung di restoran kami.
            </p>
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <button type="button" @click="lompat(grup[0].slug)"
                        class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm border-0 bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 transition-colors hover:bg-clay-600 sm:w-auto">
                    Lihat menu
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
                </button>
                <a href="{{ $tanyaWa }}" target="_blank" rel="noopener"
                   class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm border border-[rgba(242,235,220,.38)] px-7 sm:w-auto font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 transition-colors hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline">
                    <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">
                    Tanya menu
                </a>
            </div>
        </div>

        {{-- Keunggulan singkat --}}
        <div class="border-t border-[rgba(242,235,220,.12)] bg-[rgba(20,13,8,.28)]">
            <div class="wt-container grid grid-cols-1 divide-y divide-[rgba(242,235,220,.10)] sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                @foreach ([
                    ['Pesan dari mana saja', 'Bayar praktis dengan QRIS, e-wallet, atau transfer bank'],
                    ['Santap di tempat atau dibawa pulang', 'Hidangan segera disiapkan begitu pembayaran diterima'],
                    ['Layanan antar ke kamar', 'Tamu menginap cukup memesan lewat telepon kamar'],
                ] as [$judulInfo, $isiInfo])
                    <div class="px-4 py-5 text-center">
                        <div class="font-display text-[10px] font-semibold uppercase tracking-[.24em] text-sand-300">{{ $judulInfo }}</div>
                        <div class="mt-1.5 text-[13px] text-[rgba(242,235,220,.74)]">{{ $isiInfo }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Pilihan favorit: 5 menu dengan jumlah terjual terbanyak --}}
    @if ($terlaris->isNotEmpty())
        <section class="bg-cream-50 py-14 sm:py-16">
            <div class="wt-container">
                <div class="mb-8 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <div class="font-display text-[11px] font-semibold uppercase tracking-[.28em] text-clay-500">Paling sering dipesan</div>
                        <h2 class="mb-0 mt-2 font-display text-[26px] font-extrabold uppercase leading-tight tracking-[.04em] sm:text-[30px]">Pilihan favorit tamu</h2>
                    </div>
                </div>
                <div class="-mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-3 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-3 sm:gap-5 sm:overflow-visible sm:px-0 lg:grid-cols-5">
                    @foreach ($terlaris as $i => $m)
                        <article @class(['group flex w-[210px] flex-none snap-start flex-col overflow-hidden rounded-xl border border-cream-300 bg-cream-50 transition duration-300 hover:-translate-y-1 hover:border-cream-400 hover:shadow-wt-md sm:w-auto', 'opacity-60' => $m->habis()])>
                            <div class="relative aspect-[4/3] overflow-hidden bg-[linear-gradient(150deg,#F4EADB,#E6D3B3)]">
                                @if ($foto = $m->fotoUrl())
                                    <button type="button" @click="lihatFoto(@js($dataFoto($m)))" aria-label="Lihat foto {{ $m->name }}" data-lihat-foto="{{ $m->id }}"
                                            class="block h-full w-full cursor-zoom-in border-0 bg-transparent p-0">
                                        <img src="{{ $foto }}" alt="{{ $m->name }}" loading="lazy" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105">
                                    </button>
                                @else
                                    <div class="flex h-full items-center justify-center text-clay-500/50">
                                        <x-resto.ikon :minum="in_array($m->menu_group, $minuman)" ukuran="36" />
                                    </div>
                                @endif
                                <span class="absolute left-3 top-3 flex h-7 min-w-7 items-center justify-center rounded-full bg-brown-800 px-2 font-display text-[11px] font-bold text-sand-300 shadow-wt-xs">#{{ $i + 1 }}</span>
                            </div>
                            <div class="flex flex-1 flex-col p-4">
                                <div class="font-display text-[10px] font-semibold uppercase tracking-[.18em] text-ink-500">{{ $m->menu_group }}</div>
                                <h3 class="mb-0 mt-1 text-[15px] font-semibold leading-snug">{{ $m->name }}</h3>
                                @if ($m->description)
                                    <p class="mb-0 mt-1 line-clamp-2 text-[13px] leading-snug text-ink-500">{{ $m->description }}</p>
                                @endif
                                <div class="mt-auto flex items-center justify-between gap-2 pt-4">
                                    <span class="whitespace-nowrap text-[15px] font-semibold tabular-nums text-clay-600">{{ $harga($m) ?? 'Sesuai ukuran' }}</span>
                                    <x-resto.tambah :menu="$m" />
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Tab kelompok (menempel saat digulir) dan pencarian --}}
    <div class="sticky top-[72px] z-30 border-y border-cream-300 bg-[rgba(246,243,233,.96)] backdrop-blur">
        <div class="wt-container flex items-center gap-8">
            {{-- Layar lebar: tab dibentangkan sampai ke kotak pencarian (justify-between), dipisah garis tegak --}}
            <nav class="-mx-1 flex min-w-0 flex-1 items-center gap-4 overflow-x-auto px-1 [scrollbar-width:none] lg:justify-between lg:gap-2.5 lg:overflow-visible xl:gap-3" aria-label="Kelompok menu" x-ref="tab">
                <template x-for="(g, i) in grupTampil" :key="g.slug">
                    <div class="contents">
                        <span x-show="i > 0" class="h-3.5 w-px flex-none bg-cream-400" aria-hidden="true"></span>
                        <button type="button" @click="lompat(g.slug)" :data-tab="g.slug"
                                class="relative h-12 flex-none whitespace-nowrap border-0 bg-transparent p-0 font-display text-[11px] font-semibold uppercase tracking-[.16em] transition-colors after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:bg-clay-500 after:transition-transform lg:h-14 lg:tracking-[.1em]"
                                :class="aktif === g.slug ? 'text-ink-900 after:scale-x-100' : 'text-ink-500 after:scale-x-0 hover:text-ink-900'"
                                :aria-current="aktif === g.slug ? 'true' : null"
                                x-text="g.nama"></button>
                    </div>
                </template>
            </nav>
            <label class="relative hidden w-[200px] flex-none xl:block">
                <span class="sr-only">Cari menu</span>
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" x-model="cari" placeholder="Cari menu" class="h-9 w-full rounded-full border border-cream-300 bg-cream-50 pl-9 pr-3 text-[13px] focus:border-clay-500">
            </label>
        </div>
        <div class="wt-container pb-3 xl:hidden">
            <label class="relative block">
                <span class="sr-only">Cari menu</span>
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" x-model="cari" placeholder="Cari menu" class="h-10 w-full rounded-full border border-cream-300 bg-cream-50 pl-9 pr-3 text-sm focus:border-clay-500">
            </label>
        </div>
    </div>

    {{-- Menu per kelompok, tata letak zigzag: satu gambar kelompok bergantian kiri dan kanan --}}
    <div class="bg-cream-50">
        <div class="wt-container py-14 lg:py-20" x-ref="daftarMenu">
            {{-- Ringkasan hasil pencarian --}}
            <div x-show="kata && totalCocok > 0" x-cloak class="mb-10 flex flex-wrap items-center justify-between gap-3 border-b border-cream-300 pb-4">
                <p class="m-0 text-sm text-ink-500">
                    <span class="font-semibold text-ink-900" x-text="totalCocok"></span> menu cocok dengan
                    &ldquo;<span class="font-semibold text-ink-900" x-text="cari.trim()"></span>&rdquo;
                </p>
                <button type="button" @click="cari = ''" class="h-9 rounded-full border border-cream-400 bg-transparent px-4 text-[13px] font-semibold text-brown-800 hover:bg-cream-200">Hapus pencarian</button>
            </div>

            @foreach ($grup as $nama => $isi)
                @php
                    $slug = Str::slug($nama);
                    $kanan = $loop->index % 2 === 1;
                    $minum = in_array($nama, $minuman);
                    // Gambar kelompok: foto menu pertama di kelompok ini yang sudah punya foto
                    $menuFotoGrup = $isi->first(fn ($m) => $m->fotoUrl());
                    $fotoGrup = $menuFotoGrup?->fotoUrl();
                @endphp
                <section id="grup-{{ $slug }}" data-grup="{{ $slug }}" x-show="jumlahCocok(grup[{{ $loop->index }}]) > 0" :class="kata && 'mb-12!'"
                         @class(['scroll-mt-[190px] lg:scroll-mt-[200px] xl:scroll-mt-[160px]', 'mb-20 lg:mb-28' => ! $loop->last])>
                    <div @class(['grid items-start gap-8 lg:gap-16',
                        'lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]' => ! $kanan,
                        'lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]' => $kanan]) :class="kata && 'lg:grid-cols-1!'">

                        {{-- Gambar kelompok --}}
                        {{-- Tinggi gambar mengikuti panjang daftar (320–560px) dan menempel saat daftar panjang digulir --}}
                        <div x-show="!kata" @class(['lg:self-stretch', 'lg:order-2' => $kanan])>
                            <div class="relative aspect-[16/10] overflow-hidden rounded-sm bg-brown-800 lg:sticky lg:top-[200px] xl:top-[160px] lg:aspect-auto lg:h-[clamp(320px,100%,560px)]">
                                @if ($fotoGrup)
                                    <button type="button" @click="lihatFoto(@js($dataFoto($menuFotoGrup)))" aria-label="Lihat foto {{ $menuFotoGrup->name }}" data-lihat-foto="{{ $menuFotoGrup->id }}"
                                            class="block h-full w-full cursor-zoom-in border-0 bg-transparent p-0">
                                        <img src="{{ $fotoGrup }}" alt="{{ $nama }}" class="h-full w-full object-cover" loading="lazy">
                                    </button>
                                @else
                                    <div class="flex h-full flex-col items-center justify-center gap-4 bg-[radial-gradient(circle_at_30%_20%,rgba(228,185,143,.22),transparent_55%),radial-gradient(circle_at_80%_90%,rgba(181,113,74,.30),transparent_50%)] text-sand-300">
                                        <span class="flex h-20 w-20 items-center justify-center rounded-full border border-[rgba(228,185,143,.4)]">
                                            <x-resto.ikon :minum="$minum" ukuran="34" />
                                        </span>
                                        <span class="font-display text-xs font-semibold uppercase tracking-[.3em] text-[rgba(242,235,220,.8)]">{{ $nama }}</span>
                                    </div>
                                @endif
                                <div class="pointer-events-none absolute inset-3 border border-[rgba(255,253,246,.35)]"></div>
                            </div>
                        </div>

                        {{-- Daftar menu --}}
                        <div class="min-w-0">
                            <div x-show="!kata" class="mb-2 font-display text-[11px] font-semibold uppercase tracking-[.28em] text-clay-500">{{ $minum ? 'Minuman' : 'Makanan' }}</div>
                            <h2 class="m-0 font-display text-[28px] font-extrabold uppercase leading-tight tracking-[.05em] sm:text-[32px]" :class="kata && 'text-lg! tracking-[.12em]!'">{{ $nama }}</h2>
                            @isset($catatan[$nama])
                                <p x-show="!kata" class="mb-0 mt-2 text-sm text-ink-500">{{ $catatan[$nama] }}</p>
                            @endisset
                            <div class="mt-6 flex flex-col" :class="kata && 'mt-3!'">
                                @foreach ($isi as $m)
                                    <article x-show="cocok(@js(Str::lower($m->name.' '.$m->description)))" @class(['flex items-center gap-4 border-b border-cream-300/70 py-4 last:border-b-0', 'opacity-60' => $m->habis()])>
                                        @if ($foto = $m->fotoUrl())
                                            <button type="button" @click="lihatFoto(@js($dataFoto($m)))" aria-label="Lihat foto {{ $m->name }}" data-lihat-foto="{{ $m->id }}"
                                                    class="h-14 w-14 flex-none cursor-zoom-in overflow-hidden rounded-full border-0 bg-transparent p-0 transition hover:ring-2 hover:ring-clay-500/50">
                                                <img src="{{ $foto }}" alt="{{ $m->name }}" class="h-full w-full object-cover" loading="lazy">
                                            </button>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-3">
                                                {{-- Nama menu beserta lencana Favorit/Baru; baris di bawahnya khusus deskripsi --}}
                                                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                                    <h3 class="m-0 min-w-0 font-display text-[13px] font-bold uppercase leading-snug tracking-[.08em] text-ink-900">{{ $m->name }}</h3>
                                                    @if ($m->isTerlaris())
                                                        <span class="flex-none rounded-full bg-[rgba(181,113,74,.12)] px-2 py-0.5 font-display text-[9px] font-bold uppercase tracking-[.12em] text-clay-600" title="Termasuk 5 menu terlaris">★ Favorit</span>
                                                    @endif
                                                    @if ($m->isNew())
                                                        <span class="flex-none rounded-full bg-[rgba(92,158,103,.14)] px-2 py-0.5 font-display text-[9px] font-bold uppercase tracking-[.12em] text-status-available">Baru</span>
                                                    @endif
                                                </div>
                                                <span class="h-[5px] min-w-6 flex-1 border-y border-[rgba(181,113,74,.35)]" aria-hidden="true"></span>
                                                <span class="flex-none whitespace-nowrap font-display text-sm font-bold tabular-nums text-clay-600">{{ $harga($m) ?? 'Sesuai ukuran' }}</span>
                                            </div>
                                            @if ($m->description)
                                                <p class="mb-0 mt-1.5 text-[13px] text-ink-500">{{ $m->description }}</p>
                                            @endif
                                        </div>
                                        <x-resto.tambah :menu="$m" />
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>
            @endforeach

            <div x-show="totalCocok === 0" x-cloak class="mx-auto max-w-[520px] py-10 text-center">
                <h2 class="m-0 font-display text-lg font-extrabold uppercase tracking-[.04em]">Menu tidak ditemukan</h2>
                <p class="mb-0 mt-2 text-sm text-ink-500">Coba kata lain, atau tanyakan langsung kepada staf restoran.</p>
                <button type="button" @click="cari = ''" class="mt-5 h-10 rounded-full border border-cream-400 bg-transparent px-5 text-[13px] font-semibold text-brown-800 hover:bg-cream-200">Tampilkan semua menu</button>
            </div>
        </div>
    </div>

    {{-- Penutup: kartu kontak di atas latar krem supaya terpisah jelas dari footer yang sama-sama cokelat --}}
    <section class="bg-cream-50 pb-16 lg:pb-20">
        <div class="wt-container">
            <div class="relative overflow-hidden rounded-sm bg-brown-800 bg-[radial-gradient(circle_at_12%_0%,rgba(228,185,143,.16),transparent_45%),radial-gradient(circle_at_95%_100%,rgba(181,113,74,.28),transparent_50%)]">
                <div class="pointer-events-none absolute inset-3 border border-[rgba(255,253,246,.18)]" aria-hidden="true"></div>
                <div class="relative grid items-center gap-8 px-8 py-10 sm:px-12 sm:py-12 md:grid-cols-[1fr_auto]">
                    <div>
                        <div class="font-display text-[11px] font-semibold uppercase tracking-[.28em] text-sand-300">Butuh bantuan?</div>
                        <h2 class="mb-0 mt-3 font-display text-[24px] font-extrabold uppercase leading-tight tracking-[.04em] text-cream-50 sm:text-[28px]">Ada pertanyaan tentang menu?</h2>
                        <p class="mb-0 mt-2 max-w-[480px] text-sm text-[rgba(242,235,220,.74)]">Staf restoran siap membantu soal bahan, porsi, atau pesanan khusus.</p>
                    </div>
                    <a href="{{ $tanyaWa }}" target="_blank" rel="noopener"
                       class="inline-flex h-12 items-center gap-2.5 self-start rounded-sm bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 transition-colors hover:bg-clay-600 hover:text-cream-50 hover:no-underline md:self-auto">
                        <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="18" height="18" class="brightness-0 invert">
                        Tanya lewat WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Penampil foto menu: ketuk foto untuk melihat lebih detail; ketuk foto besar untuk memperbesar di titik itu --}}
    <template x-teleport="body">
        <div x-show="foto" x-cloak x-transition.opacity role="dialog" aria-modal="true" :aria-label="foto ? 'Foto ' + foto.nama : ''"
             @keydown.escape.window="foto && tutupFoto()" data-penampil-foto
             class="fixed inset-0 z-[400] flex flex-col bg-[rgba(20,13,8,.96)]">
            <div class="flex flex-none items-center justify-between gap-4 px-4 py-3 text-cream-50 sm:px-6">
                <span class="min-w-0 truncate font-display text-xs font-semibold uppercase tracking-[.16em]" x-text="foto?.nama"></span>
                <button type="button" @click="tutupFoto()" x-ref="tutupFoto" aria-label="Tutup foto"
                        class="flex h-10 w-10 flex-none items-center justify-center rounded-full border-0 bg-[rgba(242,235,220,.12)] text-cream-50 hover:bg-[rgba(242,235,220,.2)]">
                    <x-tamu.ikon nama="tutup" ukuran="18" />
                </button>
            </div>
            {{-- Ketuk di luar foto untuk menutup --}}
            <div class="flex min-h-0 flex-1 items-center justify-center overflow-hidden p-3 sm:p-8" @click.self="tutupFoto()">
                <img :src="foto?.url" :alt="foto?.nama" @click="ketukFoto($event)" @mousemove="zoom && geserFoto($event)"
                     :style="zoom ? `transform: scale(2.2); transform-origin: ${asalZoom}` : ''"
                     :class="zoom ? 'cursor-zoom-out' : 'cursor-zoom-in'"
                     class="max-h-full max-w-full select-none rounded-sm object-contain transition-transform duration-200">
            </div>
            <div class="flex flex-none flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t border-[rgba(242,235,220,.12)] px-4 py-4 text-cream-50 sm:px-6">
                <div class="min-w-0 flex-1">
                    <p class="m-0 text-[13px] leading-snug text-[rgba(242,235,220,.8)]" x-show="foto?.ket" x-text="foto?.ket"></p>
                    <p class="m-0 mt-1 text-[11px] text-[rgba(242,235,220,.55)]" x-text="zoom ? 'Ketuk foto untuk kembali.' : 'Ketuk foto untuk memperbesar.'"></p>
                </div>
                <div class="flex flex-none items-center gap-4">
                    <span class="font-display text-base font-bold tabular-nums text-sand-300" x-text="foto?.harga"></span>
                    <span x-show="foto?.habis" class="rounded-full bg-[rgba(212,81,74,.2)] px-2.5 py-1 font-display text-[10px] font-bold uppercase tracking-[.12em] text-[#F2B8B4]">Sedang tidak tersedia</span>
                    <template x-if="foto?.pesan">
                        <div>
                            <button type="button" x-show="!jumlah(foto.id)" @click="tambah(foto.id)"
                                    class="flex h-10 items-center gap-1.5 rounded-full border-0 bg-clay-500 px-5 font-display text-xs font-semibold text-cream-50 hover:bg-clay-600">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                Tambah ke pesanan
                            </button>
                            <div x-show="jumlah(foto.id)" class="flex items-center gap-0.5 rounded-full border border-[rgba(242,235,220,.3)] p-0.5">
                                <button type="button" @click="kurang(foto.id)" :aria-label="'Kurangi ' + foto.nama"
                                        class="flex h-9 w-9 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center rounded-full border-0 bg-transparent text-cream-50 hover:bg-[rgba(242,235,220,.12)]">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg>
                                </button>
                                <span class="min-w-7 text-center font-display text-sm font-bold tabular-nums" x-text="jumlah(foto.id)"></span>
                                <button type="button" @click="tambah(foto.id)" :aria-label="'Tambah ' + foto.nama"
                                        class="flex h-9 w-9 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center rounded-full border-0 bg-clay-500 text-cream-50 hover:bg-clay-600">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- Kartu keranjang melayang di bawah layar; klik di mana saja membuka panel pesanan --}}
    <div x-show="totalItem > 0 && !panel" x-cloak
         x-transition:enter="transition duration-300 ease-wt" x-transition:enter-start="translate-y-6 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition duration-150 ease-wt" x-transition:leave-start="translate-y-0 opacity-100" x-transition:leave-end="translate-y-6 opacity-0"
         class="pointer-events-none fixed inset-x-0 bottom-0 z-40 px-3 pb-[max(12px,env(safe-area-inset-bottom))] sm:px-6 sm:pb-6">
        <div class="pointer-events-auto mx-auto flex max-w-[680px] items-center gap-3 rounded-lg border border-cream-300 bg-cream-50 p-2.5 pl-3 shadow-[0_18px_40px_-12px_rgba(62,42,28,.45),0_2px_6px_rgba(62,42,28,.08)] sm:gap-4 sm:p-3 sm:pl-4">
            <button type="button" @click="bukaPanel()" class="flex min-w-0 flex-1 items-center gap-3 border-0 bg-transparent p-0 text-left" aria-label="Lihat pesanan">
                <span class="relative flex h-11 w-11 flex-none items-center justify-center rounded-md bg-brown-800 text-cream-150">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5.5 8h13l-1.1 11.1a1 1 0 0 1-1 .9H7.6a1 1 0 0 1-1-.9L5.5 8Z"/><path d="M9 8V7a3 3 0 0 1 6 0v1"/></svg>
                    {{-- Lencana jumlah; berdenyut sebentar setiap jumlah berubah --}}
                    <span x-text="totalItem" x-effect="totalItem && $el.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.3)' }, { transform: 'scale(1)' }], { duration: 280, easing: 'ease-out' })"
                          class="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-clay-500 px-1 text-[11px] font-bold tabular-nums text-cream-50 ring-2 ring-cream-50"></span>
                </span>
                <span class="min-w-0">
                    <span class="block whitespace-nowrap font-display text-[10px] font-bold uppercase tracking-[.16em] text-clay-500">Pesanan Anda</span>
                    <span class="mt-0.5 flex items-baseline gap-1.5 whitespace-nowrap">
                        <span class="font-display text-[17px] font-extrabold tabular-nums text-ink-900" x-text="rupiah(total)"></span>
                        <span class="hidden text-[13px] text-ink-500 sm:inline" x-text="'· ' + totalItem + ' item'"></span>
                    </span>
                </span>
            </button>
            <button type="button" @click="bukaPanel()"
                    class="group inline-flex h-12 flex-none items-center gap-2 rounded-md border-0 bg-clay-500 px-4 font-display text-xs font-bold uppercase tracking-[.08em] sm:text-[13px] text-cream-50 shadow-wt-xs transition-colors hover:bg-clay-600 sm:px-6">
                Lanjutkan
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="transition-transform group-hover:translate-x-0.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </div>
    </div>

    {{-- Tawaran masuk saat tamu (belum masuk) menekan Lanjut: masuk/daftar, atau lanjut tanpa akun. Keranjang tetap tersimpan. --}}
    <x-tawaran-akun kunci="wt.resto.tanpa-akun" :kembali="route('menu', ['lanjut' => 1])" :varian="[
        'luar' => ['manfaat' => [
                ['Data pemesan terisi otomatis', 'Nama dan nomor WhatsApp tidak perlu diketik ulang setiap kali memesan.'],
                ['Riwayat pesanan tersimpan', 'Pantau status pesanan kapan saja melalui menu Pesanan saya.'],
            ], 'lanjut' => 'Lanjutkan sebagai tamu', 'ingat' => true, 'catatan' => 'Isi keranjang Anda tetap tersimpan setelah masuk.'],
        'menginap' => ['manfaat' => [
                ['Kamar langsung dikenali', 'Pesanan berikutnya tidak lagi memerlukan kode kamar.'],
                ['Riwayat pesanan tersimpan', 'Pantau status pesanan kapan saja melalui menu Pesanan saya.'],
            ], 'lanjut' => 'Lanjutkan sebagai tamu', 'ingat' => true, 'catatan' => 'Isi keranjang Anda tetap tersimpan setelah masuk.'],
        'kamar' => ['manfaat' => [
                ['Kamar langsung dikenali', 'Masuk dengan akun yang dipakai saat memesan kamar, tanpa kode kamar.'],
                ['Tagihkan ke kamar', 'Pesanan dapat dibayar sekaligus saat check-out.'],
                ['Riwayat pesanan tersimpan', 'Pantau status pesanan kapan saja melalui menu Pesanan saya.'],
            ], 'lanjut' => 'Gunakan kode kamar', 'ingat' => false, 'catatan' => 'Isi keranjang Anda tetap tersimpan setelah masuk.'],
    ]" />


    {{-- Panel pesanan: 1) keranjang dan cara penyajian, 2) data pemesan dan metode bayar --}}
    <div x-show="panel" x-cloak @click="tutupPanel()" x-transition.opacity class="fixed inset-0 z-[340] bg-[rgba(62,42,28,.40)]"></div>
    <aside x-show="panel" x-cloak x-ref="panel" @keydown.escape.window="panel && !tawarAkun && tutupPanel()"
           x-transition:enter="transition duration-250 ease-wt" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
           x-transition:leave="transition duration-200 ease-wt" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
           role="dialog" aria-modal="true" aria-labelledby="judul-pesanan"
           class="fixed inset-y-0 right-0 z-[345] flex w-[460px] max-w-full flex-col bg-cream-50 shadow-wt-lg">
        <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-5 py-4 sm:px-6">
            <button type="button" x-show="langkah === 2" @click="langkah = 1" aria-label="Kembali ke keranjang"
                    class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <div class="min-w-0 flex-1">
                <div id="judul-pesanan" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]" x-text="langkah === 1 ? 'Pesanan Anda' : 'Pemesan dan pembayaran'"></div>
                <div class="text-xs text-ink-500" x-text="'Langkah ' + langkah + ' dari 2'"></div>
            </div>
            <button type="button" @click="tutupPanel()" aria-label="Tutup"
                    class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
            {{-- Langkah 1 --}}
            <div x-show="langkah === 1" class="flex flex-col gap-6">
                {{-- Tamu menginap memesan ke kamarnya dengan kode kamar; tamu luar membayar di muka --}}
                <div data-isian="kamar">
                    <div class="grid grid-cols-2 gap-1 rounded-lg border border-cream-300 bg-cream-100 p-1" role="radiogroup" aria-label="Jenis tamu">
                        @foreach (['luar' => 'Tidak menginap', 'menginap' => 'Sedang menginap'] as $nilai => $label)
                            <button type="button" role="radio" :aria-checked="jenis === '{{ $nilai }}'" @click="pilihJenis('{{ $nilai }}')"
                                    class="min-h-10 rounded-md border-0 px-2.5 py-1.5 text-[13px] font-semibold leading-tight transition"
                                    :class="jenis === '{{ $nilai }}' ? 'bg-brown-800 text-cream-50 shadow-wt-xs' : 'bg-transparent text-ink-500 hover:text-ink-900'">{{ $label }}</button>
                        @endforeach
                    </div>
                    <p class="mb-0 mt-2 text-xs leading-relaxed text-ink-500" data-keterangan-jenis
                       x-text="jenis === 'menginap' ? 'Masukkan kode kamar, tagihan masuk ke nota kamar Anda.' : 'Bayar langsung saat memesan.'">{{ $jenisAwal === 'menginap' ? 'Masukkan kode kamar, tagihan masuk ke nota kamar Anda.' : 'Bayar langsung saat memesan.' }}</p>

                    <div x-show="jenis === 'menginap'" x-cloak class="mt-3">
                        {{-- Kamar terpilih --}}
                        <template x-if="kamarDipilih">
                            <div class="flex items-center gap-3 rounded-lg border border-[rgba(92,158,103,.4)] bg-[rgba(92,158,103,.08)] px-3.5 py-3">
                                <svg class="flex-none text-status-available" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                <span class="min-w-0 flex-1 text-sm font-semibold" x-text="'Kamar ' + kamarDipilih.nomor"></span>
                                <button type="button" @click="gantiKamar()" class="border-0 bg-transparent p-0 text-[13px] font-semibold text-clay-600 hover:underline">Ganti</button>
                            </div>
                        </template>
                        {{-- Beberapa kamar: pilih salah satu --}}
                        <template x-if="!kamarDipilih && kamarList.length">
                            <div data-pilih-kamar>
                                <div class="mb-2 flex items-baseline justify-between gap-3">
                                    <span class="wt-field-label">Pilih kamar Anda</span>
                                    <span class="text-[11px] tabular-nums text-ink-500" x-text="kamarList.length + ' kamar'"></span>
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <template x-for="k in kamarList" :key="k.id">
                                        <button type="button" @click="kamarId = k.id; delete galat.kamar" :aria-label="'Kamar ' + k.nomor"
                                                class="group flex flex-col items-start rounded-lg border bg-cream-50 px-3 py-2.5 text-left transition hover:-translate-y-0.5 hover:border-clay-500 hover:shadow-wt-xs"
                                                :class="galat.kamar ? 'border-status-dirty' : 'border-cream-300'">
                                            <span class="text-[10px] font-semibold uppercase tracking-[.14em] text-ink-500 group-hover:text-clay-600">Kamar</span>
                                            <span class="font-display text-xl font-extrabold leading-tight tabular-nums text-ink-900" x-text="k.nomor"></span>                                        </button>
                                    </template>
                                </div>
                                <button type="button" @click="lupakanKode()" class="mt-2.5 inline-flex items-center gap-1.5 border-0 bg-transparent p-0 text-xs font-semibold text-clay-600 hover:underline">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3M17 6l3 3M15 8l2 2"/></svg>
                                    Pakai kode kamar lain
                                </button>
                            </div>
                        </template>
                        {{-- Belum ada kamar: ketik kode kamar --}}
                        <template x-if="!kamarList.length">
                            <form @submit.prevent="cocokkan()" novalidate>
                                <label class="wt-field-label mb-1.5 block" for="kode-kamar">Kode kamar</label>
                                <div class="flex gap-2">
                                    <input id="kode-kamar" type="text" x-model="kodeIsian" @input="kodeGalat = null" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false"
                                           placeholder="Contoh: LK7Q-2M9X" class="wt-input h-11 min-w-0 flex-1 uppercase tracking-[.08em] placeholder:normal-case placeholder:tracking-normal"
                                           :class="kodeGalat && 'is-invalid'">
                                    <button type="submit" :disabled="cocokkanProses" class="h-11 flex-none rounded border-0 bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900 disabled:opacity-70"
                                            x-text="cocokkanProses ? 'Mencocokkan…' : 'Cocokkan'">Cocokkan</button>
                                </div>
                                <span x-show="kodeGalat" x-text="kodeGalat" x-cloak class="mt-1.5 block text-xs font-medium text-status-dirty" role="alert"></span>
                                <p class="mb-0 mt-1.5 text-xs leading-snug text-ink-500">Kode kamar ada di pesan WhatsApp saat check-in dan di halaman reservasi Anda.</p>
                            </form>
                        </template>
                        <span x-show="galat.kamar" x-text="galat.kamar" x-cloak class="mt-1.5 block text-xs font-medium text-status-dirty" role="alert"></span>
                    </div>
                </div>

                <div class="flex flex-col divide-y divide-cream-300 rounded-lg border border-cream-300">
                    <template x-for="b in daftar" :key="b.id">
                        <div class="px-3.5 py-3">
                        <div class="flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold" x-text="b.nama"></div>
                                <div class="text-xs tabular-nums text-ink-500" x-text="rupiah(b.harga) + ' × ' + b.qty"></div>
                            </div>
                            <div class="flex items-center gap-0.5 rounded-full border border-cream-300 p-0.5">
                                <button type="button" @click="kurang(b.id)" :aria-label="'Kurangi ' + b.nama" class="flex h-7 w-7 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center rounded-full border-0 bg-transparent text-brown-800 hover:bg-cream-200">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg>
                                </button>
                                <span class="min-w-5 text-center text-sm font-bold tabular-nums" x-text="b.qty"></span>
                                <button type="button" @click="tambah(b.id)" :aria-label="'Tambah ' + b.nama" class="flex h-7 w-7 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center rounded-full border-0 bg-transparent text-brown-800 hover:bg-cream-200">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                </button>
                            </div>
                            <div class="w-[84px] text-right text-sm font-semibold tabular-nums" x-text="rupiah(b.subtotal)"></div>
                        </div>

                        {{-- Catatan per menu untuk dapur, seperti di aplikasi pesan antar --}}
                        <div class="mt-2">
                            <template x-if="ubahCatatan !== b.id">
                                <button type="button" @click="bukaCatatan(b.id)"
                                        class="flex w-full min-w-0 items-center gap-2 rounded-md border border-dashed border-cream-400 bg-transparent px-2.5 py-1.5 text-left text-xs hover:border-clay-500 hover:bg-[rgba(181,113,74,.05)]"
                                        :aria-label="(b.note ? 'Ubah catatan ' : 'Tambah catatan untuk ') + b.nama">
                                    <svg class="flex-none text-clay-600" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    <span x-show="!b.note" class="font-semibold text-clay-600">Tambah catatan</span>
                                    <span x-show="b.note" class="min-w-0 flex-1 truncate italic text-ink-900" x-text="b.note"></span>
                                    <span x-show="b.note" class="flex-none font-semibold text-clay-600">Ubah</span>
                                </button>
                            </template>
                            <template x-if="ubahCatatan === b.id">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <input type="text" x-model="catatan[b.id]" maxlength="150" placeholder="Contoh: tidak pedas, tanpa bawang"
                                               x-init="$nextTick(() => $el.focus())" @keydown.enter.prevent="simpanCatatan()" @keydown.escape.stop="simpanCatatan()"
                                               class="wt-input h-9 min-w-0 flex-1 text-[13px]" :aria-label="'Catatan untuk ' + b.nama">
                                        <button type="button" @click="simpanCatatan()"
                                                class="h-9 flex-none rounded border-0 bg-brown-800 px-3.5 text-xs font-semibold text-cream-50 hover:bg-brown-900">Simpan</button>
                                    </div>
                                    <div class="mt-1 text-right text-[11px] tabular-nums text-ink-500" x-text="(catatan[b.id] || '').length + '/150'"></div>
                                </div>
                            </template>
                        </div>
                        </div>
                    </template>
                </div>

                <div>
                    <div class="mb-2 flex items-baseline justify-between gap-3">
                        <span class="wt-field-label">Penyajian</span>
                        <span x-show="galat.serving_type" x-text="galat.serving_type" x-cloak class="text-[11px] font-medium text-status-dirty"></span>
                    </div>
                    {{-- Daftar pilihan berikon; Antar ke kamar hanya untuk tamu menginap --}}
                    <div class="flex flex-col divide-y divide-cream-300 overflow-hidden rounded-lg border" data-cara-menikmati
                         :class="galat.serving_type ? 'border-status-dirty' : 'border-cream-300'">
                        @foreach ([
                            ['room_service', 'Antar ke kamar', 'Diantar staf ke kamar Anda', '<path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16"/><path d="M2 21h20"/><path d="M15 12h.01"/>'],
                            ['take_away', 'Ambil di tempat', 'Dibungkus, diambil di restoran', '<path d="M6 8h12l1 13H5L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>'],
                            ['dine_in', 'Makan di tempat', 'Disajikan di restoran', '<path d="M7 3v8a2 2 0 0 0 2 2v8"/><path d="M11 3v8a2 2 0 0 1-2 2"/><path d="M17 21V3c-1.7 1-3 3.2-3 6v4h3"/>'],
                        ] as [$nilai, $label, $ket, $ikon])
                            <label @if ($nilai === 'room_service') x-show="jenis === 'menginap'" x-cloak @endif
                                   class="group flex cursor-pointer items-center gap-3.5 px-3.5 py-3 transition hover:bg-row-hover has-[:checked]:bg-[rgba(181,113,74,.07)]">
                                <input type="radio" name="serving_type" value="{{ $nilai }}" x-model="isi.serving_type" @change="delete galat.serving_type" class="peer sr-only">
                                <span class="flex h-10 w-10 flex-none items-center justify-center rounded-lg bg-cream-200 text-clay-600 transition group-has-[:checked]:bg-clay-500 group-has-[:checked]:text-cream-50" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $ikon !!}</svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-ink-900">{{ $label }}</span>
                                    <span class="mt-0.5 block text-xs leading-snug text-ink-500">{{ $ket }}</span>
                                </span>
                                <span class="flex h-5 w-5 flex-none items-center justify-center rounded-full border border-cream-400 text-transparent transition peer-checked:border-clay-500 peer-checked:bg-clay-500 peer-checked:text-cream-50" aria-hidden="true">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <label class="block">
                    <span class="wt-field-label mb-1.5 block">Catatan untuk restoran (opsional)</span>
                    <textarea x-model="isi.note" rows="2" maxlength="255" placeholder="Contoh: minta sendok plastik, diambil pukul 12.00" class="wt-input"></textarea>
                </label>
            </div>

            {{-- Langkah 2 --}}
            <div x-show="langkah === 2" x-cloak class="flex flex-col gap-4">
                {{-- Keranjang tersimpan di peramban; setelah masuk panel pesanan dibuka lagi (?lanjut) --}}
                {{-- Tamu menginap: pemesan = pemesan reservasi, tinggal memilih cara bayar --}}
                <template x-if="jenis === 'menginap'">
                    <fieldset class="m-0 min-w-0 border-0 p-0">
                        <legend class="wt-field-label mb-2 p-0">Pembayaran</legend>
                        <div class="flex flex-col gap-2">
                            @foreach ([['kamar', 'Masuk tagihan kamar', 'Dibayar saat check-out. Pesanan langsung diproses dapur.'], ['sekarang', 'Bayar sekarang', 'Virtual account atau QRIS']] as [$nilai, $label, $ket])
                                <label class="relative cursor-pointer rounded-lg border border-cream-300 p-3.5 transition hover:bg-row-hover has-[:checked]:border-clay-500 has-[:checked]:bg-[rgba(181,113,74,.07)]">
                                    <input type="radio" name="tagih" value="{{ $nilai }}" x-model="tagih" class="peer sr-only">
                                    <span class="absolute right-3 top-3.5 h-4 w-4 rounded-full border border-cream-400 peer-checked:border-[5px] peer-checked:border-clay-500"></span>
                                    <span class="block pr-6 text-sm font-semibold">{{ $label }}</span>
                                    <span class="mt-0.5 block text-xs leading-snug text-ink-500">{{ $ket }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </template>

                @foreach ([
                    ['name', 'Nama pemesan', 'text', 'name', 'Nama Anda'],
                    ['phone', 'Nomor WhatsApp', 'tel', 'tel', '08xxxxxxxxxx'],
                    ['email', 'Email (opsional)', 'email', 'email', 'Untuk bukti pembayaran'],
                ] as [$f, $label, $type, $auto, $ph])
                    <label class="block" x-show="jenis === 'luar'">
                        <span class="wt-field-label mb-1.5 block">{{ $label }}</span>
                        <input type="{{ $type }}" x-model="isi.{{ $f }}" @input="delete galat.{{ $f }}" autocomplete="{{ $auto }}" placeholder="{{ $ph }}"
                               data-isian="{{ $f }}" class="wt-input h-11 text-[15px]" :class="galat.{{ $f }} && 'is-invalid'">
                        {{-- Pesan galat di bawah kolom agar tampil utuh --}}
                        <span x-show="galat.{{ $f }}" x-text="galat.{{ $f }}" x-cloak class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert"></span>
                    </label>
                @endforeach

                <div class="rounded-lg bg-cream-200 p-4 text-sm">
                    <div class="flex justify-between gap-3"><span class="text-ink-500">Pesanan</span><span class="font-semibold" x-text="totalItem + ' item'"></span></div>
                    <div class="mt-1 flex justify-between gap-3"><span class="text-ink-500">Penyajian</span><span class="font-semibold" x-text="{ dine_in: 'Makan di tempat', take_away: 'Ambil di tempat', room_service: 'Antar ke kamar' }[isi.serving_type]"></span></div>
                    <div x-show="kamarDipilih && jenis === 'menginap'" class="mt-1 flex justify-between gap-3"><span class="text-ink-500">Kamar</span><span class="font-semibold" x-text="kamarDipilih?.nomor"></span></div>
                </div>
                {{-- Metode bayar: nomor VA atau QRIS langsung dibuat bersama pesanan --}}
                <fieldset x-show="perluBayar" class="m-0 mt-1 min-w-0 border-0 p-0">
                    <legend class="mb-2 flex w-full items-baseline justify-between gap-3 p-0">
                        <span class="wt-field-label">Metode pembayaran</span>
                        <span x-show="galat.metode" x-text="galat.metode" x-cloak class="text-[11px] font-medium text-status-dirty"></span>
                    </legend>
                    <div class="flex flex-col divide-y divide-cream-300 overflow-hidden rounded-lg border border-cream-300" :class="galat.metode && 'border-status-dirty'" data-isian="metode">
                        @foreach (\App\Services\Keuangan\PembayaranMidtrans::METODE as $kunci => [$nama, $ket])
                            <label class="relative flex cursor-pointer items-center gap-3 px-3.5 py-2.5 transition hover:bg-row-hover has-[:checked]:bg-[rgba(181,113,74,.07)]">
                                <input type="radio" name="metode" value="{{ $kunci }}" x-model="isi.metode" @change="delete galat.metode" class="peer sr-only">
                                <span class="flex h-9 w-16 flex-none items-center justify-center rounded border border-cream-300 bg-white px-1.5">
                                    <img src="{{ asset('ds/bank/'.$kunci.'.svg') }}" alt="" class="max-h-5 max-w-full object-contain">
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold leading-snug">{{ $nama }}</span>
                                    <span class="block truncate text-xs text-ink-500">{{ $ket }}</span>
                                </span>
                                <span class="h-4 w-4 flex-none rounded-full border border-cream-400 peer-checked:border-[5px] peer-checked:border-clay-500"></span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mb-0 mt-2 text-xs leading-relaxed text-ink-500">Nomor pembayaran langsung tampil setelah pesanan dibuat. Pesanan diproses dapur setelah pembayaran berhasil.</p>
                </fieldset>
            </div>
        </div>

        <div class="flex-none border-t border-cream-300 px-5 pb-[max(16px,env(safe-area-inset-bottom))] pt-4 sm:px-6">
            <div class="mb-3 flex items-baseline justify-between">
                <span class="text-sm text-ink-500">Total</span>
                <span class="font-display text-xl font-extrabold tabular-nums" x-text="rupiah(total)"></span>
            </div>
            <button type="button" x-show="langkah === 1" @click="lanjut()"
                    class="h-12 w-full rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600">Lanjut</button>
            <button type="button" x-show="langkah === 2" x-cloak @click="bayar()" :disabled="proses"
                    class="h-12 w-full rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70"
                    x-text="proses ? 'Membuat pesanan…' : (perluBayar ? 'Bayar ' : 'Pesan · ') + rupiah(total)"></button>
        </div>
    </aside>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        const simpananDari = (kunci) => ({
            baca() { try { return JSON.parse(localStorage.getItem(kunci) || '{}'); } catch { return {}; } },
            tulis(v) { try { localStorage.setItem(kunci, JSON.stringify(v)); } catch { /* mode privat */ } },
        });
        const simpanan = simpananDari('wt.keranjang');
        const simpananCatatan = simpananDari('wt.keranjang.catatan');
        // Metode bayar terakhir dipilih di perangkat ini diingat untuk pesanan berikutnya
        const metodeTerakhir = () => { try { return localStorage.getItem('wt.metode-bayar') || ''; } catch { return ''; } };

        Alpine.data('menuResto', (grup, katalog, cfg) => ({
            grup,
            cari: '',
            aktif: grup[0]?.slug ?? null,
            kunciTab: 0,

            // Keranjang disimpan di perangkat ini saja: { idMenu: jumlah }
            keranjang: {},
            // Catatan per menu: { idMenu: teks }; ubahCatatan = id menu yang catatannya sedang diketik
            catatan: {},
            ubahCatatan: null,
            panel: false,
            // Pop-up x-tawaran-akun sedang terbuka (Esc menutup pop-up dulu, bukan panel)
            tawarAkun: false,
            // Penampil foto menu: { id, url, nama, ket, harga, pesan, habis } atau null
            foto: null,
            zoom: false,
            asalZoom: '50% 50%',
            langkah: 1,
            isi: { ...cfg.pemesan, serving_type: cfg.jenisAwal === 'menginap' ? 'room_service' : '', note: '', metode: metodeTerakhir() },
            galat: {},
            proses: false,

            // Tamu menginap: kamar dari kode kamar (session) atau dari akun pemilik reservasi
            jenis: cfg.jenisAwal,
            kamarList: cfg.kamarTamu,
            kamarId: cfg.kamarTamu.length === 1 ? cfg.kamarTamu[0].id : null,
            kodeIsian: '',
            kodeGalat: cfg.kodeGalat,
            cocokkanProses: false,
            tagih: 'kamar',
            get kamarDipilih() { return this.kamarList.find((k) => k.id === this.kamarId) ?? null; },
            get perluBayar() { return this.jenis === 'luar' || this.tagih === 'sekarang'; },
            pilihJenis(j) {
                this.jenis = j;
                delete this.galat.kamar;
                if (j === 'menginap' && !this.isi.serving_type) this.isi.serving_type = 'room_service';
                if (j === 'luar' && this.isi.serving_type === 'room_service') this.isi.serving_type = '';
            },
            async kirimJson(url, body) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify(body),
                });
                return [res, await res.json().catch(() => ({}))];
            },
            async cocokkan() {
                if (this.cocokkanProses) return;
                if (!this.kodeIsian.trim()) { this.kodeGalat = 'Isi kode kamar.'; return; }
                this.cocokkanProses = true;
                try {
                    const [res, data] = await this.kirimJson(cfg.urlKodeKamar, { kode_kamar: this.kodeIsian });
                    if (res.ok) {
                        this.kamarList = data.kamar;
                        this.kamarId = data.kamar.length === 1 ? data.kamar[0].id : null;
                        this.kodeIsian = '';
                        delete this.galat.kamar;
                    } else if (res.status === 419) {
                        this.kodeGalat = 'Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.';
                    } else if (res.status === 429) {
                        this.kodeGalat = 'Terlalu banyak percobaan. Tunggu sebentar, lalu coba lagi.';
                    } else {
                        this.kodeGalat = data.pesan || 'Kode kamar tidak cocok.';
                    }
                } catch {
                    this.kodeGalat = 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.';
                }
                this.cocokkanProses = false;
            },
            gantiKamar() {
                if (this.kamarList.length > 1) { this.kamarId = null; return; }
                this.lupakanKode();
            },
            async lupakanKode() {
                this.kamarId = null;
                try {
                    const [res, data] = await this.kirimJson(cfg.urlLupakan, {});
                    if (res.ok) this.kamarList = data.kamar;
                    else if (res.status === 419) Alpine.store('toasts').tampil('Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.', '#D4514A');
                } catch { this.kamarList = []; }
            },

            jumlah(id) { return this.keranjang[id] || 0; },
            tambah(id) {
                if (!katalog[id]) return;
                const n = this.jumlah(id);
                if (n >= 20) return Alpine.store('toasts').tampil('Maksimal 20 porsi per menu', '#E8AC45');
                this.keranjang = { ...this.keranjang, [id]: n + 1 };
            },
            kurang(id) {
                const k = { ...this.keranjang };
                if (k[id] > 1) {
                    k[id]--;
                } else {
                    delete k[id];
                    this.hapusCatatan(id);
                }
                this.keranjang = k;
                if (!Object.keys(k).length) this.tutupPanel();
            },
            get daftar() {
                return Object.entries(this.keranjang).filter(([id]) => katalog[id])
                    .map(([id, qty]) => ({ id: +id, nama: katalog[id].nama, harga: katalog[id].harga, qty, subtotal: katalog[id].harga * qty,
                        note: (this.catatan[id] || '').trim() }));
            },
            bukaCatatan(id) { this.simpanCatatan(); this.ubahCatatan = id; },
            simpanCatatan() {
                if (this.ubahCatatan === null) return;
                const id = this.ubahCatatan;
                const teks = (this.catatan[id] || '').trim().slice(0, 150);
                if (teks) this.catatan = { ...this.catatan, [id]: teks }; else this.hapusCatatan(id);
                this.ubahCatatan = null;
            },
            hapusCatatan(id) {
                const c = { ...this.catatan };
                delete c[id];
                this.catatan = c;
                if (this.ubahCatatan === +id) this.ubahCatatan = null;
            },
            get totalItem() { return this.daftar.reduce((a, b) => a + b.qty, 0); },
            get total() { return this.daftar.reduce((a, b) => a + b.subtotal, 0); },
            rupiah(n) { return 'Rp' + n.toLocaleString('id-ID'); },

            lihatFoto(data) {
                this.zoom = false;
                this.foto = data;
                this.$nextTick(() => this.$refs.tutupFoto?.focus());
            },
            tutupFoto() { this.foto = null; this.zoom = false; },
            // Titik yang diketuk menjadi pusat pembesaran; saat diperbesar, gerakan tetikus menggeser foto
            geserFoto(e) {
                // offsetX/Y memakai ukuran asli foto (sebelum diperbesar), jadi titiknya tetap tepat saat foto sudah membesar
                const el = e.currentTarget;
                const x = Math.min(100, Math.max(0, (e.offsetX / el.offsetWidth) * 100));
                const y = Math.min(100, Math.max(0, (e.offsetY / el.offsetHeight) * 100));
                this.asalZoom = `${x}% ${y}%`;
            },
            ketukFoto(e) {
                if (!this.zoom) this.geserFoto(e);
                this.zoom = !this.zoom;
            },
            bukaPanel() { this.langkah = 1; this.panel = true; },
            tutupPanel() { if (!this.proses) { this.simpanCatatan(); this.panel = false; } },
            lanjut() {
                this.simpanCatatan();
                if (this.jenis === 'menginap' && !this.kamarDipilih) {
                    // Tamu menginap tanpa kode kamar: tawarkan masuk supaya kamarnya tampil sendiri
                    if (cfg.tamu && !this.kamarList.length) return this.$dispatch('tawar-akun', { varian: 'kamar' });
                    this.galat = { ...this.galat, kamar: this.kamarList.length ? 'Pilih kamar Anda.' : 'Cocokkan kode kamar dulu.' };
                    return;
                }
                if (!this.isi.serving_type) {
                    this.galat = { ...this.galat, serving_type: 'Pilih salah satu.' };
                    return;
                }
                // Tamu belum masuk: tawarkan masuk/daftar; pilihan tanpa akun diingat x-tawaran-akun selama sesi peramban
                if (cfg.tamu) return this.$dispatch('tawar-akun', { varian: this.jenis === 'luar' ? 'luar' : 'menginap' });
                this.keLangkah2();
            },
            lanjutTanpaAkun(varian) {
                if (varian === 'kamar') {
                    // Tetap tanpa akun: arahkan ke isian kode kamar
                    this.galat = { ...this.galat, kamar: 'Cocokkan kode kamar dulu.' };
                    this.$nextTick(() => this.$refs.panel.querySelector('[data-isian="kamar"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
                    return;
                }
                this.keLangkah2();
            },
            keLangkah2() {
                this.langkah = 2;
                this.$nextTick(() => this.$refs.panel.querySelector(`[data-isian="${this.isi.name ? 'phone' : 'name'}"]`)?.focus());
            },

            async bayar() {
                if (this.proses) return;
                const g = {};
                if (this.jenis === 'luar') {
                    const nama = this.isi.name.trim();
                    if (!nama) g.name = 'Wajib diisi.'; else if (nama.length < 3) g.name = 'Minimal 3 huruf.';
                    if (!this.isi.phone.trim()) g.phone = 'Wajib diisi.';
                }
                if (this.perluBayar && !this.isi.metode) g.metode = 'Pilih metode pembayaran.';
                this.galat = g;
                if (Object.keys(g).length) {
                    this.$refs.panel.querySelector(`[data-isian="${Object.keys(g)[0]}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                if (this.perluBayar && !cfg.bayarOnline) return Alpine.store('toasts').tampil('Pembayaran online belum tersedia. Silakan pesan langsung di restoran.', '#E8AC45');

                const gagal = (teks) => Alpine.store('toasts').tampil(teks, '#D4514A');
                this.proses = true;
                try {
                    const res = await fetch(cfg.urlPesan, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({
                            ...(this.jenis === 'luar' ? this.isi : {
                                serving_type: this.isi.serving_type, note: this.isi.note, metode: this.tagih === 'sekarang' ? this.isi.metode : null,
                                reservation_room_id: this.kamarId, tagih: this.tagih,
                            }),
                            items: this.daftar.map((b) => ({ id: b.id, qty: b.qty, note: b.note || null })),
                        }),
                    });
                    const data = await res.json().catch(() => ({}));

                    if (res.ok || data.status_url) {
                        // Pesanan sudah tersimpan di server; keranjang dikosongkan saat pindah ke halaman status
                        const keStatus = () => {
                            this.keranjang = {}; this.catatan = {};
                            simpanan.tulis({}); simpananCatatan.tulis({});
                            if (this.perluBayar) { try { localStorage.setItem('wt.metode-bayar', this.isi.metode); } catch { /* mode privat */ } }
                            window.location.href = data.status_url;
                        };
                        // Halaman pesanan langsung menampilkan nomor VA atau QRIS untuk metode yang dipilih
                        if (data.pesan) gagal(data.pesan);
                        setTimeout(keStatus, data.pesan ? 1200 : 0);
                        return;
                    }
                    if (res.status === 422 && data.errors) {
                        const pesan = {};
                        for (const [k, v] of Object.entries(data.errors)) pesan[k.split('.')[0]] ??= v[0];
                        if (pesan.items) gagal(pesan.items);
                        if (pesan.serving_type || pesan.kamar) this.langkah = 1;
                        if (pesan.kamar) { this.kamarId = null; this.kamarList = []; }
                        this.galat = pesan;
                    } else if (res.status === 422 && data.hapus) {
                        const k = { ...this.keranjang };
                        data.hapus.forEach((id) => { delete k[id]; this.hapusCatatan(id); });
                        this.keranjang = k;
                        this.langkah = 1;
                        gagal(data.pesan);
                    } else if (res.status === 419) {
                        gagal('Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.');
                    } else if (res.status === 429) {
                        gagal('Terlalu banyak percobaan. Tunggu sebentar, lalu coba lagi.');
                    } else {
                        gagal(data.pesan || 'Terjadi kesalahan. Silakan coba lagi.');
                    }
                } catch {
                    gagal('Tidak dapat terhubung ke server. Periksa koneksi internet Anda, lalu coba lagi.');
                }
                this.proses = false;
            },

            get kata() { return this.cari.trim().toLowerCase(); },
            cocok(nama) { return !this.kata || nama.includes(this.kata); },
            jumlahCocok(g) { return g.menu.filter((n) => this.cocok(n)).length; },
            get grupTampil() { return this.grup.filter((g) => this.jumlahCocok(g) > 0); },
            get totalCocok() { return this.grup.reduce((a, g) => a + this.jumlahCocok(g), 0); },

            init() {
                // Muat keranjang tersimpan; menu yang sudah tidak tersedia dibuang
                this.keranjang = Object.fromEntries(Object.entries(simpanan.baca())
                    .filter(([id, qty]) => katalog[id] && Number.isInteger(qty) && qty > 0).map(([id, qty]) => [id, Math.min(qty, 20)]));
                this.$watch('keranjang', (v) => simpanan.tulis(v));
                // Catatan hanya dipertahankan untuk menu yang masih ada di keranjang
                this.catatan = Object.fromEntries(Object.entries(simpananCatatan.baca())
                    .filter(([id, teks]) => this.keranjang[id] && typeof teks === 'string' && teks.trim()).map(([id, teks]) => [id, teks.trim().slice(0, 150)]));
                this.$watch('catatan', (v) => simpananCatatan.tulis(v));

                // Kembali dari masuk/daftar di tengah memesan: buka lagi panel pesanan, lalu rapikan alamat
                const alamat = new URL(location.href);
                if (alamat.searchParams.has('lanjut')) {
                    alamat.searchParams.delete('lanjut');
                    history.replaceState(null, '', alamat);
                    if (Object.keys(this.keranjang).length) this.$nextTick(() => this.bukaPanel());
                }

                // Kategori aktif = kelompok terakhir yang judulnya sudah melewati bagian atas layar
                // (di bawah navbar, dan di bawah bilah tab pada layar kecil)
                let menunggu = false;
                const perbarui = () => {
                    menunggu = false;
                    if (Date.now() < this.kunciTab) return; // sedang bergulir karena klik kategori
                    const batas = window.innerWidth >= 1280 ? 180 : 220;
                    let slug = null;
                    document.querySelectorAll('[data-grup]').forEach((el) => {
                        if (el.offsetParent !== null && el.getBoundingClientRect().top <= batas) slug = el.dataset.grup;
                    });
                    slug ??= [...document.querySelectorAll('[data-grup]')].find((el) => el.offsetParent !== null)?.dataset.grup;
                    if (slug && slug !== this.aktif) this.tandai(slug);
                };
                window.addEventListener('scroll', () => { if (!menunggu) { menunggu = true; requestAnimationFrame(perbarui); } }, { passive: true });
                // Tunggu satu frame agar semua x-show selesai diterapkan sebelum posisi diukur
                this.$watch('cari', () => requestAnimationFrame(() => {
                    const bilah = this.$refs.tab.closest('.sticky').getBoundingClientRect().bottom;
                    const atas = this.$refs.daftarMenu.getBoundingClientRect().top;
                    if (atas < bilah) window.scrollTo({ top: window.scrollY + atas - bilah, behavior: 'instant' });
                    perbarui();
                }));
                this.$nextTick(perbarui);
            },
            tandai(slug) {
                this.aktif = slug;
                this.$refs.tab.querySelector(`[data-tab="${slug}"]`)?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
            },
            lompat(slug) {
                this.tandai(slug);
                this.kunciTab = Date.now() + 900;
                document.getElementById('grup-' + slug)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        }));
    });
</script>
@endpush
</x-layouts.guest>
