@props([
    'urlBayar',            // POST {metode} → {tagihan}
    'urlCek',              // GET → {dibayar, batal}
    'jumlah',              // rupiah yang ditagih
    'batas',               // batas bayar (Carbon)
    'tagihan' => null,     // tagihan aktif dari PembayaranMidtrans::tagihanAktif()
    'judul' => 'Selesaikan pembayaran',
    'keterangan' => '',
    'labelJumlah' => 'Total pembayaran',
])
@php
    use App\Services\PembayaranMidtrans;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $metode = collect(PembayaranMidtrans::METODE)->map(fn ($m, $k) => ['kunci' => $k, 'nama' => $m[0], 'ket' => $m[1]])->values();
    // Langkah bayar per metode
    $petunjuk = [
        'qris' => ['Buka aplikasi dompet digital atau m-banking yang mendukung QRIS.', 'Pilih menu Scan atau Bayar, lalu pindai kode QR di atas.', 'Pastikan nama penerima dan jumlahnya '.$rp($jumlah).', lalu konfirmasi pembayaran.'],
        'bca' => ['Buka BCA mobile, pilih m-Transfer lalu BCA Virtual Account.', 'Masukkan nomor virtual account di atas.', 'Periksa nama dan jumlah tagihan, lalu masukkan PIN m-BCA.'],
        'bni' => ['Buka BNI Mobile Banking, pilih Transfer lalu Virtual Account Billing.', 'Masukkan nomor virtual account di atas.', 'Periksa rincian tagihan, lalu konfirmasi dengan password transaksi.'],
        'bri' => ['Buka BRImo, pilih menu BRIVA.', 'Masukkan nomor virtual account di atas.', 'Periksa rincian tagihan, lalu masukkan PIN.'],
        'mandiri' => ["Buka Livin' by Mandiri, pilih Bayar lalu cari penyedia Midtrans.", 'Masukkan kode perusahaan dan nomor tagihan di atas.', 'Periksa rincian tagihan, lalu konfirmasi dengan PIN.'],
        'permata' => ['Buka PermataMobile X, pilih Bayar Tagihan lalu Virtual Account.', 'Masukkan nomor virtual account di atas.', 'Periksa rincian, lalu konfirmasi. Dari bank lain, transfer antarbank ke Permata dengan nomor virtual account sebagai rekening tujuan.'],
    ];
    $batasTeks = $batas->isToday() ? $batas->format('H.i') : $batas->translatedFormat('j M, H.i');
@endphp

