@php
    $tanyaWa = 'https://wa.me/'.($wisma['whatsapp'] ?? '').'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang sewa aula.');
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $tombolUtama = 'inline-flex h-12 w-full max-w-[260px] items-center justify-center rounded-sm bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline sm:w-auto';
@endphp

{{-- Informasi aula. Formulir pengajuan ada di halaman sendiri (aula.ajukan), dibuka lewat tombol. --}}
<x-layouts.guest title="Sewa aula">
<div class="animate-wt-masuk">
    <x-tamu.kepala-halaman judul="Sewa Aula" gambar="ds/img/aula.jpg"
                           :teks="'Ruang serbaguna untuk rapat, pelatihan, syukuran, dan acara keluarga di pusat kota Malili. Sewa sehari penuh mulai '.$rp($tarif['min']).', sudah termasuk AC, sound system, proyektor, kursi, dan meja.'">
        <a href="{{ route('aula.ajukan') }}" class="{{ $tombolUtama }}">Ajukan sewa</a>
        <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm border border-[rgba(242,235,220,.38)] px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline sm:w-auto">
            <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Tanya dulu
        </a>
    </x-tamu.kepala-halaman>

    <div class="wt-container max-w-[1120px] py-10 sm:py-14">
        <div class="flex flex-col gap-6">
            @foreach ($daftarAula as $a)
                <div class="wt-card grid overflow-hidden md:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                    {{-- Semua foto aula bisa digeser dan dibuka layar penuh; tanpa foto unggahan memakai gambar gedung --}}
                    @if ($a->photoUrls())
                        <x-tamu.galeri :tipe="$a" layar-penuh otomatis class="h-[260px] md:h-auto md:min-h-[380px]" data-galeri-aula />
                    @else
                        <img src="{{ $a->fotoUtama() }}" alt="{{ $a->name }}" class="h-[240px] w-full object-cover md:h-full">
                    @endif
                    <div class="flex flex-col p-6">
                        <div class="wt-eyebrow">Aula</div>
                        <h2 class="mb-0 mt-1.5 font-display text-[26px] font-extrabold uppercase tracking-[.03em]">{{ $a->name }}</h2>
                        <p class="mb-0 mt-2 text-sm leading-relaxed text-ink-500">{{ $a->description }}</p>
                        {{-- Kotak promosi: kapasitas dari Hall::KAPASITAS_PROMOSI (batas pemesanan tetap kolom capacity), harga mulai dari Pengaturan --}}
                        <dl class="m-0 mt-5 grid gap-3 sm:grid-cols-2" data-tarif-aula>
                            <div class="rounded-md bg-brown-900 px-4 py-3.5 text-cream-150" data-kapasitas-aula>
                                <dt class="font-display text-[11px] font-bold uppercase tracking-[.16em] text-sand-300">Kapasitas</dt>
                                <dd class="m-0 mt-1.5 font-display text-[26px] leading-none font-extrabold text-cream-50">{{ \App\Models\Hall::KAPASITAS_PROMOSI }} <span class="text-base font-bold">orang</span></dd>
                                <dd class="m-0 mt-2 text-xs text-[rgba(242,235,220,.75)]">{{ \App\Models\Hall::ACARA_PROMOSI }}</dd>
                            </div>
                            <div class="rounded-md border border-[rgba(181,113,74,.3)] bg-[rgba(181,113,74,.08)] px-4 py-3.5" data-harga-aula>
                                <dt class="font-display text-[11px] font-bold uppercase tracking-[.16em] text-clay-600">Mulai</dt>
                                <dd class="m-0 mt-1.5 font-display text-[26px] leading-none font-extrabold tabular-nums text-clay-500">{{ $rp($tarif['min']) }}</dd>
                                <dd class="m-0 mt-2 text-xs text-ink-500">/ hari · {{ \App\Models\Hall::KETERANGAN_HARGA }}</dd>
                            </div>
                        </dl>
                        <div class="mt-5 wt-micro-label">Sudah termasuk</div>
                        <ul class="m-0 mt-2 grid list-none gap-1.5 p-0 text-sm sm:grid-cols-2" data-termasuk-aula>
                            @foreach (\App\Models\Hall::TERMASUK as $f)
                                <li class="flex items-center gap-2"><svg class="flex-none text-status-available" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>{{ $f }}</li>
                            @endforeach
                        </ul>
                        <p class="mb-0 mt-3 text-xs text-ink-500">{{ \App\Models\Hall::CATATAN_HARGA }} Harga akhir disesuaikan dengan kebutuhan acara dan disepakati bersama staf kami.</p>
                        <a href="{{ route('aula.ajukan') }}" class="wt-btn-primary mt-6 h-12 w-full text-xs tracking-[.1em] sm:w-auto sm:self-start sm:px-8">Ajukan sewa</a>
                    </div>
                </div>
            @endforeach

            {{-- Cara sewa: tiga langkah berurutan. Garis penghubung vertikal di kiri ikon (ponsel), putus-putus dengan panah (md ke atas). --}}
            <section class="mt-4" aria-labelledby="judul-cara-sewa" data-cara-sewa>
                <div class="wt-eyebrow">Cara sewa aula</div>
                <h2 id="judul-cara-sewa" class="mb-0 mt-1.5 font-display text-[22px] font-extrabold uppercase leading-tight tracking-[.03em] text-balance sm:text-[26px]">3 langkah mudah, tanpa bayar di muka</h2>
                <ol class="m-0 mt-6 grid list-none p-0 md:grid-cols-3 md:gap-4">
                    @foreach ([
                        ['kalender', 'Isi formulir', 'Pilih tanggal, jam, dan jumlah peserta. Gratis, tanpa bayar di muka.'],
                        ['whatsapp', 'Kami hubungi Anda', 'Staf menghubungi lewat WhatsApp untuk membahas kebutuhan acara dan harga.'],
                        ['cek', 'Jadwal dikunci', 'Setelah harga disepakati, nota dan konfirmasi jadwal dikirim ke WhatsApp Anda.'],
                    ] as $i => [$ikon, $judul, $isi])
                        <li class="relative flex gap-4 pb-7 last:pb-0 md:flex-col md:gap-0 md:pb-0" data-langkah="{{ $i + 1 }}">
                            @unless ($loop->last)
                                <span class="absolute bottom-0 left-6 top-12 border-l-2 border-dashed border-cream-400 md:hidden" aria-hidden="true"></span>
                                <span class="absolute left-[calc(3rem+10px)] right-[-2px] top-6 hidden border-t-2 border-dashed border-cream-400 md:block" aria-hidden="true"></span>
                                <x-tamu.ikon nama="kanan" ukuran="16" class="absolute -right-3.5 top-4 hidden text-cream-400 md:block" />
                            @endunless
                            <span class="relative z-[1] flex h-12 w-12 flex-none items-center justify-center rounded-full bg-brown-800 text-cream-50">
                                @if ($ikon === 'whatsapp')
                                    <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="20" height="20" class="brightness-0 invert">
                                @else
                                    <x-tamu.ikon :nama="$ikon" ukuran="20" />
                                @endif
                            </span>
                            <div class="min-w-0 pt-0.5 md:mt-4 md:pr-6 md:pt-0">
                                <div class="font-display text-[11px] font-bold uppercase tracking-[.16em] text-clay-600">Langkah {{ $i + 1 }}</div>
                                <div class="mt-1 font-display text-[15px] font-bold">{{ $judul }}</div>
                                <p class="mb-0 mt-1 text-[13px] leading-relaxed text-ink-500">{{ $isi }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3">
                    {{-- Formulir ada di halaman sendiri; anchor #ajukan-aula langsung ke bagian formulirnya --}}
                    <a href="{{ route('aula.ajukan') }}#ajukan-aula" class="wt-btn-primary h-12 px-8 text-xs tracking-[.1em] max-sm:w-full" data-mulai-ajukan>Mulai ajukan sewa</a>
                    <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-[13px] font-semibold text-olive-600">
                        <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16">Ada pertanyaan? Tanya via WhatsApp
                    </a>
                </div>
            </section>
        </div>
    </div>
</div>
</x-layouts.guest>
