@php
    use App\Models\Payment;
    use App\Models\ServiceOrder;
    use App\Services\Keuangan\PembayaranMidtrans;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    // Dari Cek Pesanan: lihat saja, token tidak boleh muncul di halaman ini
    $hanyaLihat ??= false;
    $dibayar = $order->sudahDibayar();
    $batal = $order->status === 'cancelled';
    $ambil = $order->serving_type === 'take_away';
    $antar = $order->serving_type === 'room_service';
    // Tamu menginap yang menagihkan ke kamar: langsung diproses dapur tanpa menunggu pembayaran
    $tagihKamar = $order->payment_mode === 'charge_to_room';
    $nomorKamar = $order->reservationRoom?->room?->room_number;
    $masukDapur = $dibayar || $tagihKamar;
    $bayarBerhasil = $order->invoice?->payments->firstWhere('status', 'success');
    $batasBayar = $order->batasBayar();
    // Belum dibayar: tamu memilih metode dan melihat nomor VA atau QRIS langsung di halaman ini
    $siapBayar = ! $masukDapur && ! $batal && PembayaranMidtrans::siap();
    $total = (int) ($order->invoice?->total_amount ?? $order->items->sum('subtotal'));
    $hp = $order->customer?->phone;
    $hpSamar = $hp ? substr($hp, 0, 4).str_repeat('•', max(0, strlen($hp) - 7)).substr($hp, -3) : '—';

    $urutan = ['pending' => 0, 'in_progress' => 1, 'ready' => 2, 'delivered' => 3];
    $posisi = $batal ? -1 : ($masukDapur ? $urutan[$order->status] ?? 0 : -1);
    $langkah = [
        $tagihKamar ? ['Dipesan', 'Masuk tagihan kamar'] : ['Dibayar', $dibayar ? 'Pembayaran diterima' : 'Menunggu pembayaran'],
        ['Disiapkan', 'Dapur menyiapkan pesanan'],
        match (true) {
            $ambil => ['Siap diambil', 'Ambil di restoran'],
            $antar => ['Siap diantar', 'Diantar ke kamar '.$nomorKamar],
            default => ['Siap disajikan', 'Disajikan di meja Anda'],
        },
        ['Selesai', 'Terima kasih'],
    ];
    // Langkah ke-i selesai bila posisi pesanan sudah melewatinya (dibayar atau dipesan = langkah 0 selesai)
    $selesai = fn ($i) => $masukDapur && ($i === 0 || $posisi >= $i);

    [$judul, $pesan, $warna] = match (true) {
        $batal && ($tagihKamar || $dibayar) => ['Pesanan dibatalkan', 'Pesanan ini dibatalkan restoran. Hubungi resepsionis bila ada pertanyaan.', 'dirty'],
        $batal => ['Pesanan dibatalkan', 'Pembayaran tidak diterima sampai batas waktu. Silakan pesan ulang dari halaman Resto.', 'dirty'],
        ! $masukDapur => ['Menunggu pembayaran', 'Selesaikan pembayaran sebelum '.$batasBayar->format('H.i').' WITA. Pesanan diproses dapur setelah pembayaran berhasil.', 'maintenance'],
        $order->status === 'ready' => match (true) {
            $ambil => ['Pesanan Anda siap diambil di restoran', 'Tunjukkan kode pesanan ini kepada staf restoran.', 'available'],
            $antar => ['Pesanan Anda siap diantar', 'Staf kami segera mengantar pesanan ke kamar '.$nomorKamar.'.', 'available'],
            default => ['Pesanan Anda siap disajikan', 'Staf kami segera mengantar pesanan ke meja Anda.', 'available'],
        },
        $order->status === 'in_progress' => ['Pesanan sedang disiapkan', 'Dapur sedang menyiapkan pesanan Anda. Halaman ini diperbarui otomatis.', 'available'],
        $order->status === 'delivered' => ['Pesanan selesai', 'Terima kasih sudah memesan di Wisma Trans.', 'available'],
        $tagihKamar => ['Pesanan diterima', 'Pesanan Anda masuk antrean dapur dan ditagihkan ke kamar '.$nomorKamar.'. Halaman ini diperbarui otomatis.', 'available'],
        default => ['Pembayaran berhasil', 'Pesanan Anda masuk antrean dapur. Halaman ini diperbarui otomatis.', 'available'],
    };
    $gaya = [
        'dirty' => 'border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.08)] text-status-dirty',
        'maintenance' => 'border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] text-[#8A5E10]',
        'available' => 'border-[rgba(92,158,103,.35)] bg-[rgba(92,158,103,.10)] text-status-available',
    ][$warna];
    // Muat ulang otomatis hanya setelah dibayar, agar formulir bayar yang sedang diisi tidak hilang
    $pantau = $masukDapur && ! $batal && $order->status !== 'delivered';
@endphp

