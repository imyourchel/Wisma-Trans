@php
    use App\Models\Payment;
    use App\Services\Reservasi\FolioService;

    $rp = fn ($n) => FolioService::rp($n);
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rekap kas {{ $rekap['tanggal']->translatedFormat('j F Y') }} · Wisma Trans</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px; font: 13px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #37281C; background: #fff; }
        h1 { margin: 0; font-size: 18px; letter-spacing: .04em; text-transform: uppercase; }
        .sub { color: #7A6A58; margin: 2px 0 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #E4D9C8; text-align: left; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #7A6A58; background: #F6F0E6; }
        .r { text-align: right; font-variant-numeric: tabular-nums; }
        .total td { font-weight: 700; border-top: 2px solid #37281C; }
        .ringkas { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .ringkas div { border: 1px solid #E4D9C8; border-radius: 6px; padding: 10px 12px; }
        .ringkas span { display: block; font-size: 11px; color: #7A6A58; }
        .ringkas strong { font-size: 16px; }
        .ttd { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; text-align: center; }
        .ttd div { padding-top: 64px; border-bottom: 1px solid #37281C; }
        .alat { margin-bottom: 20px; }
        .alat button { padding: 8px 16px; border: 0; border-radius: 4px; background: #4A3426; color: #fff; font-weight: 600; cursor: pointer; }
        @media print { .alat { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="alat"><button type="button" onclick="window.print()">Cetak</button></div>
    <h1>Rekap kas Wisma Trans</h1>
    <p class="sub">{{ $rekap['tanggal']->translatedFormat('l, j F Y') }} · dicetak {{ now()->translatedFormat('j M Y H.i') }} oleh {{ $oleh->name }}</p>

    <div class="ringkas">
        <div><span>Total masuk</span><strong>{{ $rp($rekap['masuk']) }}</strong></div>
        <div><span>Pengembalian dana</span><strong>−{{ $rp($rekap['refund']) }}</strong></div>
        <div><span>Online / manual</span><strong>{{ $rp($rekap['kanal']['online']) }} / {{ $rp($rekap['kanal']['manual']) }}</strong></div>
        <div><span>Total bersih</span><strong>{{ $rp($rekap['bersih']) }}</strong></div>
    </div>

    <table>
        <thead><tr><th>Metode</th><th class="r">Masuk</th><th class="r">Dikembalikan</th><th class="r">Bersih</th></tr></thead>
        <tbody>
            @forelse ($rekap['metode'] as $m => $x)
                <tr><td>{{ Payment::METHODS[$m] ?? $m }}</td><td class="r">{{ $rp($x['masuk']) }}</td><td class="r">{{ $x['keluar'] > 0 ? '−'.$rp($x['keluar']) : '—' }}</td><td class="r">{{ $rp($x['bersih']) }}</td></tr>
            @empty
                <tr><td colspan="4">Belum ada transaksi berhasil.</td></tr>
            @endforelse
            <tr class="total"><td>Total</td><td class="r">{{ $rp($rekap['masuk']) }}</td><td class="r">−{{ $rp($rekap['refund']) }}</td><td class="r">{{ $rp($rekap['bersih']) }}</td></tr>
        </tbody>
    </table>

    <table>
        <thead><tr><th>Waktu</th><th>Kode</th><th>Nota</th><th>Pelanggan</th><th>Metode</th><th>Kanal</th><th>Penerima</th><th class="r">Jumlah</th></tr></thead>
        <tbody>
            @foreach ($transaksi as $p)
                <tr>
                    <td>{{ $p->paid_at?->format('H.i') }}</td>
                    <td>{{ $p->payment_code }}</td>
                    <td>{{ $p->invoice?->invoice_number ?? '—' }}</td>
                    <td>{{ $p->invoice?->customer?->full_name }}</td>
                    <td>{{ Payment::METHODS[$p->method] ?? $p->method }}</td>
                    <td>{{ $p->channel === 'online' ? 'Online' : 'Manual' }}</td>
                    <td>{{ $p->receivedBy?->name ?? ($p->channel === 'online' ? 'Midtrans' : '—') }}</td>
                    <td class="r">{{ $p->transaction_type === 'refund' ? '−' : '' }}{{ $rp($p->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="ttd">
        <div>Petugas</div>
        <div>Manager</div>
    </div>
</body>
</html>