{{-- Pembayaran online: pilih metode, lalu nomor VA atau QRIS tampil di kartu ini. Halaman dimuat ulang setelah dibayar. --}}
<section x-data="bayarOnline(@js([
             'urlBayar' => $urlBayar, 'urlCek' => $urlCek, 'tagihan' => $tagihan,
             'batas' => $batas->getTimestampMs(), 'kini' => now()->getTimestampMs(),
             'metode' => $metode, 'petunjuk' => $petunjuk, 'logo' => asset('ds/bank'),
         ]))"
         class="overflow-hidden rounded-lg border border-cream-300 bg-cream-50 shadow-wt-md" aria-labelledby="judul-bayar">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-cream-300 bg-cream-100 px-5 py-4 sm:px-6">
        <div>
            <h2 id="judul-bayar" class="m-0 scroll-mt-28 font-display text-[15px] font-extrabold uppercase tracking-[.06em]">{{ $judul }}</h2>
            @if ($keterangan)
                <p class="mb-0 mt-0.5 text-xs text-ink-500">{{ $keterangan }}</p>
            @endif
        </div>
        {{-- Di ponsel ringkasan ada di bawah formulir, jadi jumlahnya ditampilkan juga di sini --}}
        <div class="w-full text-sm lg:hidden">{{ $labelJumlah }} <span class="font-display text-lg font-extrabold tabular-nums">{{ $rp($jumlah) }}</span></div>
        <div class="inline-flex items-center gap-2 rounded-full bg-[rgba(232,172,69,.18)] px-3 py-1.5 text-xs font-semibold text-[#8A5A0B]" role="timer" aria-live="off">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/></svg>
            <span>Sisa waktu <span class="tabular-nums" x-text="sisa"></span></span>
        </div>
    </div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="min-w-0 p-5 sm:p-6">
            {{-- Gagal membuat tagihan --}}
            <div x-show="galat" x-cloak class="mb-4 flex items-start gap-2.5 rounded-md border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] px-3.5 py-3 text-[13px] text-ink-900" role="alert">
                <svg class="mt-px flex-none text-status-dirty" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                <span x-text="galat"></span>
            </div>

            {{-- Langkah 1: pilih metode --}}
            <div x-show="!tagihan">
                <h3 class="m-0 font-display text-[11px] font-bold uppercase tracking-[.12em] text-ink-500">Scan kode QR</h3>
                <div class="mt-2.5 flex flex-col gap-2">
                    <template x-for="m in daftarMetode.filter(m => m.kunci === 'qris')" :key="m.kunci">
                        @include('partials.pilihan-metode-bayar')
                    </template>
                </div>
                <h3 class="mb-0 mt-6 font-display text-[11px] font-bold uppercase tracking-[.12em] text-ink-500">Transfer virtual account</h3>
                <div class="mt-2.5 flex flex-col gap-2">
                    <template x-for="m in daftarMetode.filter(m => m.kunci !== 'qris')" :key="m.kunci">
                        @include('partials.pilihan-metode-bayar')
                    </template>
                </div>
                <p class="mb-0 mt-4 text-xs text-ink-500">Nomor pembayaran dibuat setelah Anda memilih metode. Metode bisa diganti sebelum membayar.</p>
            </div>

            {{-- Langkah 2: petunjuk bayar --}}
            <template x-if="tagihan">
                <div>
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-[72px] flex-none items-center justify-center rounded-md border border-cream-300 bg-white px-2"><img :src="logo(tagihan.metode)" alt="" class="max-h-6 max-w-full object-contain"></span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold leading-snug" x-text="info(tagihan.metode).nama"></div>
                            <div class="line-clamp-1 text-xs text-ink-500" x-text="info(tagihan.metode).ket"></div>
                        </div>
                        <button type="button" @click="gantiMetode()" class="flex-none rounded border-0 bg-transparent px-1 text-[13px] font-semibold text-clay-600 hover:text-clay-500 hover:underline">Ganti metode</button>
                    </div>

                    {{-- QRIS --}}
                    <template x-if="tagihan.metode === 'qris'">
                        <div class="mt-5 flex flex-col items-center rounded-lg border border-cream-300 bg-white px-5 py-6 text-center">
                            <img :src="tagihan.qr" alt="Kode QRIS untuk membayar" width="232" height="232" class="h-[232px] w-[232px] max-w-full" x-show="tagihan.qr">
                            <div class="mt-3 text-xs text-ink-500">Jumlah dibayar</div>
                            <div class="font-display text-2xl font-extrabold tabular-nums">{{ $rp($jumlah) }}</div>
                            <a :href="tagihan.qr" target="_blank" rel="noopener" class="mt-3 inline-flex h-9 items-center gap-2 rounded-full border border-cream-300 bg-cream-50 px-4 text-xs font-semibold text-brown-800 hover:bg-cream-200 hover:text-brown-800 hover:no-underline lg:hidden">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/></svg>
                                Buka gambar QR untuk disimpan
                            </a>
                        </div>
                    </template>

                    {{-- Virtual account --}}
                    <template x-if="tagihan.metode !== 'qris'">
                        <div class="mt-5 divide-y divide-cream-300 rounded-lg border border-cream-300 bg-white">
                            <template x-if="tagihan.kode_perusahaan">
                                <div class="flex items-center justify-between gap-3 px-4 py-3.5 sm:px-5">
                                    <div>
                                        <div class="text-xs text-ink-500">Kode perusahaan</div>
                                        <div class="font-display text-xl font-extrabold tabular-nums tracking-[.06em]" x-text="tagihan.kode_perusahaan"></div>
                                    </div>
                                    <button type="button" @click="salinTeks(tagihan.kode_perusahaan, 'Kode perusahaan')" class="h-9 flex-none rounded-full border border-cream-300 bg-cream-50 px-4 text-xs font-semibold text-brown-800 hover:bg-cream-200">Salin</button>
                                </div>
                            </template>
                            <div class="flex items-center justify-between gap-3 px-4 py-3.5 sm:px-5">
                                <div class="min-w-0">
                                    <div class="text-xs text-ink-500" x-text="tagihan.kode_perusahaan ? 'Nomor tagihan' : 'Nomor virtual account'"></div>
                                    <div class="break-words font-display text-lg font-extrabold tabular-nums tracking-[.06em] sm:text-2xl" x-text="kelompok(tagihan.nomor)"></div>
                                </div>
                                <button type="button" @click="salinTeks(tagihan.nomor, tagihan.kode_perusahaan ? 'Nomor tagihan' : 'Nomor virtual account')" class="h-9 flex-none rounded-full border border-cream-300 bg-cream-50 px-4 text-xs font-semibold text-brown-800 hover:bg-cream-200">Salin</button>
                            </div>
                            <div class="flex items-center justify-between gap-3 px-4 py-3.5 sm:px-5">
                                <div>
                                    <div class="text-xs text-ink-500">Jumlah transfer</div>
                                    <div class="font-display text-xl font-extrabold tabular-nums">{{ $rp($jumlah) }}</div>
                                </div>
                                <button type="button" @click="salinTeks('{{ (int) $jumlah }}', 'Jumlah transfer')" class="h-9 flex-none rounded-full border border-cream-300 bg-cream-50 px-4 text-xs font-semibold text-brown-800 hover:bg-cream-200">Salin</button>
                            </div>
                        </div>
                    </template>

                    <h3 class="mb-0 mt-6 font-display text-[11px] font-bold uppercase tracking-[.12em] text-ink-500">Cara membayar</h3>
                    <ol class="m-0 mt-3 flex list-none flex-col gap-3 p-0 text-sm">
                        <template x-for="(t, i) in petunjuk[tagihan.metode]" :key="i">
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-cream-200 font-display text-xs font-bold text-brown-800" x-text="i + 1"></span>
                                <span class="pt-0.5" x-text="t"></span>
                            </li>
                        </template>
                    </ol>

                    {{-- Status: diperiksa otomatis --}}
                    <div class="mt-6 flex flex-wrap items-center gap-3 rounded-lg bg-cream-200 px-4 py-3.5">
                        <span class="relative flex h-2.5 w-2.5 flex-none" aria-hidden="true">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-maintenance opacity-60"></span>
                            <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-status-maintenance"></span>
                        </span>
                        <div class="min-w-[200px] flex-1 text-[13px] leading-snug">
                            <div class="font-semibold text-ink-900">Menunggu pembayaran</div>
                            <div class="text-ink-500">Halaman ini berganti otomatis setelah pembayaran diterima.</div>
                        </div>
                        <button type="button" @click="cekStatus(true)" :disabled="mengecek" class="h-9 flex-none rounded border border-cream-400 bg-cream-50 px-4 text-xs font-semibold text-brown-800 hover:bg-cream-100 disabled:cursor-wait disabled:opacity-70 max-sm:w-full"
                                x-text="mengecek ? 'Mengecek…' : 'Cek status'"></button>
                    </div>
                </div>
            </template>
        </div>

        {{-- Ringkasan yang dibayar --}}
        <aside class="flex flex-col border-t border-cream-300 bg-cream-100 p-5 sm:p-6 lg:border-l lg:border-t-0">
            <div class="text-xs text-ink-500">{{ $labelJumlah }}</div>
            <div class="font-display text-[28px] font-extrabold leading-tight tabular-nums">{{ $rp($jumlah) }}</div>
            <div class="mt-1 text-xs text-ink-500">Bayar sebelum <span class="font-semibold text-ink-900">{{ $batasTeks }} WITA</span></div>
            {{ $slot }}
        </aside>
    </div>

    <div class="flex items-center gap-2 border-t border-cream-300 px-5 py-3 text-xs text-ink-500 sm:px-6">
        <svg class="flex-none" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        Pembayaran diproses dengan aman oleh Midtrans. Wisma Trans tidak menyimpan data rekening Anda.
    </div>
