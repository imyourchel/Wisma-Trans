@php
    use App\Models\Payment;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $item = $order->items->first();
    $menginap = $order->order_type === 'in_house';
    $kamar = $order->reservationRoom?->room?->room_number;
    $batal = $order->status === 'cancelled';
    $ditimbang = in_array($order->status, ['in_progress', 'ready', 'delivered'], true);
    [$kunci, $label] = $order->keadaanTamu();
    $urutan = ['pending' => 0, 'in_progress' => 1, 'ready' => 2, 'delivered' => 3];
    $posisi = $urutan[$order->status] ?? -1;
    $langkah = [
        ['Diterima', $menginap ? 'Dijemput dari kamar '.$kamar : 'Diantar ke resepsionis'],
        ['Ditimbang', 'Dicuci dan disetrika'],
        [$menginap ? 'Siap diantar' : 'Siap diambil', $menginap ? 'Diantar ke kamar' : 'Ambil di resepsionis'],
        ['Selesai', 'Terima kasih'],
    ];
    $bayarBerhasil = $order->invoice?->payments->firstWhere('status', 'success');
    $pantau = ! $batal && $order->status !== 'delivered' && ! $bayar;
@endphp

<x-layouts.guest :title="$title">
<div @class(['wt-container animate-wt-masuk py-10 sm:py-14', 'max-w-[980px]' => $bayar, 'max-w-[760px]' => ! $bayar])
     x-data="{ init() { @if ($pantau) setInterval(() => { if (document.visibilityState === 'visible') location.reload(); }, 30000); @endif } }">

    @auth
        <a href="{{ route('pesanan') }}" class="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-500 hover:text-clay-600">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Pesanan saya
        </a>
    @endauth
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="wt-eyebrow">Laundry</div>
            <h1 class="mb-0 mt-1.5 font-display text-2xl font-extrabold tracking-[.02em] tabular-nums">{{ $order->order_code }}</h1>
            <div class="mt-1 text-sm text-ink-500">{{ $order->order_date->translatedFormat('l, j F Y · H.i') }} WITA · {{ $menginap ? 'Kamar '.$kamar : 'Tamu luar' }}</div>
        </div>
        @if ($urlNota)
            <a href="{{ $urlNota }}" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-2 rounded-full bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900 hover:text-cream-50 hover:no-underline">Unduh nota (PDF)</a>
        @endif
    </div>

    @if ($bayar)
        <x-bayar-online :url-bayar="route('pesanan.bayar', ['kode' => $order->kodeUrl(), 't' => $order->access_token])"
                        :url-cek="route('pesanan.cek', ['kode' => $order->kodeUrl(), 't' => $order->access_token])"
                        :jumlah="(int) $order->invoice->total_amount" :batas="$order->batasBayar()" :tagihan="$tagihan"
                        judul="Bayar laundry" label-jumlah="Tagihan laundry"
                        keterangan="Atau bayar tunai saat {{ $menginap ? 'cucian diantar' : 'mengambil cucian' }}.">
            <dl class="m-0 mt-5 flex flex-col gap-1.5 border-t border-cream-300 pt-4 text-xs">
                <div class="flex justify-between gap-3"><dt class="text-ink-500">{{ $item?->service?->name }}</dt><dd class="m-0 text-right font-semibold">{{ $item?->note }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Harga</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp($item?->unit_price) }}/kg</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-500">Nomor nota</dt><dd class="m-0 font-semibold tabular-nums">{{ $order->invoice->invoice_number }}</dd></div>
            </dl>
        </x-bayar-online>
    @else
        <div @class(['rounded-lg border px-5 py-4',
            'border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.08)]' => $batal,
            'border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)]' => ! $batal && $kunci === 'belum-bayar',
            'border-[rgba(92,158,103,.35)] bg-[rgba(92,158,103,.10)]' => ! $batal && $kunci !== 'belum-bayar']) role="status">
            <div class="font-display text-base font-bold">{{ $label }}</div>
            <p class="mb-0 mt-1 text-sm text-ink-900/80">
                @switch($order->status)
                    @case('pending') {{ $menginap ? 'Petugas kami segera menjemput cucian ke kamar '.$kamar.'.' : 'Antar cucian ke resepsionis Wisma Trans. Kami menimbangnya lalu mengirim tagihan ke WhatsApp Anda.' }} @break
                    @case('in_progress') Cucian sedang dicuci, perkiraan selesai {{ $order->updated_at->copy()->addDay()->translatedFormat('l, j M · H.i') }} WITA. @break
                    @case('ready') {{ $menginap ? 'Petugas kami segera mengantar cucian ke kamar Anda.' : 'Silakan ambil di resepsionis Wisma Trans.' }} @break
                    @case('delivered') Terima kasih sudah memakai layanan laundry Wisma Trans. @break
                    @default Pesanan laundry ini dibatalkan.
                @endswitch
                @if ($kunci === 'belum-bayar' && ! $bayar) Tagihan dibayar tunai saat {{ $menginap ? 'cucian diantar' : 'mengambil cucian' }}. @endif
            </p>
        </div>
    @endif

    @unless ($batal)
        <ol class="mt-8 grid grid-cols-4 gap-2 p-0" aria-label="Tahapan laundry">
            @foreach ($langkah as $i => [$nama, $ket])
                @php $kini = $i === $posisi && $order->status !== 'delivered'; @endphp
                <li class="relative flex list-none flex-col items-center text-center">
                    @if ($i > 0)<span @class(['absolute right-1/2 top-[15px] h-0.5 w-full -translate-x-[16px]', 'bg-status-available' => $posisi >= $i, 'bg-cream-300' => $posisi < $i])></span>@endif
                    <span @class(['relative z-[1] flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-bold',
                        'border-status-available bg-status-available text-cream-50' => $posisi > $i || $order->status === 'delivered',
                        'border-clay-500 bg-cream-50 text-clay-600 ring-4 ring-[rgba(181,113,74,.15)]' => $kini,
                        'border-cream-300 bg-cream-50 text-ink-500' => $posisi < $i])>
                        @if ($posisi > $i || $order->status === 'delivered')
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        @else
                            {{ $i + 1 }}
                        @endif
                    </span>
                    <span @class(['mt-2 text-[13px] font-semibold leading-tight', 'text-ink-500' => $posisi < $i])>{{ $nama }}</span>
                    <span class="mt-0.5 hidden text-xs leading-tight text-ink-500 sm:block">{{ $ket }}</span>
                </li>
            @endforeach
        </ol>
    @endunless

    @unless ($bayar)
        <div class="wt-card mt-8 overflow-hidden">
            <div class="flex items-center justify-between gap-4 px-5 py-3.5 text-sm">
                <div>
                    <div class="font-semibold">{{ $item?->service?->name }}</div>
                    <div class="text-xs text-ink-500">{{ $item?->note }} · {{ $rp($item?->unit_price) }}/kg</div>
                </div>
                <div class="text-right">
                    <div class="font-display text-lg font-extrabold tabular-nums">{{ $ditimbang ? $rp($item?->subtotal) : '±'.$rp($item?->subtotal) }}</div>
                    <div class="text-xs text-ink-500">{{ $ditimbang ? 'hasil timbang' : 'menunggu ditimbang' }}</div>
                </div>
            </div>
            @if ($order->note)
                <div class="border-t border-cream-300 px-5 py-3 text-sm"><span class="text-ink-500">Catatan:</span> {{ $order->note }}</div>
            @endif
            <div class="flex flex-wrap justify-between gap-3 border-t border-cream-300 bg-cream-100 px-5 py-3.5 text-sm">
                <span class="text-ink-500">Pembayaran</span>
                <span class="font-semibold">
                    @if ($order->payment_mode === 'charge_to_room')
                        Ditagihkan ke kamar {{ $kamar }}
                    @elseif ($bayarBerhasil)
                        Lunas · {{ Payment::METHODS[$bayarBerhasil->method] ?? $bayarBerhasil->method }}
                    @elseif ($order->invoice)
                        Belum dibayar
                    @else
                        Setelah ditimbang
                    @endif
                </span>
            </div>
        </div>
    @endunless

    <p class="mt-8 text-center text-xs text-ink-500">Simpan tautan halaman ini untuk memantau cucian. Ada kendala? Hubungi {{ $wisma['telepon'] ?? 'resepsionis' }}.</p>
</div>
</x-layouts.guest>
