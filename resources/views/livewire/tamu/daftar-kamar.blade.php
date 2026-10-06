@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $wa = 'https://wa.me/'.$wisma['whatsapp'].'?text='.rawurlencode('Halo Wisma Trans, saya ingin menanyakan ketersediaan kamar'.($cari->aktif() ? ' untuk '.$cari->rentang() : '').'.');
    $jumlahKamar = $tipe->sum(fn ($t) => $t->rooms->count());
@endphp

<div class="animate-wt-masuk"
     x-data="cariKamar(@js(['hariIni' => today()->toDateString(), 'masuk' => $cari->masuk?->toDateString(), 'keluar' => $cari->keluar?->toDateString(), 'kamar' => $cari->kamar, 'maksTamu' => $maksTamu]))"
     @resize.window.debounce.150ms="lebar = window.innerWidth">

    {{-- Kepala halaman --}}
    <section class="relative isolate overflow-hidden bg-brown-900">
        <img src="{{ asset('ds/img/kamar-hotel.jpg') }}" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover opacity-40">
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
        <form @submit.prevent="cek()" novalidate
              class="flex flex-wrap items-stretch rounded-md border border-cream-300 bg-cream-50 p-2 shadow-wt-lg">
            <x-tamu.rentang-tanggal class="flex-[2_1_400px]" />
            {{-- Kamar dan tamu: sama seperti di beranda --}}
            <div class="relative min-w-[210px] flex-1 px-5 py-3 md:border-l md:border-cream-300" @click.outside="buka = false" @click="kalender = false">
                <span class="wt-micro-label mb-1.5 block">Kamar dan tamu</span>
                <button type="button" @click="buka = !buka" :aria-expanded="buka"
                        class="flex h-[42px] w-full items-center justify-between gap-2 rounded border border-cream-300 bg-cream-50 px-3 text-left text-[15px] text-ink-900">
                    <span class="min-w-0 truncate" x-text="ringkasTombol" :title="ringkas">{{ $cari->ringkasTamu() }}</span>
                    <img src="{{ asset('ds/icons/chevron-up.svg') }}" alt="" width="14" height="14" class="flex-none opacity-60 transition-transform" :class="buka ? '' : 'rotate-180'">
                </button>
                <x-tamu.pilih-tamu />
            </div>
            <div class="flex min-w-[190px] flex-col justify-center py-2 pl-5 pr-2 max-md:w-full max-md:pl-2 md:border-l md:border-cream-300">
                <button type="submit" wire:loading.attr="disabled" wire:target="cek" class="wt-btn-primary h-[52px] w-full px-6 text-xs tracking-[.1em] disabled:cursor-wait disabled:opacity-80">
                    <span wire:loading.remove wire:target="cek">Cek ketersediaan</span>
                    <span wire:loading wire:target="cek">Mengecek…</span>
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
                        {{ $cari->masuk->translatedFormat('D, j M') }} – {{ $cari->keluar->translatedFormat('D, j M Y') }} · {{ $cari->malam() }} malam · {{ $cari->ringkasTamu() }}
                    @else
                        Pilih tanggal menginap untuk melihat ketersediaan dan total harga.
                    @endif
                </p>
            </div>
            <span class="text-xs text-ink-500">Harga per kamar per malam</span>
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

        @if ($tidakMuat)
            <div class="mb-5 rounded-md border border-cream-300 bg-cream-200 px-5 py-5 sm:px-6" wire:loading.remove wire:target="cek">
                <p class="m-0 font-semibold text-ink-900">Tidak ada kamar yang tersedia untuk {{ $cari->tamuTerbanyak() }} orang dalam satu kamar.</p>
                <p class="mb-0 mt-1 text-sm text-ink-500">Bagi tamu ke lebih banyak kamar, coba tanggal lain, atau hubungi kami.</p>
                <div class="mt-4 flex flex-wrap gap-2.5">
                    <button type="button" @click="bukaTamu()" class="wt-btn-primary h-10 px-5 text-xs tracking-[.08em]">Ubah susunan tamu</button>
                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="wt-btn-outline h-10 gap-2 px-5 text-xs tracking-[.08em]">
                        <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16">Hubungi via WhatsApp
                    </a>
                </div>
            </div>
        @endif

        @if ($kombinasi && $kombinasi['lengkap'])
            {{-- Rekomendasi kombinasi untuk pesanan beberapa kamar --}}
            <section class="relative mb-5 overflow-hidden rounded-xl border border-[rgba(181,113,74,.35)] bg-cream-50 shadow-wt-xs" wire:loading.remove wire:target="cek">
                <span class="absolute inset-y-0 left-0 w-1 bg-clay-500" aria-hidden="true"></span>
                <div class="flex flex-wrap items-start justify-between gap-4 p-5 sm:p-6">
                    <div class="min-w-0">
                        <div class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(181,113,74,.1)] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[.1em] text-clay-600">
                            <img src="{{ asset('ds/icons/sparkles.svg') }}" alt="" width="12" height="12">Rekomendasi untuk {{ $cari->jumlah }} kamar
                        </div>
                        <h3 class="mb-0 mt-2.5 font-display text-lg font-extrabold uppercase tracking-[.03em]">
                            {{ collect($kombinasi['kelompok'])->map(fn ($k) => $k['jumlah'].' '.$k['tipe']->name)->join(' + ') }}
                        </h3>
                        <p class="mb-0 mt-1 text-sm text-ink-500">Kombinasi termurah yang muat untuk semua tamu dan masih tersedia di tanggal ini.</p>
                        <ul class="m-0 mt-3.5 flex list-none flex-wrap gap-2 p-0">
                            @foreach ($kombinasi['kamar'] as $k)
                                <li class="inline-flex items-center gap-2 rounded-lg border border-cream-300 bg-cream-100 px-3 py-1.5 text-xs">
                                    <span class="font-semibold text-ink-900">Kamar {{ $k['no'] }}</span>
                                    <span class="text-ink-500">{{ $k['orang'] }} orang · {{ $k['tipe']->name }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="flex flex-none flex-col items-start sm:items-end">
                        <span class="text-xs text-ink-500">Total {{ $cari->malam() }} malam</span>
                        <span class="font-display text-[26px] font-extrabold leading-tight tabular-nums text-ink-900">{{ $rp($kombinasi['total']) }}</span>
                        <a href="{{ route('kamar.pesan', Str::lower($kombinasi['kamar'][0]['tipe']->code)).'?'.http_build_query($cari->query() + ['pilihan' => collect($kombinasi['kamar'])->map(fn ($k) => $k['tipe']->code)->join(',')]) }}"
                           class="wt-btn-primary mt-3 h-11 px-6 text-xs tracking-[.08em]">Pesan kombinasi ini</a>
                    </div>
                </div>
            </section>
        @elseif ($kombinasi && ! $semuaPenuh && ! $tidakMuat)
            {{-- Sisa kamar semua tipe tidak cukup untuk rombongan --}}
            <div class="mb-5 rounded-md border border-cream-300 bg-cream-200 px-5 py-5 sm:px-6" wire:loading.remove wire:target="cek">
                <p class="m-0 font-semibold text-ink-900">Kamar yang tersedia belum cukup untuk {{ $cari->jumlah }} kamar.</p>
                <p class="mb-0 mt-1 text-sm text-ink-500">Paling banyak {{ $cari->jumlah - $kombinasi['kurang'] }} kamar bisa dipesan untuk susunan tamu ini. Coba tanggal lain, kurangi kamar, atau ajukan pemesanan rombongan.</p>
                <div class="mt-4 flex flex-wrap gap-2.5">
                    <a href="{{ route('instansi', array_filter(['masuk' => $cari->masuk?->toDateString(), 'keluar' => $cari->keluar?->toDateString(), 'kamar' => $cari->jumlah])) }}" class="wt-btn-primary h-10 px-5 text-xs tracking-[.08em]">Ajukan pemesanan rombongan</a>
                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="wt-btn-outline h-10 gap-2 px-5 text-xs tracking-[.08em]">
                        <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16">Hubungi via WhatsApp
                    </a>
                </div>
            </div>
        @endif

        @if ($cari->aktif() && $cari->jumlah >= 5 && ! ($kombinasi && ! $kombinasi['lengkap']))
            <p class="mb-5 mt-0 flex items-start gap-2.5 rounded-md bg-cream-200 px-4 py-3 text-[13px] text-ink-900" wire:loading.remove wire:target="cek">
                <x-tamu.ikon nama="gedung" ukuran="17" class="mt-px text-clay-600" />
                <span>Memesan {{ $cari->jumlah }} kamar untuk rombongan atau instansi? <a href="{{ route('instansi', array_filter(['masuk' => $cari->masuk?->toDateString(), 'keluar' => $cari->keluar?->toDateString(), 'kamar' => $cari->jumlah])) }}" class="font-semibold text-clay-600">Ajukan pemesanan instansi</a> tanpa uang muka online.</span>
            </p>
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

        {{-- Kartu tipe kamar --}}
        <div wire:loading.remove wire:target="cek" class="flex flex-col gap-5">
            @forelse ($tipe as $t)
                @php
                    $s = $status[$t->id] ?? null;
                    $penuh = $s && $s['jenis'] === 'penuh';
                    $tidakMuatIni = $s && $s['jenis'] === 'tidak-muat';
                    $sebagian = $s && $s['jenis'] === 'sebagian';
                    $urlDetail = route('kamar.tipe', Str::lower($t->code)) . ($cari->aktif() ? '?'.http_build_query($cari->query()) : '');
                    $urlPesan = $s && ! $penuh && ! $tidakMuatIni ? route('kamar.pesan', Str::lower($t->code)).'?'.http_build_query($cari->query($s['kamarDipesan'])) : null;
                    $fasilitas = $t->facilities;
                @endphp
                <article wire:key="tipe-{{ $t->id }}" @if ($tidakMuatIni) x-show="lihatSemua" x-cloak @endif
                         @class(['grid overflow-hidden rounded-lg border border-cream-300 bg-cream-50 shadow-wt-xs transition lg:grid-cols-[minmax(0,360px)_minmax(0,1fr)]',
                             'hover:shadow-wt-md' => ! $penuh])>
                    <x-tamu.galeri :tipe="$t" :tautan="$urlDetail" :redup="$penuh" :class="Arr::toCssClasses(['aspect-[16/10] lg:aspect-auto lg:min-h-[250px]', 'opacity-60' => $penuh])" />

                    <div class="flex min-w-0 flex-col p-5 sm:p-6 lg:flex-row lg:gap-7">
                        <div @class(['min-w-0 flex-1', 'opacity-60' => $penuh])>
                            <h3 class="m-0 font-display text-lg font-extrabold uppercase leading-snug tracking-[.04em]">
                                <a href="{{ $urlDetail }}" class="text-ink-900 hover:text-clay-600 hover:no-underline">{{ $t->name }}</a>
                            </h3>
                            @if ($t->description)
                                <p class="mb-0 mt-1.5 line-clamp-2 text-sm leading-relaxed text-ink-500">{{ $t->description }}</p>
                            @endif
                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px] text-ink-900">
                                <span class="inline-flex items-center gap-1.5"><x-tamu.ikon nama="orang" ukuran="16" class="text-clay-600" />Maks. {{ $t->max_capacity }} orang</span>
                                @if ($t->kasur)
                                    <span class="inline-flex items-center gap-1.5"><x-tamu.ikon nama="kasur" ukuran="16" class="text-clay-600" />Kasur {{ implode(' atau ', $t->kasur) }}</span>
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
                        </div>

                        {{-- Harga dan ketersediaan --}}
                        <div class="mt-5 flex flex-col border-t border-cream-300 pt-4 lg:mt-0 lg:w-[236px] lg:flex-none lg:border-l lg:border-t-0 lg:pl-7 lg:pt-0">
                            @if (! $s)
                                <span class="wt-micro-label">Mulai dari</span>
                                <div class="mt-1 flex items-baseline gap-1.5">
                                    <span class="font-display text-[22px] font-extrabold tabular-nums text-clay-600">{{ $rp($t->base_price) }}</span>
                                    <span class="text-xs text-ink-500">/ malam</span>
                                </div>
                                <span class="mt-1 text-xs text-ink-500">Pilih tanggal untuk cek ketersediaan</span>
                                <a href="{{ $urlDetail }}" class="wt-btn-outline mt-4 h-11 w-full text-xs tracking-[.08em] lg:mt-auto">Lihat detail</a>
                            @elseif ($tidakMuatIni)
                                <span class="inline-flex items-center gap-1.5 self-start rounded-full bg-cream-200 px-2.5 py-1 text-xs font-semibold text-ink-900">
                                    <x-tamu.ikon nama="orang" ukuran="14" class="text-clay-600" />Maks. {{ $t->max_capacity }} orang per kamar
                                </span>
                                <p class="mb-0 mt-3 text-[13px] leading-relaxed text-ink-500">Tidak muat untuk {{ $cari->tamuTerbanyak() }} orang dalam satu kamar. Tambah kamar untuk membagi tamu.</p>
                                <div class="mt-4 flex gap-2 lg:mt-auto lg:flex-col lg:pt-4">
                                    <button type="button" @click="bukaTamu()" class="wt-btn-primary h-11 flex-1 px-4 text-xs tracking-[.08em] lg:w-full lg:flex-none">Ubah tamu</button>
                                    <a href="{{ $urlDetail }}" class="wt-btn-outline h-11 flex-1 px-4 text-xs tracking-[.08em] lg:w-full lg:flex-none">Lihat detail</a>
                                </div>
                            @elseif ($penuh)
                                <span class="inline-flex items-center gap-1.5 self-start rounded-full bg-[rgba(212,81,74,.1)] px-2.5 py-1 text-xs font-semibold text-status-dirty">
                                    <span class="h-1.5 w-1.5 rounded-full bg-status-dirty"></span>Penuh untuk tanggal ini
                                </span>
                                <div class="mt-3 flex items-baseline gap-1.5 text-ink-500">
                                    <span class="font-display text-lg font-bold tabular-nums">{{ $rp($t->base_price) }}</span><span class="text-xs">/ malam</span>
                                </div>
                                <a href="{{ $urlDetail }}" class="wt-btn-outline mt-4 h-11 w-full text-xs tracking-[.08em] lg:mt-auto">Lihat detail</a>
                            @elseif ($sebagian)
                                @php
                                    $tidakMuatDi = collect($cari->penghuni())->filter(fn ($o) => $o > $t->max_capacity);
                                @endphp
                                <span class="inline-flex items-center gap-1.5 self-start rounded-full bg-[rgba(232,172,69,.18)] px-2.5 py-1 text-xs font-semibold text-[#8A5A0B]">
                                    <span class="h-1.5 w-1.5 flex-none rounded-full bg-status-maintenance"></span>Muat untuk {{ $s['muat'] }} dari {{ $cari->jumlah }} kamar
                                </span>
                                <p class="mb-0 mt-2.5 text-xs leading-relaxed text-ink-500">
                                    Kamar {{ $tidakMuatDi->keys()->map(fn ($i) => $i + 1)->join(', ', ' dan ') }} ({{ $tidakMuatDi->max() }} orang) butuh tipe berkapasitas lebih besar.
                                </p>
                                <div class="mt-2.5 font-display text-[22px] font-extrabold tabular-nums text-ink-900">{{ $rp($s['total']) }}</div>
                                <div class="text-xs text-ink-500">untuk {{ $s['dipesan'] }} kamar · {{ $cari->malam() }} malam</div>
                                <div class="mt-4 flex gap-2 lg:mt-auto lg:flex-col lg:pt-4">
                                    <a href="{{ $urlPesan }}" class="wt-btn-primary h-11 flex-1 whitespace-nowrap px-4 text-xs tracking-[.08em] lg:w-full lg:flex-none">Pesan {{ $s['dipesan'] }} kamar</a>
                                    <a href="{{ $urlDetail }}" class="wt-btn-outline h-11 flex-1 px-4 text-xs tracking-[.08em] lg:w-full lg:flex-none">Lihat detail</a>
                                </div>
                            @else
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($s['jenis'] === 'kurang')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(232,172,69,.18)] px-2.5 py-1 text-xs font-semibold text-[#8A5A0B]">
                                            <span class="h-1.5 w-1.5 flex-none rounded-full bg-status-maintenance"></span>Hanya tersedia {{ $s['sisa'] }} kamar untuk tanggal ini
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[rgba(92,158,103,.14)] px-2.5 py-1 text-xs font-semibold text-status-available">
                                            <span class="h-1.5 w-1.5 rounded-full bg-status-available"></span>Tersedia
                                        </span>
                                        @if ($s['sisa'] <= 2)
                                            <span class="inline-flex items-center rounded-full bg-[rgba(232,172,69,.18)] px-2.5 py-1 text-xs font-semibold text-[#8A5A0B]">Tersisa {{ $s['sisa'] }} kamar</span>
                                        @endif
                                    @endif
                                </div>
                                <div class="mt-3 font-display text-[22px] font-extrabold tabular-nums text-ink-900">{{ $rp($s['total']) }}</div>
                                <div class="text-xs text-ink-500">untuk {{ $s['dipesan'] }} kamar · {{ $cari->malam() }} malam</div>
                                <div class="mt-0.5 text-xs tabular-nums text-ink-500">{{ $rp($t->base_price) }} / kamar / malam</div>
                                <div class="mt-4 flex gap-2 lg:mt-auto lg:flex-col lg:pt-4">
                                    <a href="{{ $urlPesan }}" class="wt-btn-primary h-11 flex-1 whitespace-nowrap px-4 text-xs tracking-[.08em] lg:w-full lg:flex-none">
                                        {{ $s['jenis'] === 'kurang' ? 'Pesan '.$s['dipesan'].' kamar' : 'Pesan' }}
                                    </a>
                                    <a href="{{ $urlDetail }}" class="wt-btn-outline h-11 flex-1 px-4 text-xs tracking-[.08em] lg:w-full lg:flex-none">Lihat detail</a>
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-md border border-cream-300 bg-cream-50 px-6 py-12 text-center text-ink-500">Belum ada kamar yang dapat dipesan online. Silakan hubungi kami.</div>
            @endforelse

            @if ($tersembunyi)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed border-cream-400 px-4 py-3 text-[13px] text-ink-500">
                    <span class="inline-flex items-center gap-2">
                        <x-tamu.ikon nama="orang" ukuran="16" class="text-clay-600" />
                        <span x-text="lihatSemua ? '{{ $tersembunyi }} tipe di atas tidak muat untuk {{ $cari->tamuTerbanyak() }} orang dalam satu kamar.' : '{{ $tersembunyi }} tipe kamar disembunyikan karena tidak muat untuk {{ $cari->tamuTerbanyak() }} orang dalam satu kamar.'"></span>
                    </span>
                    <button type="button" @click="lihatSemua = !lihatSemua" class="h-8 rounded border border-cream-400 bg-cream-50 px-3.5 text-xs font-semibold text-brown-800 hover:bg-cream-200"
                            x-text="lihatSemua ? 'Sembunyikan' : 'Tampilkan'">Tampilkan</button>
                </div>
            @endif
        </div>

        {{-- Informasi dan jalur instansi --}}
        <div class="mt-10 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="rounded-lg border border-cream-300 bg-cream-50 p-5 sm:p-6">
                <h2 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.1em] text-ink-900">Sebelum memesan</h2>
                <ul class="m-0 mt-3 flex list-none flex-col gap-2.5 p-0 text-sm text-ink-900">
                    <li class="flex gap-2.5"><x-tamu.ikon nama="jam" ukuran="18" class="mt-px text-clay-600" />Check-in mulai {{ $ketentuan['checkIn'] }} WITA, check-out paling lambat {{ $ketentuan['checkOut'] }} WITA.</li>
                    <li class="flex gap-2.5"><x-tamu.ikon nama="dompet" ukuran="18" class="mt-px text-clay-600" />Uang muka {{ $ketentuan['dpPersen'] }}% dibayar online dalam {{ $ketentuan['dpJam'] }} jam, sisanya saat check-in.</li>
                    <li class="flex gap-2.5"><x-tamu.ikon nama="kasur" ukuran="18" class="mt-px text-clay-600" />Nomor kamar ditentukan resepsionis saat check-in.</li>
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

    @include('livewire.tamu.skrip-cari-kamar')
</div>
