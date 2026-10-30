@php
    use App\Models\Payment;
    use App\Services\Keuangan\PembayaranMidtrans;
    use App\Services\Reservasi\ReservasiService;
    use Illuminate\Support\Str;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    // Dari Cek Pesanan: lihat saja. Token (= kode kamar dan kode bukti) tidak boleh muncul di mana pun di halaman ini.
    $hanyaLihat ??= false;
    // Dari tautan bagikan (orang lain): juga lihat saja, ditambah tanpa pembayaran
    $dibagikan ??= false;
    $urlBagikan ??= null;
    $masuk = $r->masuk();
    $keluar = $r->keluar();
    $malam = $masuk && $keluar ? (int) $masuk->diffInDays($keluar) : 0;
    $nota = $r->stayInvoice;
    $kamar = $r->kamarBerlaku()->isNotEmpty() ? $r->kamarBerlaku() : $r->rooms->where('status', '!=', 'moved');
    $instansi = $r->request !== null;
    $dpDibayar = $r->dpDibayar();
    $siapBayar = ! $dibagikan && $r->status === 'pending_payment' && PembayaranMidtrans::siap();
    $hp = $r->customer?->phone;
    $hpSamar = $hp ? substr($hp, 0, 4).str_repeat('•', max(0, strlen($hp) - 7)).substr($hp, -3) : '—';
    $catatanTerakhir = $r->logs->where('event_type', 'status_change')->sortByDesc('id')->first()?->note;
    $jamMasuk = ReservasiService::jamCheckIn();
    $jamKeluar = str_replace(':', '.', substr((string) \App\Models\Setting::get('check_out_time', '12:00'), 0, 5));
    // Kartu kode kamar (salin dan bagikan) untuk reservasi yang akan atau sedang menginap
    $kartuKode = ! $hanyaLihat && ! $bukti && $r->access_token && in_array($r->status, ['confirmed', 'checked_in'], true);
    // Pembagian tamu per kamar dari catatan log pembuatan; dipakai hanya bila jumlahnya cocok dengan kamar yang tampil
    $pembagian = $r->pembagianTamu();
    $pembagian = count($pembagian) === $kamar->count() ? array_values($pembagian) : [];
    // Langkah 3 dari 3: uang muka reservasi online sudah diterima
    $terkonfirmasi = ! $instansi && in_array($r->status, ['confirmed', 'checked_in'], true) && $dpDibayar > 0;

    [$judul, $pesan, $warna] = match ($r->status) {
        'pending_payment' => ['Menunggu uang muka', 'Bayar uang muka sebelum '.$r->dp_due_at->translatedFormat('j F Y, H.i').' WITA. Lewat dari itu reservasi dibatalkan otomatis.', 'maintenance'],
        'waiting_approval' => ['Pengajuan sedang diperiksa', 'Manager kami memeriksa ketersediaan kamar dan menghubungi narahubung lewat WhatsApp atau email.', 'maintenance'],
        'confirmed' => ['Reservasi terkonfirmasi', $instansi
            ? 'Kamar sudah ditetapkan. Tagihan dikirim ke instansi sesuai kesepakatan.'
            : 'Uang muka sudah kami terima. Sisa pembayaran dilunasi di resepsionis.', 'available'],
        'checked_in' => ['Sedang menginap', 'Selamat beristirahat. Check-out paling lambat pukul '.$jamKeluar.' WITA.', 'available'],
        'checked_out' => ['Selesai', 'Terima kasih sudah menginap di Wisma Trans.', 'available'],
        'rejected' => ['Pengajuan belum dapat diterima', $catatanTerakhir ?: 'Hubungi kami untuk mengajukan ulang.', 'dirty'],
        'no_show' => ['Tamu tidak datang', 'Reservasi berakhir karena tamu tidak datang pada tanggal kedatangan. Uang muka hangus.', 'dirty'],
        default => ['Reservasi dibatalkan', $catatanTerakhir ?: 'Reservasi ini sudah dibatalkan.', 'dirty'],
    };
    // Selama menunggu persetujuan token disebut kode akses; setelah disetujui menjadi kode kamar
    $labelKode = $r->status === 'waiting_approval' ? 'Kode akses' : 'Kode kamar';
    $catatan = $r->catatanTerpilah();
    $adaCatatan = $catatan['kebutuhan'] || $catatan['teks'] !== '';
    // Teks yang dibagikan lewat menu Bagikan
    $jumlahKamar = $r->status === 'waiting_approval' && $instansi ? $r->request->room_count : $kamar->count();
    $subjekBagikan = 'Reservasi Wisma Trans '.$r->reservation_code;
    $teksBagikan = $urlBagikan ? implode("\n", [
        $subjekBagikan,
        ReservasiService::rentang($masuk, $keluar).' · '.$malam.' malam · '.$jumlahKamar.' kamar',
        'Status: '.$judul,
        'Lihat detail: '.$urlBagikan,
    ]) : '';
    $gaya = [
        'dirty' => 'border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.08)] text-status-dirty',
        'maintenance' => 'border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] text-[#8A5E10]',
        'available' => 'border-[rgba(92,158,103,.35)] bg-[rgba(92,158,103,.10)] text-status-available',
    ][$warna];