</section>

@once
@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('bayarOnline', (cfg) => ({
            daftarMetode: cfg.metode,
            petunjuk: cfg.petunjuk,
            tagihan: cfg.tagihan,
            memuat: '',     // metode yang sedang dibuatkan tagihan
            mengecek: false,
            sedangCek: false,   // pengecekan otomatis yang sedang berjalan, supaya tidak tumpang tindih
            galat: '',
            sisa: '',
            init() {
                // Hitung mundur memakai selisih jam server agar tidak terpengaruh jam perangkat
                const selisih = cfg.kini - Date.now();
                const dua = (n) => String(n).padStart(2, '0');
                const tik = () => {
                    const ms = cfg.batas - (Date.now() + selisih);
                    if (ms <= 0) { location.reload(); return; }
                    const d = Math.floor(ms / 1000);
                    const jam = Math.floor(d / 3600);
                    this.sisa = (jam ? jam + ':' + dua(Math.floor(d % 3600 / 60)) : Math.floor(d / 60)) + ':' + dua(d % 60);
                };
                tik();
                setInterval(tik, 1000);
                // Selama tagihan tampil, tanyakan status berkala; muat ulang begitu pembayaran diterima.
                // Begitu tamu kembali ke halaman ini (setelah membayar di aplikasi bank/e-wallet atau tab lain), langsung dicek.
                const cekOtomatis = () => { if (this.tagihan && document.visibilityState === 'visible') this.cekStatus(false); };
                setInterval(cekOtomatis, 5000);
                document.addEventListener('visibilitychange', cekOtomatis);
                window.addEventListener('focus', cekOtomatis);
            },
            info(k) { return this.daftarMetode.find(m => m.kunci === k) || { nama: k, ket: '' }; },
            logo(k) { return cfg.logo + '/' + k + '.svg'; },
            kelompok(n) { return String(n || '').replace(/(\d{4})(?=\d)/g, '$1 '); },
            kirimJson(url, opsi = {}) {
                return fetch(url, { ...opsi, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
                    .then(async (res) => ({ res, data: await res.json().catch(() => ({})) }));
            },
            async pilih(kunci) {
                if (this.memuat) return;
                this.memuat = kunci;
                this.galat = '';
                try {
                    const { res, data } = await this.kirimJson(cfg.urlBayar, { method: 'POST', body: JSON.stringify({ metode: kunci }) });
                    if (res.ok && data.tagihan) {
                        this.tagihan = data.tagihan;
                        this.$nextTick(() => this.$root.querySelector('#judul-bayar')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                    } else if (res.status === 429) {
                        this.galat = 'Terlalu sering berganti metode. Tunggu satu menit, lalu coba lagi.';
                    } else if (res.status === 419) {
                        this.galat = 'Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.';
                    } else if (res.status === 409) {
                        Alpine.store('toasts').tampil(data.pesan, '#E8AC45');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        this.galat = data.pesan || 'Nomor pembayaran gagal dibuat. Silakan coba lagi.';
                    }
                } catch {
                    this.galat = 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda, lalu coba lagi.';
                }
                this.memuat = '';
            },
            gantiMetode() { this.tagihan = null; this.galat = ''; },
            async cekStatus(manual) {
                if (this.mengecek || this.sedangCek) return;
                this.mengecek = manual;
                this.sedangCek = true;
                try {
                    const { res, data } = await this.kirimJson(cfg.urlCek);
                    if (res.ok && (data.dibayar || data.batal)) {
                        if (data.dibayar) Alpine.store('toasts').tampil('Pembayaran diterima. Memuat ulang halaman…');
                        setTimeout(() => location.reload(), data.dibayar ? 800 : 0);
                        return;
                    }
                    if (manual) Alpine.store('toasts').tampil('Pembayaran belum diterima. Coba lagi beberapa saat setelah membayar.', '#E8AC45');
                } catch {
                    if (manual) Alpine.store('toasts').tampil('Tidak dapat terhubung ke server.', '#E8AC45');
                }
                this.mengecek = false;
                this.sedangCek = false;
            },
            salinTeks(teks, nama) {
                navigator.clipboard?.writeText(String(teks))
                    .then(() => Alpine.store('toasts').tampil(nama + ' disalin'))
                    .catch(() => Alpine.store('toasts').tampil('Tidak dapat menyalin otomatis. Salin secara manual.', '#E8AC45'));
            },
        }));
    });
</script>
@endpush
@endonce
