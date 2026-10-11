@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $i = $t->institution;
    $lewat = $t->due_date && $t->due_date->lt(today());
    $bulanan = $t instanceof \App\Models\BillingStatement;
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Surat tagihan {{ $nomor }}</title>
<style>
    @page { margin: 56px 64px 80px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #2E2218; line-height: 1.6; }
    table { border-collapse: collapse; width: 100%; }
    td { vertical-align: top; padding: 0; }
    .kop { border-bottom: 3px double #4E3423; padding-bottom: 10px; margin-bottom: 22px; }
    .kop .nama { font-size: 18px; font-weight: bold; letter-spacing: 3px; color: #4E3423; }
    .kecil { font-size: 9.5px; color: #6B5A49; }
    .ringkas td { padding: 5px 10px; border: 1px solid #D9CBAE; }
    .ringkas td:first-child { width: 38%; background: #F8F2E7; }
    .ttd { margin-top: 44px; width: 46%; margin-left: 54%; text-align: center; }
</style>
</head>
<body>
    <div class="kop">
        <table>
            <tr>
                <td>
                    <div class="nama">WISMA TRANS</div>
                    <div class="kecil">{{ implode(', ', $wisma['alamat'] ?? []) }} · Telepon/WhatsApp {{ $wisma['telepon'] ?? '' }}</div>
                </td>
                <td style="text-align: right;" class="kecil">Malili, {{ today()->translatedFormat('j F Y') }}</td>
            </tr>
        </table>
    </div>

    <table style="width: 60%;">
        <tr><td style="width: 30%;">Nomor</td><td>: ST/{{ str_replace(['INV/', 'BS/'], '', $nomor) }}</td></tr>
        <tr><td>Perihal</td><td>: {{ $lewat ? 'Tagihan yang telah jatuh tempo' : 'Pemberitahuan tagihan' }}</td></tr>
    </table>

    <p style="margin-top: 20px;">
        Kepada Yth.<br>
        <strong>Pimpinan {{ $i->name }}</strong><br>
        @if ($penerima['nama'] !== $i->name)u.p. {{ $penerima['nama'] }}<br>@endif
        {{ $i->address ?: 'di tempat' }}
    </p>

    <p>Dengan hormat,</p>
    <p>
        Bersama surat ini kami sampaikan bahwa {{ $bulanan ? 'tagihan bulanan' : 'tagihan' }} Wisma Trans nomor <strong>{{ $nomor }}</strong>
        {{ $bulanan ? 'periode '.$t->rentang().' ' : '' }}atas nama {{ $i->name }}
        {{ $lewat ? 'telah melewati jatuh tempo pada '.$t->due_date->translatedFormat('j F Y') : 'akan jatuh tempo pada '.$t->due_date?->translatedFormat('j F Y') }}
        dengan rincian sebagai berikut:
    </p>

    <table class="ringkas" style="margin: 12px 0 16px;">
        <tr><td>Nomor tagihan</td><td>{{ $nomor }}</td></tr>
        <tr><td>Total tagihan</td><td>{{ $rp($t->total_amount) }}</td></tr>
        <tr><td>Sudah dibayar</td><td>{{ $rp($t->paid_amount) }}</td></tr>
        <tr><td><strong>Sisa yang harus dibayar</strong></td><td><strong>{{ $rp($sisa) }}</strong></td></tr>
        <tr><td>Jatuh tempo</td><td>{{ $t->due_date?->translatedFormat('j F Y') ?? '—' }}</td></tr>
        <tr><td>Rekening tujuan</td><td>{!! $rekening ? e($rekening['bank'].' '.$rekening['nomor']).'<br>a.n. '.e($rekening['nama']) : 'Hubungi resepsionis Wisma Trans' !!}</td></tr>
    </table>

    <p>
        Kami mohon pembayaran dapat dilakukan {{ $lewat ? 'secepatnya' : 'sebelum tanggal jatuh tempo' }} dengan mencantumkan nomor tagihan
        pada berita transfer. Apabila pembayaran telah dilakukan, mohon abaikan surat ini dan kirimkan bukti pembayaran kepada kami.
    </p>
    <p>Atas perhatian dan kerja samanya kami ucapkan terima kasih.</p>

    <div class="ttd">
        Hormat kami,<br>
        Manager Wisma Trans
        <div style="height: 64px;"></div>
        <div style="border-top: 1px solid #2E2218; padding-top: 4px;">(..................................................)</div>
    </div>
</body>
</html>
