@php
    use App\Models\Payment;
    use App\Services\PembayaranMidtrans;
    use App\Services\ReservasiService;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $masuk = $r->masuk();
    $keluar = $r->keluar();
    $malam = $masuk && $keluar ? (int) $masuk->diffInDays($keluar) : 0;
    $nota = $r->stayInvoice;
    $kamar = $r->kamarBerlaku()->isNotEmpty() ? $r->kamarBerlaku() : $r->rooms->where('status', '!=', 'moved');
    $instansi = $r->request !== null;
    $dpDibayar = $r->dpDibayar();
    $siapBayar = $r->status === 'pending_payment' && PembayaranMidtrans::siap();
    $hp = $r->customer?->phone;
    $hpSamar = $hp ? substr($hp, 0, 4).str_repeat('•', max(0, strlen($hp) - 7)).substr($hp, -3) : '—';
    $catatanTerakhir = $r->logs->where('event_type', 'status_change')->sortByDesc('id')->first()?->note;
    $jamMasuk = ReservasiService::jamCheckIn();
    $jamKeluar = str_replace(':', '.', substr((string) \App\Models\Setting::get('check_out_time', '12:00'), 0, 5));

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
    $gaya = [
        'dirty' => 'border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.08)] text-status-dirty',
        'maintenance' => 'border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] text-[#8A5E10]',
        'available' => 'border-[rgba(92,158,103,.35)] bg-[rgba(92,158,103,.10)] text-status-available',
    ][$warna];
@endphp

