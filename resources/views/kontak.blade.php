@php
    use App\Models\Setting;

    $wa = fn (string $teks) => 'https://wa.me/'.($wisma['whatsapp'] ?? '').'?text='.rawurlencode($teks);
    $jam = fn (string $kunci, string $bawaan) => str_replace(':', '.', substr((string) Setting::get($kunci, $bawaan), 0, 5));
    $topik = [
        ['Reservasi kamar', 'Halo Wisma Trans, saya ingin bertanya tentang reservasi kamar.'],
        ['Sewa aula', 'Halo Wisma Trans, saya ingin bertanya tentang sewa aula.'],
        ['Restoran', 'Halo Wisma Trans, saya ingin bertanya tentang menu restoran.'],
        ['Laundry', 'Halo Wisma Trans, saya ingin bertanya tentang layanan laundry.'],
        ['Instansi / rombongan', 'Halo Wisma Trans, saya ingin bertanya tentang reservasi untuk instansi.'],
        ['Lainnya', 'Halo Wisma Trans, saya ingin bertanya.'],
    ];
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $hargaLaundry = \App\Models\Service::where('category', 'laundry')->where('is_active', true)->value('price');
    // [kategori, pertanyaan, jawaban]; angka aturan dibaca dari pengaturan
    $tanya = [
        ['Menginap', 'Jam berapa check-in dan check-out?', 'Check-in mulai pukul '.$jam('check_in_time', '14:00').' WITA, check-out paling lambat pukul '.$jam('check_out_time', '12:00').' WITA. Terlambat keluar dikenai biaya '.$rp(Setting::get('late_checkout_fee', 250000)).'.'],
        ['Pembayaran', 'Berapa uang muka reservasi online?', Setting::get('dp_min_percent', 50).'% dari total kamar, dibayar dalam '.Setting::get('dp_due_hours', 24).' jam setelah memesan. Sisanya dilunasi di resepsionis saat check-in.'],
        ['Pembayaran', 'Metode pembayaran apa saja yang bisa dipakai?', 'Pembayaran online lewat QRIS atau virtual account BCA, BNI, BRI, Mandiri, dan Permata. Di resepsionis, Anda juga bisa membayar tunai.'],
        ['Menginap', 'Bagaimana jika saya batal atau ingin mengubah tanggal?', 'Bisa dilakukan lebih dari '.Setting::get('cancellation_min_days', 1).' hari sebelum kedatangan; bila batal, uang muka kembali '.Setting::get('cancellation_refund_percent', 50).'%. Setelah itu, atau bila tidak datang, uang muka hangus.'],
        ['Menginap', 'Apakah harus punya akun untuk memesan?', 'Tidak. Anda bisa memesan kamar, makanan, dan laundry tanpa akun. Kode pesanan dan tautan pantau dikirim ke WhatsApp. Dengan akun, semua pesanan tersimpan di Pesanan saya.'],
        ['Layanan', 'Berapa tarif laundry?', ($hargaLaundry ? $rp($hargaLaundry).' per kg, ' : '').'minimal '.\App\Services\LaundryService::MIN_KG.' kg per pesanan, selesai sekitar 1×24 jam. Tamu menginap cukup memesan dari kamar; cucian kami jemput.'],
        ['Layanan', 'Berapa harga sewa aula?', 'Harga sewa disesuaikan dengan tanggal, durasi, dan kebutuhan acara. Ajukan jadwal lewat halaman Sewa aula, lalu staf kami menghubungi Anda untuk membicarakannya.'],
        ['Instansi', 'Bisa memesan untuk kantor atau rombongan?', 'Bisa. Ajukan lewat halaman Pengajuan instansi. Tidak perlu uang muka online; tagihan dikirim ke instansi sesuai skema yang disepakati.'],
    ];
@endphp