@endphp

<x-layouts.guest :title="$title">
<div @class(['wt-container animate-wt-masuk py-10 sm:py-14', 'max-w-[980px]' => $siapBayar, 'max-w-[820px]' => ! $siapBayar])
     x-data="{ ubah: false, batal: false, bukaUbah() { this.ubah = true; this.$nextTick(() => document.getElementById('ubah-tanggal')?.scrollIntoView({ behavior: 'smooth', block: 'center' })); } }">

    @if (auth()->check() && ! $dibagikan)
        <a href="{{ route('pesanan') }}" class="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-500 hover:text-clay-600">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Pesanan saya
        </a>
    @endif
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="font-display text-[11px] font-semibold uppercase tracking-[.22em] text-clay-600" data-langkah>{{ $terkonfirmasi ? 'Langkah 3 dari 3 · Pesanan terkonfirmasi' : ($instansi ? 'Pengajuan reservasi instansi' : 'Reservasi kamar') }}</div>
            <div class="mt-1.5 flex flex-wrap items-center gap-3">
                <h1 class="m-0 font-display text-[26px] font-extrabold uppercase tracking-[.03em] tabular-nums">{{ $r->reservation_code }}</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold {{ $gaya }}" data-lencana-status>
                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $judul }}
                </span>
            </div>
            <div class="mt-1 text-sm text-ink-500">Dipesan {{ $r->created_at->translatedFormat('l, j F Y · H.i') }} WITA</div>
        </div>
        {{-- Bagikan: tautan lihat bertanda tangan (tanpa token akses), bukan alamat halaman ini --}}
        @if ($urlBagikan)
            <div class="relative" data-bagikan
                 x-data="{
                     menu: false, disalin: false, gagal: false, bisaShare: typeof navigator.share === 'function',
                     url: @js($urlBagikan), teks: @js($teksBagikan), subjek: @js($subjekBagikan),
                     tutup(fokus = true) { if (! this.menu) return; this.menu = false; if (fokus) this.$refs.tombol.focus(); },
                     async bagikan() { try { await navigator.share({ title: this.subjek, text: this.teks }); this.tutup(); } catch (e) {} },
                     salinCadangan() {
                         const t = document.createElement('textarea');
                         t.value = this.url; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
                         document.body.appendChild(t); t.select();
                         let ok = false;
                         try { ok = document.execCommand('copy'); } catch (e) {}
                         t.remove();
                         return ok;
                     },
                     async salin() {
                         let ok = false;
                         try { await navigator.clipboard.writeText(this.url); ok = true; } catch (e) { ok = this.salinCadangan(); }
                         this.gagal = ! ok;
                         if (! ok) return;
                         this.disalin = true;
                         this.$store.toasts.tampil('Tautan disalin');
                         setTimeout(() => this.disalin = false, 2500);
                     },
                 }"
                 @keydown.escape.window="tutup()" @click.outside="tutup(false)">
                <button type="button" x-ref="tombol" @click="menu = ! menu" :aria-expanded="menu" aria-haspopup="menu" aria-controls="menu-bagikan"
                        class="inline-flex h-9 items-center gap-2 rounded-full border border-cream-300 bg-cream-50 px-4 text-[13px] text-brown-800 hover:bg-cream-200">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg>
                    Bagikan
                </button>
                <div id="menu-bagikan" x-show="menu" x-cloak x-transition.opacity.duration.150ms role="menu" aria-label="Bagikan reservasi"
                     class="absolute left-0 top-full z-30 mt-2 w-[min(300px,calc(100vw-32px))] rounded-lg border border-cream-300 bg-cream-50 p-1.5 shadow-wt-lg sm:left-auto sm:right-0" data-menu-bagikan>
                    @php $item = 'flex w-full items-center gap-3 rounded-md border-0 bg-transparent px-2.5 py-2.5 text-left text-sm text-ink-900 hover:bg-cream-200 hover:text-ink-900 hover:no-underline'; @endphp
                    <button type="button" x-show="bisaShare" @click="bagikan()" role="menuitem" class="{{ $item }}">
                        <svg class="flex-none text-clay-600" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7M16 6l-4-4-4 4M12 2v13"/></svg>
                        Bagikan…
                    </button>
                    <a :href="'https://wa.me/?text=' + encodeURIComponent(teks)" target="_blank" rel="noopener" @click="tutup(false)" role="menuitem" class="{{ $item }}" data-bagikan-wa>
                        <span class="block h-4 w-4 flex-none bg-[#25A244]" style="-webkit-mask: url('{{ asset('ds/icons/whatsapp.svg') }}') center/contain no-repeat; mask: url('{{ asset('ds/icons/whatsapp.svg') }}') center/contain no-repeat" aria-hidden="true"></span>
                        WhatsApp
                    </a>
                    <a :href="'mailto:?subject=' + encodeURIComponent(subjek) + '&body=' + encodeURIComponent(teks)" @click="tutup(false)" role="menuitem" class="{{ $item }}" data-bagikan-email>
                        <svg class="flex-none text-clay-600" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                        Email
                    </a>
                    <button type="button" @click="salin()" role="menuitem" class="{{ $item }}" data-bagikan-salin>
                        <svg class="flex-none text-clay-600" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        <span x-text="disalin ? 'Tautan disalin' : 'Salin tautan'" :class="disalin && 'font-semibold text-status-available'">Salin tautan</span>
                    </button>
                    {{-- Bila browser menolak menyalin: tautan ditampilkan untuk disalin manual --}}
                    <input x-show="gagal" x-cloak type="text" readonly :value="url" @focus="$event.target.select()" aria-label="Tautan lihat reservasi" class="wt-input mx-1 mb-1 mt-0.5 h-9 w-[calc(100%-8px)] text-xs">
                    <p class="m-0 mt-1 border-t border-cream-300 px-2.5 pb-1 pt-2 text-[11px] leading-relaxed text-ink-500">Tautan ini hanya untuk melihat. Pembatalan dan perubahan hanya bisa dilakukan oleh pemesan.</p>
                </div>
            </div>
        @endif
    </div>

    @if ($dibagikan)
        <div class="mb-6 flex items-start gap-3 rounded-lg border border-[rgba(108,149,196,.35)] bg-[rgba(108,149,196,.08)] px-5 py-4" role="note" data-mode-bagikan>
            <svg class="mt-0.5 flex-none text-[#3F6B9C]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
            <p class="m-0 text-[13px] leading-relaxed text-ink-900"><span class="font-semibold">Dibagikan oleh pemesan, hanya untuk melihat.</span> Pembatalan dan perubahan hanya bisa dilakukan oleh pemesan.</p>
        </div>
    @elseif ($hanyaLihat)
        <x-tamu.mode-lihat jenis="reservasi" :kode="$r->reservation_code" :hp="$r->customer?->phone" :telepon="$wisma['telepon'] ?? '0822-9222-4111'" />
    @endif
    @if (session('status'))
        <div class="mb-5 rounded-lg border border-[rgba(92,158,103,.35)] bg-[rgba(92,158,103,.10)] px-5 py-3.5 text-sm text-ink-900" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-5 rounded-lg border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] px-5 py-3.5 text-sm text-ink-900" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($siapBayar)
        {{-- Lihat saja: tanpa token, endpoint bayar dan cek memeriksa izin di session --}}
        <x-bayar-online :url-bayar="route('reservasi.bayar', array_filter(['kode' => $r->reservation_code, 't' => $token]))"
                        :url-cek="route('reservasi.cek', array_filter(['kode' => $r->reservation_code, 't' => $token]))"
                        :jumlah="$r->jumlahBayar()" :batas="$r->dp_due_at" :tagihan="$tagihan"
                        judul="Bayar uang muka" label-jumlah="Uang muka {{ ReservasiService::persenUangMuka() }}%"
                        keterangan="Kamar terkonfirmasi setelah uang muka diterima. Sisanya dibayar saat check-out.">
            <dl class="m-0 mt-5 flex flex-col gap-1.5 border-t border-cream-300 pt-4 text-xs">
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Menginap</dt><dd class="m-0 text-right font-semibold">{{ ReservasiService::rentang($masuk, $keluar) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Kamar</dt><dd class="m-0 text-right font-semibold">{{ $kamar->countBy(fn ($k) => $k->roomType->name)->map(fn ($n, $t) => "{$n} {$t}")->join(', ') }}</dd></div>
                @if (($tambahanKamar = \App\Services\Reservasi\TambahanKamar::ringkasan($r))->isNotEmpty())
                    <div class="flex justify-between gap-3" data-tambahan-reservasi><dt class="text-ink-500">Tambahan</dt><dd class="m-0 text-right font-semibold">{{ $tambahanKamar->join('; ') }}</dd></div>
                @endif
                <div class="flex justify-between gap-3"><dt class="text-ink-500">{{ $tambahanKamar->isNotEmpty() ? 'Total' : 'Total kamar' }}</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp($nota?->total_amount) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Sisa saat check-out</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp((float) $nota?->total_amount - $r->jumlahBayar()) }}</dd></div>
            </dl>
        </x-bayar-online>
    @else
        <div class="rounded-lg border px-5 py-4 {{ $gaya }}" role="status">
            <div class="font-display text-base font-bold">{{ $judul }}</div>
            <p class="mb-0 mt-1 text-sm text-ink-900/80">{{ $pesan }}</p>
            @if ($r->status === 'pending_payment' && ! $dibagikan)
                <p class="mb-0 mt-2 text-sm text-ink-900/80">Pembayaran online sedang tidak tersedia. Hubungi resepsionis {{ $wisma['telepon'] ?? '' }} untuk membayar uang muka.</p>
            @endif
            @if (in_array($r->status, ['cancelled', 'no_show', 'rejected'], true))
                <div class="mt-4">
                    <a href="{{ $instansi ? route('instansi') : route('kamar') }}" class="inline-flex h-10 items-center rounded bg-clay-500 px-5 font-display text-xs font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">{{ $instansi ? 'Ajukan ulang' : 'Pesan lagi' }}</a>
                </div>
            @endif
        </div>
    @endif

    {{-- Bukti pemesanan instansi: kode bukti untuk staf yang ditugaskan menginap --}}
    @if ($bukti)
        <div class="wt-card mt-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
            <x-kode-kamar :reservasi="$r" label="Kode bukti / kode kamar" besar class="flex-1">
                <p class="mb-0 mt-2 text-sm leading-relaxed text-ink-900">Teruskan kode ini kepada staf yang ditugaskan menginap. Sebutkan atau tunjukkan di resepsionis saat kedatangan.</p>
            </x-kode-kamar>
            <div class="flex flex-none flex-col gap-2">
                @if ($r->status === 'checked_in')
                    <a href="{{ $r->urlPesanMakanan() }}" class="inline-flex h-9 items-center justify-center rounded-full bg-clay-500 px-4 text-[13px] font-semibold text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">Pesan makanan</a>
                    <a href="{{ $r->urlPesanLaundry() }}" class="inline-flex h-9 items-center justify-center rounded-full border border-clay-500 px-4 text-[13px] font-semibold text-clay-600 hover:bg-[rgba(181,113,74,.08)] hover:no-underline">Pesan laundry</a>
                @endif
                <a href="{{ $bukti['url'] }}" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-2 rounded-full bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900 hover:text-cream-50 hover:no-underline">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/></svg>
                    Unduh bukti (PDF)
                </a>
            </div>
        </div>
    @endif

    @if ($kartuKode)
        <div class="wt-card mt-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
            <x-kode-kamar :reservasi="$r" besar class="flex-1">
                <p class="mb-0 mt-2 text-sm leading-relaxed text-ink-900">Pakai kode ini untuk memesan makanan dan laundry ke kamar selama menginap.</p>
            </x-kode-kamar>
            @if ($r->status === 'checked_in')
                <div class="flex flex-none flex-col gap-2">
                    <a href="{{ $r->urlPesanMakanan() }}" class="inline-flex h-10 items-center justify-center rounded-full bg-clay-500 px-5 text-[13px] font-semibold text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">Pesan makanan</a>
                    <a href="{{ $r->urlPesanLaundry() }}" class="inline-flex h-10 items-center justify-center rounded-full border border-clay-500 px-5 text-[13px] font-semibold text-clay-600 hover:bg-[rgba(181,113,74,.08)] hover:no-underline">Pesan laundry</a>
                </div>
            @endif
        </div>
    @endif

    {{-- Rincian menginap: tanggal dan jam, kode kamar, kamar per baris, ringkasan pembayaran, dan tindakan --}}
    <div class="wt-card mt-8 overflow-hidden p-0" data-rincian-menginap>
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-cream-300 px-5 py-4 sm:px-6">
            <div class="min-w-0">
                <div class="wt-micro-label">Menginap</div>
                <div class="mt-1 font-display text-[17px] font-bold">{{ $masuk?->translatedFormat('j M') }} – {{ $keluar?->translatedFormat('j M Y') }} · {{ $malam }} malam</div>
                <div class="mt-0.5 text-[13px] text-ink-500">Check-in mulai {{ $jamMasuk }} · Check-out paling lambat {{ $jamKeluar }} WITA</div>
                @if (in_array($r->status, ['pending_payment', 'waiting_approval', 'confirmed'], true))
                    <div class="mt-0.5 text-[13px] text-ink-500" data-early-check-in>{{ ReservasiService::teksEarlyCheckIn() }}</div>
                @endif
            </div>
            {{-- Kode kamar tampil sekali saja: di sini, atau di kartu kode di atas (termasuk kode bukti instansi) --}}
            @if (! $hanyaLihat && $r->access_token && ! $bukti && ! $kartuKode)
                <div class="text-right" data-kode-akses>
                    <div class="wt-micro-label">{{ $labelKode }}</div>
                    <div class="mt-1 font-display text-[17px] font-bold tabular-nums tracking-[.06em]">{{ $r->token() }}</div>
                    @if ($r->status === 'waiting_approval')
                        <p class="mb-0 ml-auto mt-1 max-w-[260px] text-[11px] leading-snug text-ink-500">Dipakai untuk cek status. Setelah disetujui, kode ini juga menjadi kode kamar untuk pesan makanan dan laundry.</p>
                    @endif
                </div>
            @endif
        </div>

        @if ($instansi && $r->status === 'waiting_approval')
            <dl class="m-0 grid gap-x-6 gap-y-3 px-5 py-4 text-sm sm:grid-cols-2 sm:px-6">
                <div><dt class="text-xs text-ink-500">Instansi</dt><dd class="m-0 font-semibold" data-instansi-pengajuan>
                    @if ($r->institution)
                        {{ $r->institution->name }}
                    @elseif ($namaTulis = $r->instansiTulis())
                        {{ $namaTulis }} <span class="ml-1 whitespace-nowrap rounded-full bg-[rgba(232,172,69,.16)] px-2 py-0.5 align-middle text-[11px] font-semibold text-[#8A5E10]">menunggu konfirmasi</span>
                    @else
                        Diisi saat persetujuan
                    @endif
                </dd></div>
                <div><dt class="text-xs text-ink-500">Nama kegiatan</dt><dd class="m-0 font-semibold">{{ $r->request->event_name ?: '—' }}</dd></div>
                <div><dt class="text-xs text-ink-500">Kamar diajukan</dt><dd class="m-0 font-semibold">{{ $r->request->room_count }} kamar</dd></div>
                @if ($tamu = $r->pesertaInstansi())
                    <div><dt class="text-xs text-ink-500">Jumlah tamu</dt><dd class="m-0 font-semibold" data-jumlah-tamu>{{ $tamu }} orang</dd></div>
                @endif
                @if ($adaCatatan)
                    <div class="sm:col-span-2"><dt class="text-xs text-ink-500">Catatan</dt><dd class="m-0">@include('tamu.partials.catatan-tamu', ['catatan' => $catatan])</dd></div>
                @endif
            </dl>
        @else
            <ul class="m-0 list-none divide-y divide-cream-300 p-0">
                @foreach ($kamar->values() as $i => $k)
                    @php
                        $kasur = $k->room?->bed_type;
                        $foto = $k->roomType->photoUrls()[0] ?? null;
                        $sudahMasuk = $k->room && in_array($k->status, ['checked_in', 'checked_out'], true);
                    @endphp
                    <li class="grid grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-3.5 sm:px-6" data-kamar-reservasi>
                        @if ($foto)
                            <img src="{{ $foto }}" alt="" loading="lazy" class="h-[44px] w-[56px] rounded object-cover">
                        @else
                            <span class="flex h-[44px] w-[56px] items-center justify-center rounded bg-cream-200 text-clay-600" aria-hidden="true"><x-tamu.ikon nama="kasur" ukuran="20" /></span>
                        @endif
                        <div class="min-w-0">
                            <div class="font-semibold text-ink-900">{{ $k->roomType->name }}{{ $kasur ? ' · '.\App\Models\Room::labelKasur($kasur) : '' }}</div>
                            <div class="text-[13px] text-ink-500">
                                {{ collect([$pembagian[$i] ?? null, $sudahMasuk ? 'Kamar '.$k->room->room_number : 'Nomor kamar diberikan saat check-in'])->filter()->join(' · ') }}
                            </div>
                            <div class="text-xs tabular-nums text-ink-500">
                                {{ $rp($k->price_per_night) }} × {{ $k->malam() }} malam
                                @if ($k->potongan() > 0) · potongan {{ $rp($k->potongan()) }} @endif
                            </div>
                            @if ($t = \App\Services\Reservasi\TambahanKamar::teks($k))
                                <div class="mt-0.5 text-xs font-medium text-clay-600" data-tambahan-kamar-tamu>+ {{ $t }}</div>
                            @endif
                        </div>
                        <div class="flex flex-col items-end gap-1.5">
                            <span @class(['rounded-full px-2.5 py-0.5 text-[11px] font-semibold',
                                'bg-[rgba(92,158,103,.14)] text-status-available' => in_array($k->status, ['booked', 'checked_in'], true) && in_array($r->status, ['confirmed', 'checked_in'], true),
                                'bg-cream-200 text-ink-500' => ! (in_array($k->status, ['booked', 'checked_in'], true) && in_array($r->status, ['confirmed', 'checked_in'], true))])>
                                {{ $r->status === 'pending_payment' ? 'Menunggu uang muka' : (\App\Models\ReservationRoom::STATUS[$k->status] ?? $k->status) }}
                            </span>
                            <span class="font-semibold tabular-nums">{{ $rp($k->biaya()) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($nota && ! $instansi)
                <dl class="m-0 flex flex-col gap-2 border-t border-cream-300 bg-cream-100 px-5 py-4 text-sm sm:px-6">
                    <div class="flex items-baseline justify-between"><dt class="font-semibold">Total</dt><dd class="m-0 font-display text-lg font-extrabold tabular-nums">{{ $rp($nota->total_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Sudah dibayar</dt>
                        <dd class="m-0 tabular-nums">{{ $rp($nota->paid_amount) }}@if ($r->dp_amount_required && $dpDibayar <= 0) · <span class="font-semibold text-[#8A5E10]">uang muka {{ $rp($r->dp_amount_required) }} belum dibayar</span>@endif</dd></div>
                    @if (in_array($r->status, ['pending_payment', 'confirmed', 'checked_in'], true))
                        <div class="flex justify-between"><dt class="text-ink-500">Sisa saat check-out</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp(max(0, (float) $nota->total_amount - (float) $nota->paid_amount)) }}</dd></div>
                    @endif
                </dl>
            @endif
        @endif

        @if ($urlNota || $bolehUbah)
            <div class="flex flex-wrap items-center gap-2.5 border-t border-cream-300 px-5 py-4 sm:px-6">
                @if ($urlNota)
                    <a href="{{ $urlNota }}" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900 hover:text-cream-50 hover:no-underline">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/></svg>
                        Unduh nota (PDF)
                    </a>
                @endif
                @if ($bolehUbah)
                    <button type="button" @click="bukaUbah()" class="h-10 rounded border border-cream-400 bg-cream-50 px-4 text-[13px] font-semibold text-brown-800 hover:bg-cream-200">Ubah tanggal</button>
                @endif
            </div>
        @endif
    </div>

    @if ($folio)
        {{-- Tagihan berjalan selama menginap, supaya biaya tambahan tidak baru diketahui saat check-out --}}
        <section class="mt-8" aria-labelledby="judul-tagihan">
            <h2 id="judul-tagihan" class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.06em]">Tagihan sementara</h2>
            <p class="mb-3 mt-1 text-xs text-ink-500">Kamar, pesanan yang ditagihkan ke kamar, dan pembayaran yang sudah kami terima. Dilunasi di resepsionis saat check-out.</p>
            <x-tagihan-sementara :folio="$folio" class="bg-cream-50" />
            @if ($folio['kredit'])
                <p class="mb-0 mt-2 text-xs text-ink-500">{{ $folio['kredit'] }}</p>
            @endif
        </section>
    @endif

    <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div><dt class="text-xs text-ink-500">{{ $instansi ? 'Narahubung' : 'Nama pemesan' }}</dt><dd class="m-0 font-semibold">{{ $r->customer?->full_name }}</dd></div>
        <div><dt class="text-xs text-ink-500">Nomor WhatsApp</dt><dd class="m-0 font-semibold tabular-nums">{{ $hpSamar }}</dd></div>
        @if ($adaCatatan && ! ($instansi && $r->status === 'waiting_approval'))
            <div class="sm:col-span-2"><dt class="text-xs text-ink-500">Permintaan khusus</dt><dd class="m-0" data-permintaan-khusus>@include('tamu.partials.catatan-tamu', ['catatan' => $catatan])</dd></div>
        @endif
        @php $bayarDp = $nota?->payments->where('status', 'success')->where('payment_purpose', 'down_payment')->sortBy('paid_at')->first(); @endphp
        @if ($bayarDp)
            <div><dt class="text-xs text-ink-500">Uang muka dibayar</dt><dd class="m-0 font-semibold">{{ Payment::METHODS[$bayarDp->method] ?? $bayarDp->method }} · {{ $bayarDp->paid_at?->translatedFormat('j M, H.i') }} WITA</dd></div>
        @endif
    </dl>

    {{-- Ubah tanggal dan pembatalan --}}
    @if ($bolehUbah || $bolehBatal)
        <div class="mt-8 grid gap-4 {{ $bolehUbah && $bolehBatal ? 'sm:grid-cols-2' : '' }}">
            @if ($bolehUbah)
                <div id="ubah-tanggal" class="wt-card scroll-mt-24 p-5">
                    <div class="font-display text-[13px] font-bold uppercase tracking-[.08em]">Ubah tanggal</div>
                    <p class="mb-0 mt-1 text-xs leading-relaxed text-ink-500">Bisa sampai {{ ReservasiService::hariMinimalBatal() + 1 }} hari sebelum kedatangan. Harga per malam tetap sama.</p>
                    <button type="button" x-show="!ubah" @click="ubah = true" class="mt-3 h-10 rounded border border-cream-400 bg-cream-50 px-4 text-[13px] font-semibold text-brown-800 hover:bg-cream-200">Pilih tanggal baru</button>
                    <form x-show="ubah" x-cloak method="POST" action="{{ route('reservasi.ubah', ['kode' => $r->reservation_code]) }}" class="mt-3 flex flex-col gap-3">
                        @csrf
                        <input type="hidden" name="t" value="{{ $token }}">
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block"><span class="wt-field-label mb-1.5 block">Check-in</span>
                                <input type="date" name="masuk" required min="{{ today()->toDateString() }}" value="{{ old('masuk', $masuk?->toDateString()) }}" class="wt-input h-10"></label>
                            <label class="block"><span class="wt-field-label mb-1.5 block">Check-out</span>
                                <input type="date" name="keluar" required min="{{ today()->addDay()->toDateString() }}" value="{{ old('keluar', $keluar?->toDateString()) }}" class="wt-input h-10"></label>
                        </div>
                        <button class="h-10 rounded border-0 bg-clay-500 font-display text-xs font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600">Simpan tanggal baru</button>
                    </form>
                </div>
            @endif
            @if ($bolehBatal)
                <div id="batal-reservasi" class="wt-card scroll-mt-24 p-5">
                    <div class="font-display text-[13px] font-bold uppercase tracking-[.08em]">Batalkan {{ $instansi ? 'pengajuan' : 'reservasi' }}</div>
                    <p class="mb-0 mt-1 text-xs leading-relaxed text-ink-500">
                        @if ($r->status === 'confirmed')
                            Uang muka dikembalikan {{ ReservasiService::persenPengembalian() }}% ({{ $rp($pengembalian) }}). Pembatalan kurang dari {{ ReservasiService::hariMinimalBatal() + 1 }} hari sebelum kedatangan membuat uang muka hangus.
                        @else
                            Belum ada pembayaran, jadi tidak ada biaya pembatalan.
                        @endif
                    </p>
                    <button type="button" x-show="!batal" @click="batal = true" class="mt-3 h-10 rounded border border-[rgba(212,81,74,.45)] bg-cream-50 px-4 text-[13px] font-semibold text-status-dirty hover:bg-[rgba(212,81,74,.06)]">Batalkan</button>
                    <form x-show="batal" x-cloak method="POST" action="{{ route('reservasi.batal', ['kode' => $r->reservation_code]) }}" class="mt-3 flex flex-col gap-3">
                        @csrf
                        <input type="hidden" name="t" value="{{ $token }}">
                        <label class="block"><span class="wt-field-label mb-1.5 block">Alasan (opsional)</span>
                            <input type="text" name="alasan" maxlength="150" class="wt-input h-10" placeholder="Contoh: rencana perjalanan berubah"></label>
                        <div class="flex gap-2">
                            <button class="h-10 flex-1 rounded border-0 bg-status-dirty font-display text-xs font-bold uppercase tracking-[.08em] text-cream-50 hover:opacity-90">Ya, batalkan</button>
                            <button type="button" @click="batal = false" class="h-10 rounded border border-cream-300 bg-transparent px-4 text-[13px] text-brown-800 hover:bg-cream-200">Tidak</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    @elseif ($r->status === 'confirmed' && ! $hanyaLihat)
        <p class="mt-8 text-sm text-ink-500">Perubahan tanggal atau pembatalan kurang dari {{ ReservasiService::hariMinimalBatal() + 1 }} hari sebelum kedatangan dilayani resepsionis.</p>
    @endif

    @unless ($dibagikan)
        <x-ajakan-akun :hp="$r->customer?->phone" class="mt-8" />
    @endunless

    <p class="mt-8 text-center text-xs text-ink-500">
        @if ($dibagikan)
            Halaman ini dibagikan oleh pemesan dan hanya untuk melihat. Ada kendala? Hubungi {{ $wisma['telepon'] ?? 'resepsionis' }}.
        @elseif ($hanyaLihat)
            Halaman ini dibuka dari Cek pesanan dan berlaku sementara. Ada kendala? Hubungi {{ $wisma['telepon'] ?? 'resepsionis' }}.
        @else
            Simpan tautan halaman ini, atau buka Cek pesanan dengan kode {{ $r->reservation_code }} dan {{ $bukti ? 'kode bukti di atas' : ($kartuKode ? 'kode kamar di atas' : Str::lower($labelKode).' '.$r->token()) }}. Ada kendala? Hubungi {{ $wisma['telepon'] ?? 'resepsionis' }}.
        @endif
    </p>
</div>
</x-layouts.guest>