<x-layouts.guest :title="$title">
<div @class(["wt-container animate-wt-masuk py-10 sm:py-14", "max-w-[980px]" => $siapBayar, "max-w-[760px]" => ! $siapBayar])
     x-data="statusPesanan(@js(['pantau' => $pantau]))">

    @auth
        <a href="{{ route('pesanan') }}" class="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-500 hover:text-clay-600">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Pesanan saya
        </a>
    @endauth
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="wt-eyebrow">Pesanan makanan</div>
            <h1 class="mb-0 mt-1.5 font-display text-2xl font-extrabold tracking-[.02em] tabular-nums">{{ $order->order_code }}</h1>
            <div class="mt-1 text-sm text-ink-500">
                {{ $order->order_date->translatedFormat('l, j F Y · H.i') }} WITA · {{ ServiceOrder::SERVING[$order->serving_type] ?? $order->serving_type }}@if ($nomorKamar) · Kamar {{ $nomorKamar }}@endif
            </div>
        </div>
        @unless ($hanyaLihat)
        <button type="button" @click="salin()" class="inline-flex h-9 items-center gap-2 rounded-full border border-cream-300 bg-cream-50 px-4 text-[13px] text-brown-800 hover:bg-cream-200">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            Salin tautan pesanan
        </button>
        @endunless
    </div>

    @if ($hanyaLihat)
        <x-tamu.mode-lihat jenis="makanan" :kode="$order->kodeUrl()" :hp="$order->customer?->phone" :telepon="$wisma['telepon'] ?? '0822-9222-4111'" />
    @endif

    {{-- Pembayaran: pilih metode, lalu nomor VA atau QRIS tampil di kartu ini --}}
    @if ($siapBayar)
        {{-- Lihat saja: tanpa token, endpoint bayar dan cek memeriksa izin di session --}}
        <x-bayar-online :url-bayar="route('pesanan.bayar', array_filter(['kode' => $order->kodeUrl(), 't' => $hanyaLihat ? null : $order->access_token]))"
                        :url-cek="route('pesanan.cek', array_filter(['kode' => $order->kodeUrl(), 't' => $hanyaLihat ? null : $order->access_token]))"
                        :jumlah="$total" :batas="$batasBayar" :tagihan="$tagihan ?? null"
                        keterangan="Pesanan diproses dapur setelah pembayaran berhasil.">
            <div class="mt-5 border-t border-cream-300 pt-4 font-display text-[11px] font-bold uppercase tracking-[.12em] text-ink-500">Rincian pesanan</div>
            <ul class="m-0 mt-2 flex list-none flex-col gap-2.5 p-0 text-sm">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-3">
                        <span class="min-w-0">
                            <span class="block">{{ $item->service?->name }} <span class="text-ink-500">×{{ (float) $item->quantity }}</span></span>
                            @if ($item->note)<span class="block text-xs italic text-ink-500">Catatan: {{ $item->note }}</span>@endif
                        </span>
                        <span class="flex-none tabular-nums">{{ $rp($item->subtotal) }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($order->note)
                <p class="mb-0 mt-3 text-xs text-ink-500">Catatan untuk restoran: <span class="text-ink-900">{{ $order->note }}</span></p>
            @endif

            <dl class="m-0 mt-5 flex flex-col gap-1.5 border-t border-cream-300 pt-4 text-xs">
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Pemesan</dt><dd class="m-0 truncate font-semibold">{{ $order->customer?->full_name }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Penyajian</dt><dd class="m-0 font-semibold">{{ ServiceOrder::SERVING[$order->serving_type] ?? $order->serving_type }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Nomor nota</dt><dd class="m-0 font-semibold tabular-nums">{{ $order->invoice?->invoice_number }}</dd></div>
            </dl>
        </x-bayar-online>
    @else
        {{-- Status utama --}}
        <div class="rounded-lg border px-5 py-4 {{ $gaya }}" role="status">
            <div class="font-display text-base font-bold">{{ $judul }}</div>
            <p class="mb-0 mt-1 text-sm text-ink-900/80">{{ $pesan }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($batal)
                    <a href="{{ route('menu') }}" class="inline-flex h-10 items-center rounded bg-clay-500 px-5 font-display text-xs font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">Pesan lagi</a>
                @endif
                @if ($pantau)
                    <button type="button" @click="location.reload()" class="h-10 rounded border border-cream-400 bg-cream-50 px-5 text-[13px] text-brown-800 hover:bg-cream-200">Muat ulang status</button>
                @endif
            </div>
        </div>
    @endif

    {{-- Garis waktu --}}
    @unless ($batal || ! $masukDapur)
        <ol class="mt-8 grid grid-cols-4 gap-2 p-0" aria-label="Tahapan pesanan">
            @foreach ($langkah as $i => [$nama, $ket])
                @php $sudah = $selesai($i); $kini = ! $sudah && ($i === 0 ? ! $dibayar : $selesai($i - 1)); @endphp
                <li class="relative flex list-none flex-col items-center text-center">
                    @if ($i > 0)
                        <span @class(['absolute right-1/2 top-[15px] h-0.5 w-full -translate-x-[16px]', 'bg-status-available' => $sudah, 'bg-cream-300' => ! $sudah])></span>
                    @endif
                    <span @class(['relative z-[1] flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-bold',
                        'border-status-available bg-status-available text-cream-50' => $sudah,
                        'border-clay-500 bg-cream-50 text-clay-600 ring-4 ring-[rgba(181,113,74,.15)]' => $kini,
                        'border-cream-300 bg-cream-50 text-ink-500' => ! $sudah && ! $kini])>
                        @if ($sudah)
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        @else
                            {{ $i + 1 }}
                        @endif
                    </span>
                    <span @class(['mt-2 text-[13px] font-semibold leading-tight', 'text-ink-500' => ! $sudah && ! $kini])>{{ $nama }}</span>
                    <span class="mt-0.5 hidden text-xs leading-tight text-ink-500 sm:block">{{ $ket }}</span>
                </li>
            @endforeach
        </ol>
    @endunless

    {{-- Rincian --}}
    @unless ($siapBayar)
    <div class="wt-card mt-8 overflow-hidden">
        <div class="border-b border-cream-300 px-5 py-3.5 font-display text-[13px] font-bold uppercase tracking-[.08em]">Rincian pesanan</div>
        <div class="divide-y divide-cream-300">
            @foreach ($order->items as $item)
                <div class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                    <div class="min-w-0">
                        <div class="font-semibold">{{ $item->service?->name }}</div>
                        <div class="text-xs tabular-nums text-ink-500">{{ $rp($item->unit_price) }} × {{ (float) $item->quantity }}</div>
                        @if ($item->note)
                            <div class="mt-1 text-xs italic text-ink-900">Catatan: {{ $item->note }}</div>
                        @endif
                    </div>
                    <div class="font-semibold tabular-nums">{{ $rp($item->subtotal) }}</div>
                </div>
            @endforeach
        </div>
        @if ($order->note)
            <div class="border-t border-cream-300 px-5 py-3 text-sm"><span class="text-ink-500">Catatan untuk restoran:</span> {{ $order->note }}</div>
        @endif
        <div class="flex items-baseline justify-between border-t border-cream-300 bg-cream-100 px-5 py-4">
            <span class="text-sm text-ink-500">Total</span>
            <span class="font-display text-xl font-extrabold tabular-nums">{{ $rp($total) }}</span>
        </div>
    </div>

    <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div><dt class="text-xs text-ink-500">Nama pemesan</dt><dd class="m-0 font-semibold">{{ $order->customer?->full_name }}</dd></div>
        @if ($nomorKamar)
            <div><dt class="text-xs text-ink-500">Kamar</dt><dd class="m-0 font-semibold tabular-nums">Kamar {{ $nomorKamar }}</dd></div>
        @else
            <div><dt class="text-xs text-ink-500">Nomor WhatsApp</dt><dd class="m-0 font-semibold tabular-nums">{{ $hpSamar }}</dd></div>
        @endif
        @if ($order->invoice)
            <div><dt class="text-xs text-ink-500">Nomor nota</dt><dd class="m-0 font-semibold tabular-nums">{{ $order->invoice->invoice_number }}</dd></div>
        @endif
        <div>
            <dt class="text-xs text-ink-500">Pembayaran</dt>
            <dd class="m-0 font-semibold">
                @if ($tagihKamar)
                    Masuk tagihan kamar, dibayar saat check-out
                @elseif ($bayarBerhasil)
                    {{ Payment::METHODS[$bayarBerhasil->method] ?? $bayarBerhasil->method }} · {{ $bayarBerhasil->paid_at?->format('H.i') }} WITA
                @elseif ($batal)
                    Tidak dibayar
                @else
                    Belum dibayar
                @endif
            </dd>
        </div>
    </dl>
    @endunless

    <x-ajakan-akun :hp="$order->customer?->phone" class="mt-8" />

    <p class="mt-8 text-center text-xs text-ink-500">
        {{ $hanyaLihat ? 'Halaman ini dibuka dari Cek pesanan dan berlaku sementara.' : 'Simpan tautan halaman ini untuk memantau pesanan.' }} Ada kendala? Hubungi {{ $wisma['telepon'] ?? 'resepsionis' }}.
    </p>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('statusPesanan', (cfg) => ({
            init() {
                // Perbarui status otomatis setelah dibayar (dapur memproses pesanan)
                if (cfg.pantau) setInterval(() => { if (document.visibilityState === 'visible') location.reload(); }, 30000);
            },
            salin() {
                navigator.clipboard?.writeText(location.href)
                    .then(() => Alpine.store('toasts').tampil('Tautan pesanan disalin'))
                    .catch(() => Alpine.store('toasts').tampil('Salin tautan dari bilah alamat browser', '#E8AC45'));
            },
        }));
    });
</script>
@endpush
</x-layouts.guest>
