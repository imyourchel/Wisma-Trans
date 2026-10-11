@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $i = $s->institution;
    $kontak = $i->primaryContact;
    $sisa = max(0, (float) $s->total_amount - (float) $s->paid_amount);
    $lunas = $s->status === 'paid';
    // Uraian per nota: kamar dan tamu untuk menginap, acara untuk aula, pesanan untuk layanan
    $uraian = function ($n) {
        if ($n->invoice_type === 'stay') {
            $kamar = $n->reservation?->rooms->pluck('room.room_number')->filter()->unique()->implode(', ');

            return 'Menginap '.($n->reservation?->reservation_code ?? '').($kamar ? ', kamar '.$kamar : '')
                .($n->period_start ? ' (periode '.$n->period_start->translatedFormat('j M').' – '.$n->period_end->translatedFormat('j M').')' : '');
        }
        if ($n->invoice_type === 'hall') {
            return 'Sewa '.($n->hallBooking?->hall?->name ?? 'aula').($n->hallBooking ? ' '.$n->hallBooking->booking_code : '');
        }

        return $n->note ?: 'Layanan';
    };
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Tagihan bulanan {{ $s->statement_number }}</title>
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 9.5px; color: #3A2C21; line-height: 1.5; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; }
    .kanan { text-align: right; }
    .redup { color: #8C7B67; }
    .kepala { background: #4E3423; }
    .kepala td { padding: 28px 44px 24px; vertical-align: middle; }
    .garis-aksen { height: 4px; background: #B5714A; }
    .tagline { margin-top: 6px; font-size: 7.5px; letter-spacing: 2.4px; text-transform: uppercase; color: #E4B98F; }
    .jenis { font-size: 8px; letter-spacing: 2.6px; text-transform: uppercase; color: #E4B98F; }
    .judul { font-size: 22px; font-weight: bold; letter-spacing: 4px; color: #FFFDF6; margin-top: 2px; }
    .nomor { font-size: 10px; color: #E5DCC6; margin-top: 2px; letter-spacing: .5px; }
    .isi { padding: 26px 44px 110px; }
    .label { font-size: 7px; font-weight: bold; letter-spacing: 1.6px; text-transform: uppercase; color: #A0896F; margin-bottom: 4px; }
    .kartu { border: 1px solid #E8DECB; background: #FBF7EF; padding: 13px 15px; }
    .nama { font-size: 13px; font-weight: bold; color: #3E2A1C; }
    .tagihan-besar { background: #4E3423; color: #FFFDF6; padding: 13px 15px; }
    .tagihan-besar .label { color: #E4B98F; }
    .angka-besar { font-size: 19px; font-weight: bold; }
    .judul-bagian { font-size: 8px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; color: #3E2A1C; padding-bottom: 7px; }
    .rincian th { font-size: 7px; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase; color: #8C7B67; text-align: left; padding: 8px 10px; border-top: 1.5px solid #3E2A1C; border-bottom: 1px solid #D9CBAE; }
    .rincian td { padding: 8px 10px; border-bottom: 1px solid #EFE6D3; }
    .jumlah td { padding: 5px 12px; }
    .jumlah .total td { padding-top: 9px; padding-bottom: 9px; border-top: 1px solid #D9CBAE; font-size: 12px; font-weight: bold; color: #3E2A1C; }
    .jumlah .sisa td { background: {{ $lunas ? '#3F7D4A' : '#B5714A' }}; color: #FFFDF6; font-weight: bold; font-size: 11px; padding-top: 9px; padding-bottom: 9px; }
    .catatan { border-left: 3px solid #B5714A; background: #F6F0E3; padding: 10px 14px; color: #5B4A3C; }
    .kaki { position: fixed; bottom: 0; left: 0; right: 0; }
    .kaki-isi { padding: 0 44px 16px; }
    .kaki-garis { border-top: 1px solid #E5DCC6; padding-top: 11px; font-size: 7.5px; color: #8C7B67; line-height: 1.6; }
    .kaki-pita { background: #4E3423; color: #E4B98F; font-size: 7px; letter-spacing: 3px; text-transform: uppercase; text-align: center; padding: 7px 0; }
</style>
</head>
<body>
    <div class="kaki">
        <div class="kaki-isi">
            <div class="kaki-garis">
                <span style="color: #3E2A1C; font-weight: bold; letter-spacing: 1px;">WISMA TRANS</span> · {{ implode(', ', $wisma['alamat'] ?? []) }}
                · Telepon/WhatsApp {{ $wisma['telepon'] ?? '' }}<br>
                Tagihan ini dibuat otomatis oleh sistem dan sah tanpa tanda tangan. Dicetak {{ now()->translatedFormat('j F Y, H.i') }} WITA
            </div>
        </div>
        <div class="kaki-pita">Terima kasih atas kepercayaan Anda kepada Wisma Trans</div>
    </div>

    <table class="kepala">
        <tr>
            <td style="width: 58%;">
                <img src="{{ public_path('ds/logo/wismatrans-nota.png') }}" alt="Wisma Trans" style="width: 190px; height: auto;">
                <div class="tagline">Penginapan · Resto · Aula — Malili, Luwu Timur</div>
            </td>
            <td class="kanan">
                <div class="jenis">Periode {{ $s->rentang() }}</div>
                <div class="judul">TAGIHAN BULANAN</div>
                <div class="nomor">{{ $s->statement_number }}</div>
            </td>
        </tr>
    </table>
    <div class="garis-aksen"></div>

    <div class="isi">
        <table>
            <tr>
                <td style="width: 56%; padding-right: 14px;">
                    <div class="kartu">
                        <div class="label">Ditagihkan kepada</div>
                        <div class="nama">{{ $i->name }}</div>
                        @if ($i->address)<div>{{ $i->address }}</div>@endif
                        @if ($kontak)<div class="redup">u.p. {{ $kontak->full_name }}{{ $kontak->phone ? ' · '.$kontak->phone : '' }}</div>@endif
                    </div>
                </td>
                <td>
                    <div class="tagihan-besar">
                        <div class="label">{{ $lunas ? 'Lunas' : 'Jumlah tagihan' }}</div>
                        <div class="angka-besar">{{ $rp($lunas ? $s->total_amount : $sisa) }}</div>
                        <div style="margin-top: 4px; color: #E5DCC6;">
                            Terbit {{ ($s->issue_date ?? today())->translatedFormat('j F Y') }}<br>
                            Jatuh tempo <strong style="color: #FFFDF6;">{{ $s->due_date->translatedFormat('j F Y') }}</strong>
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="judul-bagian" style="margin-top: 22px;">Rincian nota</div>
        <table class="rincian">
            <thead><tr><th style="width: 20%;">Nota</th><th>Uraian</th><th style="width: 14%;">Tanggal</th><th class="kanan" style="width: 16%;">Jumlah</th></tr></thead>
            <tbody>
                @foreach ($s->invoices as $n)
                    <tr>
                        <td>{{ $n->invoice_number }}</td>
                        <td>{{ $uraian($n) }}<div class="redup" style="font-size: 7.5px;">{{ $n->customer?->full_name }}</div></td>
                        <td>{{ ($n->period_end ?? $n->issue_date)?->translatedFormat('j M Y') }}</td>
                        <td class="kanan">{{ $rp($n->total_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table style="margin-top: 10px;">
            <tr>
                <td style="width: 52%; padding-right: 14px;">
                    <div class="catatan">
                        <div class="label" style="color: #8C6A44;">Pembayaran ke</div>
                        @if ($rekening)
                            <strong>{{ $rekening['bank'] }} {{ $rekening['nomor'] }}</strong><br>a.n. {{ $rekening['nama'] }}<br>
                        @else
                            Rekening belum diatur; hubungi resepsionis Wisma Trans.<br>
                        @endif
                        Cantumkan nomor {{ $s->statement_number }} pada berita transfer.
                    </div>
                </td>
                <td>
                    <table class="jumlah">
                        <tr><td>Total {{ $s->invoices->count() }} nota</td><td class="kanan">{{ $rp($s->total_amount) }}</td></tr>
                        @if ((float) $s->paid_amount > 0)
                            <tr><td>Sudah dibayar</td><td class="kanan">−{{ $rp($s->paid_amount) }}</td></tr>
                        @endif
                        <tr class="total"><td>Total tagihan</td><td class="kanan">{{ $rp($s->total_amount) }}</td></tr>
                        <tr class="sisa"><td>{{ $lunas ? 'Lunas' : 'Sisa yang harus dibayar' }}</td><td class="kanan">{{ $rp($sisa) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
