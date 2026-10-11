@php
    use App\Services\Reservasi\BuktiInstansi;

    $masuk = $r->masuk();
    $keluar = $r->keluar();
    $malam = $masuk && $keluar ? (int) $masuk->diffInDays($keluar) : 0;
    $perTipe = $r->kamarBerlaku()->countBy(fn ($k) => $k->roomType->name);
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Bukti Pemesanan Instansi {{ $r->reservation_code }}</title>
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #3A2C21; line-height: 1.5; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; }
    .kanan { text-align: right; }
    .tengah { text-align: center; }
    .redup { color: #8C7B67; }

    .kepala { background: #4E3423; }
    .kepala td { padding: 28px 44px 24px; vertical-align: middle; }
    .garis-aksen { height: 4px; background: #B5714A; }
    .tagline { margin-top: 6px; font-size: 7.5px; letter-spacing: 2.4px; text-transform: uppercase; color: #E4B98F; }
    .jenis { font-size: 8px; letter-spacing: 2.6px; text-transform: uppercase; color: #E4B98F; }
    .judul { font-size: 22px; font-weight: bold; letter-spacing: 4px; color: #FFFDF6; margin-top: 2px; }
    .nomor { font-size: 11px; color: #E5DCC6; margin-top: 2px; letter-spacing: .5px; }

    .isi { padding: 30px 44px 110px; }
    .label { font-size: 7px; font-weight: bold; letter-spacing: 1.6px; text-transform: uppercase; color: #A0896F; margin-bottom: 4px; }
    .kode { border: 1.5px solid #B5714A; background: #FBF7EF; padding: 22px 18px 20px; }
    .kode-bukti { font-size: 40px; font-weight: bold; letter-spacing: 10px; color: #3E2A1C; margin-top: 4px; }
    .nama { font-size: 12px; font-weight: bold; color: #3E2A1C; }
    .info td { padding: 0 12px 14px 0; }
    .kartu { border: 1px solid #E8DECB; background: #FBF7EF; padding: 14px 18px; }
    .tipe td { padding: 6px 0; border-bottom: 1px solid #EFE6D3; }
    .pesan { border-left: 3px solid #B5714A; background: #F6F0E3; padding: 12px 16px; color: #3E2A1C; font-size: 11px; font-weight: bold; }

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
                <table>
                    <tr>
                        <td style="width: 58%;">
                            <span style="color: #3E2A1C; font-weight: bold; letter-spacing: 1px;">WISMA TRANS</span><br>
                            {{ implode(', ', $wisma['alamat'] ?? []) }}<br>
                            Resepsionis 24 jam · Telepon/WhatsApp {{ $wisma['telepon'] ?? '' }}
                        </td>
                        <td class="kanan">
                            Bukti ini dibuat otomatis oleh sistem dari data reservasi terbaru.<br>
                            Dicetak {{ now()->translatedFormat('j F Y, H.i') }} WITA
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <div class="kaki-pita">Terima kasih telah memilih Wisma Trans · Simple · Clean · Favour</div>
    </div>

    <table class="kepala">
        <tr>
            <td style="width: 58%;">
                <img src="{{ public_path('ds/logo/wismatrans-nota.png') }}" alt="Wisma Trans" style="width: 190px; height: auto;">
                <div class="tagline">Penginapan · Resto · Aula — Malili, Luwu Timur</div>
            </td>
            <td class="kanan">
                <div class="jenis">Reservasi instansi</div>
                <div class="judul">BUKTI PEMESANAN</div>
                <div class="nomor">{{ $r->reservation_code }}</div>
            </td>
        </tr>
    </table>
    <div class="garis-aksen"></div>

    <div class="isi">
        <div class="kode tengah">
            <div class="label">Kode bukti</div>
            <div class="kode-bukti">{{ $r->kodeBukti() }}</div>
        </div>

        <table class="info" style="margin-top: 24px;">
            <tr>
                <td style="width: 33%;">
                    <div class="label">Kode reservasi</div>
                    <div class="nama">{{ $r->reservation_code }}</div>
                </td>
                <td style="width: 34%;">
                    <div class="label">Instansi</div>
                    <div class="nama">{{ $r->institution?->name ?? '—' }}</div>
                </td>
                <td>
                    <div class="label">PIC / narahubung</div>
                    <div class="nama">{{ $r->customer?->full_name }}</div>
                    <div class="redup">{{ $r->customer?->phone }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">Tanggal datang</div>
                    <div class="nama">{{ $masuk?->translatedFormat('l, j F Y') }}</div>
                </td>
                <td>
                    <div class="label">Tanggal pulang</div>
                    <div class="nama">{{ $keluar?->translatedFormat('l, j F Y') }}</div>
                    <div class="redup">{{ $malam }} malam</div>
                </td>
                <td>
                    <div class="label">Jam check-in</div>
                    <div class="nama">Mulai pukul {{ $jamCheckIn }} WITA</div>
                </td>
            </tr>
        </table>

        <div class="kartu">
            <div class="label">Kamar per tipe</div>
            <table class="tipe">
                @forelse ($perTipe as $tipe => $n)
                    <tr><td>{{ $tipe }}</td><td class="kanan" style="font-weight: bold;">{{ $n }} kamar</td></tr>
                @empty
                    <tr><td colspan="2">{{ BuktiInstansi::ringkasKamar($r) }}</td></tr>
                @endforelse
                <tr><td style="border-bottom: 0; font-weight: bold;">Total</td><td class="kanan" style="border-bottom: 0; font-weight: bold;">{{ $r->kamarBerlaku()->count() ?: ($r->request?->room_count ?? 0) }} kamar</td></tr>
            </table>
        </div>

        <div class="pesan" style="margin-top: 24px;">
            Teruskan kode ini kepada staf yang ditugaskan menginap. Sebutkan atau tunjukkan di resepsionis saat kedatangan.
        </div>
        <p class="redup" style="margin-top: 12px;">
            Reservasi instansi hanya bisa check-in dengan kode bukti ini. Bila kode hilang, minta PIC menghubungi resepsionis untuk mengirim ulang.
        </p>
    </div>
</body>
</html>