<x-layouts.guest :title="$title">
<div @class(['wt-container animate-wt-masuk py-10 sm:py-14', 'max-w-[980px]' => $siapBayar, 'max-w-[820px]' => ! $siapBayar])
     x-data="{ salin() { navigator.clipboard?.writeText(location.href).then(() => $store.toasts.tampil('Tautan reservasi disalin')).catch(() => $store.toasts.tampil('Salin tautan dari bilah alamat browser', '#E8AC45')); } }">

    @auth
        <a href="{{ route('pesanan') }}" class="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-500 hover:text-clay-600">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Pesanan saya
        </a>
    @endauth
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="wt-eyebrow">{{ $instansi ? 'Pengajuan reservasi instansi' : 'Reservasi kamar' }}</div>
            <h1 class="mb-0 mt-1.5 font-display text-2xl font-extrabold tracking-[.02em] tabular-nums">{{ $r->reservation_code }}</h1>
            <div class="mt-1 text-sm text-ink-500">
                Dipesan {{ $r->created_at->translatedFormat('l, j F Y · H.i') }} WITA
                @if ($r->access_token)
                    · Token <span class="font-semibold tabular-nums tracking-[.06em] text-ink-900">{{ $r->token() }}</span>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($urlNota)
                <a href="{{ $urlNota }}" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-2 rounded-full bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900 hover:text-cream-50 hover:no-underline">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/></svg>
                    Unduh nota (PDF)
                </a>
            @endif
            <button type="button" @click="salin()" class="inline-flex h-9 items-center gap-2 rounded-full border border-cream-300 bg-cream-50 px-4 text-[13px] text-brown-800 hover:bg-cream-200">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Salin tautan reservasi
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-5 rounded-lg border border-[rgba(92,158,103,.35)] bg-[rgba(92,158,103,.10)] px-5 py-3.5 text-sm text-ink-900" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-5 rounded-lg border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] px-5 py-3.5 text-sm text-ink-900" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($siapBayar)
        <x-bayar-online :url-bayar="route('reservasi.bayar', ['kode' => $r->reservation_code, 't' => $token])"
                        :url-cek="route('reservasi.cek', ['kode' => $r->reservation_code, 't' => $token])"
                        :jumlah="$r->jumlahBayar()" :batas="$r->dp_due_at" :tagihan="$tagihan"
                        judul="Bayar uang muka" label-jumlah="Uang muka {{ ReservasiService::persenUangMuka() }}%"
                        keterangan="Kamar terkonfirmasi setelah uang muka diterima. Sisanya dilunasi di resepsionis.">
            <dl class="m-0 mt-5 flex flex-col gap-1.5 border-t border-cream-300 pt-4 text-xs">
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Menginap</dt><dd class="m-0 text-right font-semibold">{{ ReservasiService::rentang($masuk, $keluar) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Kamar</dt><dd class="m-0 text-right font-semibold">{{ $kamar->countBy(fn ($k) => $k->roomType->name)->map(fn ($n, $t) => "{$n} {$t}")->join(', ') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Total kamar</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp($nota?->total_amount) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Dilunasi saat menginap</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp((float) $nota?->total_amount - $r->jumlahBayar()) }}</dd></div>
            </dl>
        </x-bayar-online>
    @else
        <div class="rounded-lg border px-5 py-4 {{ $gaya }}" role="status">
            <div class="font-display text-base font-bold">{{ $judul }}</div>
            <p class="mb-0 mt-1 text-sm text-ink-900/80">{{ $pesan }}</p>
            @if ($r->status === 'pending_payment')
                <p class="mb-0 mt-2 text-sm text-ink-900/80">Pembayaran online sedang tidak tersedia. Hubungi resepsionis {{ $wisma['telepon'] ?? '' }} untuk membayar uang muka.</p>
            @endif
            @if (in_array($r->status, ['cancelled', 'no_show', 'rejected'], true))
                <div class="mt-4">
                    <a href="{{ $instansi ? route('instansi') : route('kamar') }}" class="inline-flex h-10 items-center rounded bg-clay-500 px-5 font-display text-xs font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">{{ $instansi ? 'Ajukan ulang' : 'Pesan lagi' }}</a>
                </div>
            @endif
        </div>
    @endif

    {{-- Rincian menginap --}}
    <div class="wt-card mt-8 overflow-hidden">
        <div class="grid grid-cols-2 divide-x divide-cream-300 border-b border-cream-300 sm:grid-cols-3">
            <div class="px-5 py-4">
                <div class="text-xs text-ink-500">Check-in</div>
                <div class="font-display text-[15px] font-bold">{{ $masuk?->translatedFormat('D, j M Y') }}</div>
                <div class="text-xs text-ink-500">mulai {{ $jamMasuk }} WITA</div>
            </div>
            <div class="px-5 py-4">
                <div class="text-xs text-ink-500">Check-out</div>
                <div class="font-display text-[15px] font-bold">{{ $keluar?->translatedFormat('D, j M Y') }}</div>
                <div class="text-xs text-ink-500">paling lambat {{ $jamKeluar }} WITA</div>
            </div>
            <div class="col-span-2 border-t border-cream-300 px-5 py-4 sm:col-span-1 sm:border-t-0">
                <div class="text-xs text-ink-500">Lama menginap</div>
                <div class="font-display text-[15px] font-bold">{{ $malam }} malam</div>
                <div class="text-xs text-ink-500">{{ $instansi && $r->request ? $r->request->room_count.' kamar diajukan' : $kamar->count().' kamar' }}</div>
            </div>
        </div>

        @if ($instansi && $r->status === 'waiting_approval')
            <dl class="m-0 grid gap-x-6 gap-y-3 px-5 py-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-ink-500">Instansi</dt><dd class="m-0 font-semibold">{{ $r->institution?->name ?? 'Diisi saat persetujuan' }}</dd></div>
                <div><dt class="text-xs text-ink-500">Nama kegiatan</dt><dd class="m-0 font-semibold">{{ $r->request->event_name ?: '—' }}</dd></div>
                @if ($r->special_request)
                    <div class="sm:col-span-2"><dt class="text-xs text-ink-500">Catatan</dt><dd class="m-0">{{ $r->special_request }}</dd></div>
                @endif
            </dl>
        @else
            <div class="divide-y divide-cream-300">
                @foreach ($kamar as $k)
                    <div class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                        <div class="min-w-0">
                            <div class="font-semibold">{{ $k->roomType->name }}{{ $k->room ? ' · Kamar '.$k->room->room_number : '' }}</div>
                            <div class="text-xs tabular-nums text-ink-500">
                                {{ $rp($k->price_per_night) }} × {{ $k->malam() }} malam
                                @if ($k->potongan() > 0) · potongan {{ $rp($k->potongan()) }} @endif
                            </div>
                        </div>
                        <div class="font-semibold tabular-nums">{{ $rp($k->biaya()) }}</div>
                    </div>
                @endforeach
            </div>
            @if ($nota && ! $instansi)
                <dl class="m-0 flex flex-col gap-2 border-t border-cream-300 bg-cream-100 px-5 py-4 text-sm">
                    <div class="flex items-baseline justify-between"><dt class="text-ink-500">Total kamar</dt><dd class="m-0 font-display text-lg font-extrabold tabular-nums">{{ $rp($nota->total_amount) }}</dd></div>
                    @if ($r->dp_amount_required)
                        <div class="flex justify-between"><dt class="text-ink-500">Uang muka</dt>
                            <dd class="m-0 tabular-nums">{{ $rp($r->dp_amount_required) }} · <span @class(['font-semibold', 'text-status-available' => $dpDibayar > 0, 'text-[#8A5E10]' => $dpDibayar <= 0])>{{ $dpDibayar > 0 ? 'dibayar' : 'belum dibayar' }}</span></dd></div>
                    @endif
                    @if (in_array($r->status, ['pending_payment', 'confirmed', 'checked_in'], true))
                        <div class="flex justify-between"><dt class="text-ink-500">Sisa dilunasi di resepsionis</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp(max(0, (float) $nota->total_amount - (float) $nota->paid_amount)) }}</dd></div>
                    @endif
                </dl>
            @endif
        @endif
    </div>

    <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div><dt class="text-xs text-ink-500">{{ $instansi ? 'Narahubung' : 'Nama pemesan' }}</dt><dd class="m-0 font-semibold">{{ $r->customer?->full_name }}</dd></div>
        <div><dt class="text-xs text-ink-500">Nomor WhatsApp</dt><dd class="m-0 font-semibold tabular-nums">{{ $hpSamar }}</dd></div>
        @if ($r->special_request && ! ($instansi && $r->status === 'waiting_approval'))
            <div class="sm:col-span-2"><dt class="text-xs text-ink-500">Permintaan khusus</dt><dd class="m-0">{{ $r->special_request }}</dd></div>
        @endif
        @php $bayarDp = $nota?->payments->where('status', 'success')->where('payment_purpose', 'down_payment')->sortBy('paid_at')->first(); @endphp
        @if ($bayarDp)
            <div><dt class="text-xs text-ink-500">Uang muka dibayar</dt><dd class="m-0 font-semibold">{{ Payment::METHODS[$bayarDp->method] ?? $bayarDp->method }} · {{ $bayarDp->paid_at?->translatedFormat('j M, H.i') }} WITA</dd></div>
        @endif
    </dl>

    {{-- Ubah tanggal dan pembatalan --}}
    @if ($bolehUbah || $bolehBatal)
        <div class="mt-8 grid gap-4 {{ $bolehUbah && $bolehBatal ? 'sm:grid-cols-2' : '' }}" x-data="{ ubah: false, batal: false }">
            @if ($bolehUbah)
                <div class="wt-card p-5">
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
                <div class="wt-card p-5">
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
    @elseif ($r->status === 'confirmed')
        <p class="mt-8 text-sm text-ink-500">Perubahan tanggal atau pembatalan kurang dari {{ ReservasiService::hariMinimalBatal() + 1 }} hari sebelum kedatangan dilayani resepsionis.</p>
    @endif

    <p class="mt-8 text-center text-xs text-ink-500">
        Simpan tautan halaman ini, atau buka Cek pesanan dengan kode {{ $r->reservation_code }} dan token {{ $r->token() }}. Ada kendala? Hubungi {{ $wisma['telepon'] ?? 'resepsionis' }}.
    </p>
</div>
</x-layouts.guest>