<x-layouts.guest title="Kontak">
<div class="animate-wt-masuk">
    <x-tamu.kepala-halaman judul="Kontak" teks="Resepsionis kami siap membantu 24 jam. Cara tercepat menghubungi kami adalah lewat WhatsApp.">
        <a href="{{ $wa('Halo Wisma Trans, saya ingin bertanya.') }}" target="_blank" rel="noopener" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline sm:w-auto">
            <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Chat WhatsApp
        </a>
        <a href="tel:{{ preg_replace('/\D/', '', $wisma['telepon'] ?? '') }}" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center rounded-sm border border-[rgba(242,235,220,.38)] px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline sm:w-auto">Telepon {{ $wisma['telepon'] ?? '' }}</a>
    </x-tamu.kepala-halaman>

    <div class="wt-container max-w-[1120px] py-10 sm:py-14">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="wt-card p-5">
                <div class="wt-eyebrow">Telepon & WhatsApp</div>
                <a href="{{ $wa('Halo Wisma Trans, saya ingin bertanya.') }}" target="_blank" rel="noopener" class="mt-2 block font-display text-xl font-extrabold tabular-nums text-ink-900 hover:text-clay-600 hover:no-underline">{{ $wisma['telepon'] ?? '' }}</a>
                <p class="mb-0 mt-1 text-[13px] text-ink-500">Telepon atau chat, 24 jam</p>
            </div>
            <div class="wt-card p-5">
                <div class="wt-eyebrow">Alamat</div>
                <address class="mt-2 text-sm not-italic leading-relaxed">@foreach ($wisma['alamat'] ?? [] as $baris){{ $baris }}<br>@endforeach</address>
                <a href="{{ $wisma['maps'] ?? '#' }}" target="_blank" rel="noopener" class="mt-1 inline-block text-[13px] font-semibold">Petunjuk arah</a>
            </div>
            <div class="wt-card p-5">
                <div class="wt-eyebrow">Jam layanan</div>
                <dl class="m-0 mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-ink-500">Resepsionis</dt><dd class="m-0 font-semibold">24 jam</dd>
                    <dt class="text-ink-500">Check-in</dt><dd class="m-0 font-semibold">mulai {{ $jam('check_in_time', '14:00') }}</dd>
                    <dt class="text-ink-500">Check-out</dt><dd class="m-0 font-semibold">s.d. {{ $jam('check_out_time', '12:00') }}</dd>
                </dl>
            </div>
            <div class="wt-card p-5">
                <div class="wt-eyebrow">Media sosial</div>
                <div class="mt-3 flex flex-col gap-2 text-sm">
                    <a href="{{ $wisma['instagram'] ?? '#' }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2.5 font-semibold text-ink-900 hover:text-clay-600 hover:no-underline"><img src="{{ asset('ds/icons/instagram.svg') }}" alt="" width="18" height="18" class="brightness-0 opacity-70">Instagram</a>
                    <a href="{{ $wisma['facebook'] ?? '#' }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2.5 font-semibold text-ink-900 hover:text-clay-600 hover:no-underline"><img src="{{ asset('ds/icons/facebook.svg') }}" alt="" width="18" height="18" class="brightness-0 opacity-70">Facebook</a>
                </div>
            </div>
        </div>

        <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
            {{-- Peta --}}
            <div class="wt-card overflow-hidden">
                <iframe title="Lokasi Wisma Trans di peta" src="https://www.google.com/maps?q={{ rawurlencode('Wisma Trans, '.implode(', ', $wisma['alamat'] ?? [])) }}&output=embed"
                        class="block h-[340px] w-full border-0 sm:h-[420px]" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>

            <div class="flex flex-col gap-6">
                {{-- Pesan WhatsApp siap kirim per topik --}}
                <section class="wt-card p-5 sm:p-6" aria-labelledby="judul-topik">
                    <h2 id="judul-topik" class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.06em]">Tanya lewat WhatsApp</h2>
                    <p class="mb-0 mt-1 text-[13px] text-ink-500">Pilih topik; pesan pembuka sudah kami siapkan.</p>
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        @foreach ($topik as [$judul, $teks])
                            <a href="{{ $wa($teks) }}" target="_blank" rel="noopener"
                               class="flex items-center justify-between gap-2 rounded-md border border-cream-300 bg-cream-50 px-3.5 py-3 text-[13px] font-semibold text-ink-900 hover:border-clay-500 hover:text-clay-600 hover:no-underline">
                                {{ $judul }}
                                <svg class="flex-none" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                            </a>
                        @endforeach
                    </div>
                </section>

                {{-- Pintasan --}}
                <section class="grid grid-cols-2 gap-2 text-[13px]" aria-label="Pintasan">
                    @foreach ([auth()->check() ? ['Pesanan saya', route('pesanan')] : ['Cek pesanan', route('cek')], ['Pesan kamar', route('kamar')], ['Sewa aula', route('aula')], ['Pengajuan instansi', route('instansi')]] as [$l, $u])
                        <a href="{{ $u }}" class="rounded-md bg-cream-200 px-3.5 py-3 font-semibold text-brown-800 hover:bg-cream-300 hover:no-underline">{{ $l }}</a>
                    @endforeach
                </section>
            </div>
        </div>

    </div>

    {{-- Pertanyaan umum, angkanya dari pengaturan sistem --}}
    <section class="relative isolate overflow-hidden border-t border-cream-300 bg-cream-150 py-14 sm:py-20" aria-labelledby="judul-tanya"
             x-data="{ buka: 0, kategori: 'Semua' }">
        <div class="pointer-events-none absolute -left-24 -top-24 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(181,113,74,.16),transparent_70%)]" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-32 -right-20 -z-10 h-96 w-96 rounded-full bg-[radial-gradient(circle,rgba(228,185,143,.30),transparent_70%)]" aria-hidden="true"></div>

        <div class="wt-container grid max-w-[1120px] items-start gap-10 lg:grid-cols-[minmax(0,.85fr)_minmax(0,1.4fr)] lg:gap-16">
            {{-- Pengantar --}}
            <div class="lg:sticky lg:top-28">
                <div class="flex items-center gap-3 font-display text-[11px] font-semibold uppercase tracking-[.3em] text-clay-500">
                    <span class="h-px w-8 bg-clay-500/60"></span>Pertanyaan umum
                </div>
                <h2 id="judul-tanya" class="mb-0 mt-4 font-display text-[30px] font-extrabold uppercase leading-[1.1] tracking-[.03em] text-ink-900 sm:text-[38px]">
                    Ada yang ingin<br class="hidden sm:block"> <span class="text-clay-500">ditanyakan?</span>
                </h2>
                <p class="mb-0 mt-4 max-w-[400px] text-[15px] leading-relaxed text-ink-500">Jawaban singkat untuk hal yang paling sering ditanyakan tamu. Angka dan aturannya selalu mengikuti ketentuan terbaru Wisma Trans.</p>

                <div class="mt-7 flex items-center gap-4 rounded-xl bg-brown-900 p-5 text-cream-50 shadow-wt-lg">
                    <span class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-[rgba(242,235,220,.1)] ring-1 ring-[rgba(228,185,143,.35)]">
                        <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="22" height="22">
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="font-display text-[13px] font-bold uppercase tracking-[.08em]">Belum terjawab?</div>
                        <div class="mt-0.5 text-[13px] text-[rgba(242,235,220,.75)]">Resepsionis siap membantu 24 jam.</div>
                    </div>
                    <a href="{{ $wa('Halo Wisma Trans, saya ingin bertanya.') }}" target="_blank" rel="noopener"
                       class="flex-none rounded-full bg-clay-500 px-4 py-2.5 font-display text-[11px] font-semibold uppercase tracking-[.12em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">Chat</a>
                </div>
            </div>

            {{-- Daftar pertanyaan --}}
            <div>
                <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:px-0 [&::-webkit-scrollbar]:hidden" role="group" aria-label="Saring pertanyaan">
                    @foreach (['Semua', ...collect($tanya)->pluck(0)->unique()->values()->all()] as $k)
                        <button type="button" @click="kategori = @js($k); buka = null" :aria-pressed="kategori === @js($k)"
                                :class="kategori === @js($k) ? 'border-brown-900 bg-brown-900 text-cream-50' : 'border-cream-400 bg-cream-50 text-brown-800 hover:border-clay-500 hover:text-clay-600'"
                                class="flex-none rounded-full border px-4 py-2 text-[13px] font-semibold transition">{{ $k }}</button>
                    @endforeach
                </div>

                <ul class="m-0 mt-5 flex list-none flex-col gap-3 p-0">
                    @foreach ($tanya as $i => [$kat, $q, $a])
                        <li x-show="kategori === 'Semua' || kategori === @js($kat)" x-transition.opacity.duration.200ms
                            :class="buka === {{ $i }} ? 'border-clay-500/50 bg-cream-50 shadow-wt-md' : 'border-cream-300 bg-[rgba(255,253,246,.65)] hover:border-cream-400 hover:bg-cream-50'"
                            class="group relative overflow-hidden rounded-xl border transition-[background-color,border-color,box-shadow] duration-300">
                            <span class="absolute inset-y-0 left-0 w-[3px] origin-top bg-clay-500 transition-transform duration-300" :class="buka === {{ $i }} ? 'scale-y-100' : 'scale-y-0'" aria-hidden="true"></span>
                            <h3 class="m-0">
                                <button type="button" @click="buka = buka === {{ $i }} ? null : {{ $i }}" :aria-expanded="buka === {{ $i }}" aria-controls="jawab-{{ $i }}"
                                        class="flex w-full items-center gap-4 border-0 bg-transparent px-5 py-4 text-left sm:px-6 sm:py-5">
                                    <span class="flex-none font-display text-xs font-bold tabular-nums tracking-[.1em] transition-colors" :class="buka === {{ $i }} ? 'text-clay-500' : 'text-ink-500/70'">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                    <span class="min-w-0 flex-1 text-[15px] font-semibold leading-snug text-ink-900">{{ $q }}</span>
                                    <span class="relative flex h-8 w-8 flex-none items-center justify-center rounded-full border transition-all duration-300"
                                          :class="buka === {{ $i }} ? 'rotate-45 border-clay-500 bg-clay-500 text-cream-50' : 'border-cream-400 text-brown-800 group-hover:border-clay-500 group-hover:text-clay-600'" aria-hidden="true">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                    </span>
                                </button>
                            </h3>
                            <div id="jawab-{{ $i }}" x-show="buka === {{ $i }}" x-collapse.duration.300ms @if ($i !== 0) x-cloak @endif>
                                <div class="flex gap-4 px-5 pb-5 sm:px-6">
                                    <span class="w-[1.15rem] flex-none" aria-hidden="true"></span>
                                    <div class="min-w-0 border-t border-cream-300 pt-4">
                                        <p class="m-0 text-sm leading-relaxed text-ink-500">{{ $a }}</p>
                                        <span class="mt-3 inline-flex rounded-full bg-cream-200 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[.1em] text-brown-800">{{ $kat }}</span>
                                    </div>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>
</div>
</x-layouts.guest>
