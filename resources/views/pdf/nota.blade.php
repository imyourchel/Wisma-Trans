@php
    use App\Models\HallBooking;
    use App\Models\Payment;
    use App\Models\ServiceOrder;
    use Illuminate\Support\Carbon;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $angka = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $r = $nota->reservation;
    $masuk = $r?->masuk();
    $keluar = $r?->keluar();
    $dibayar = (float) $nota->paid_amount;
    $sisa = max(0, (float) $nota->total_amount - $dibayar);
    $batal = $nota->status === 'cancelled';
    $lunas = ! $batal && $sisa <= 0 && $dibayar > 0;
    [$cap, $warnaCap, $latarCap] = match (true) {
        $batal => ['Dibatalkan', '#B23B33', '#F8E5E3'],
        $lunas => ['Lunas', '#3F7D4A', '#E3F0E4'],
        $dibayar > 0 && $r?->dp_amount_required && $dibayar >= (float) $r->dp_amount_required => ['Uang muka diterima', '#8A5A0B', '#F7EBD3'],
        $dibayar > 0 => ['Dibayar sebagian', '#8A5A0B', '#F7EBD3'],
        default => ['Belum dibayar', '#B23B33', '#F8E5E3'],
    };
    $judulJenis = ['stay' => 'Nota Menginap', 'service' => 'Nota Layanan', 'hall' => 'Nota Sewa Aula'][$nota->invoice_type] ?? 'Nota';
    $satuan = ['room' => 'malam', 'late_checkout' => 'kali', 'laundry' => 'kg', 'amenity' => 'malam', 'hall' => 'acara'];
    $jenisBaris = ['room' => 'Kamar', 'fnb' => 'Makanan & minuman', 'laundry' => 'Laundry', 'amenity' => 'Perlengkapan', 'hall' => 'Sewa aula', 'late_checkout' => 'Terlambat check-out', 'other' => 'Lainnya'];
    $jenisBayar = ['down_payment' => 'Uang muka', 'settlement' => 'Pelunasan', 'service' => 'Layanan', 'hall' => 'Sewa aula', 'other' => 'Pembayaran'];
    // Rujukan pesanan untuk nota layanan dan aula
    $aula = $nota->invoice_type === 'hall' ? HallBooking::with('hall')->where('invoice_id', $nota->id)->first() : null;
    $pesanan = $nota->invoice_type === 'service' ? ServiceOrder::where('invoice_id', $nota->id)->value('order_code') : null;
    $kamar = $r?->rooms->whereNotNull('room_id')->pluck('room.room_number')->filter()->unique()->implode(', ');
    $tanggalNota = $nota->issue_date ?? $nota->created_at;
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $judulJenis }} {{ $nota->invoice_number }}</title>
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 9.5px; color: #3A2C21; line-height: 1.5; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; }
    .kanan { text-align: right; }
    .tengah { text-align: center; }
    .redup { color: #8C7B67; }
    .tebal { font-weight: bold; }

    /* Kepala */
    .kepala { background: #4E3423; }
    .kepala td { padding: 28px 44px 24px; vertical-align: middle; }
    .garis-aksen { height: 4px; background: #B5714A; }
    .tagline { margin-top: 6px; font-size: 7.5px; letter-spacing: 2.4px; text-transform: uppercase; color: #E4B98F; }
    .jenis { font-size: 8px; letter-spacing: 2.6px; text-transform: uppercase; color: #E4B98F; }
    .judul { font-size: 26px; font-weight: bold; letter-spacing: 5px; color: #FFFDF6; margin-top: 2px; }
    .nomor { font-size: 10px; color: #E5DCC6; margin-top: 2px; letter-spacing: .5px; }

    .isi { padding: 26px 44px 120px; }

    /* Ringkasan atas */
    .label { font-size: 7px; font-weight: bold; letter-spacing: 1.6px; text-transform: uppercase; color: #A0896F; margin-bottom: 4px; }
    .kartu { border: 1px solid #E8DECB; background: #FBF7EF; padding: 13px 15px; }
    .nama { font-size: 13px; font-weight: bold; color: #3E2A1C; }
    .info td { padding: 0 0 7px; }
    .cap { display: inline-block; padding: 4px 11px; font-size: 8px; font-weight: bold; letter-spacing: 1.6px; text-transform: uppercase; color: {{ $warnaCap }}; background: {{ $latarCap }}; border: 1px solid {{ $warnaCap }}; border-radius: 10px; }
    .tagihan-besar { background: #4E3423; color: #FFFDF6; padding: 13px 15px; }
    .tagihan-besar .label { color: #E4B98F; }
    .angka-besar { font-size: 19px; font-weight: bold; letter-spacing: .3px; }

    /* Rincian */
    .judul-bagian { font-size: 8px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; color: #3E2A1C; padding-bottom: 7px; }
    .rincian th { font-size: 7px; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase; color: #8C7B67; text-align: left; padding: 8px 10px; border-top: 1.5px solid #3E2A1C; border-bottom: 1px solid #D9CBAE; }
    .rincian td { padding: 9px 10px; border-bottom: 1px solid #EFE6D3; }
    .rincian tr.genap td { background: #FCFAF5; }
    .rincian .sub { font-size: 7.5px; color: #A0896F; letter-spacing: .3px; margin-top: 1px; }
    .rincian .potong { font-size: 7.5px; color: #3F7D4A; margin-top: 1px; }

    /* Jumlah */
    .jumlah td { padding: 5px 12px; }
    .jumlah .total td { padding-top: 9px; padding-bottom: 9px; border-top: 1px solid #D9CBAE; font-size: 12px; font-weight: bold; color: #3E2A1C; }
    .jumlah .sisa td { background: {{ $lunas ? '#3F7D4A' : '#B5714A' }}; color: #FFFDF6; font-weight: bold; font-size: 11px; padding-top: 9px; padding-bottom: 9px; }

    .bayar td { padding: 6px 0; border-bottom: 1px dashed #E5DCC6; }
    .catatan { border-left: 3px solid #B5714A; background: #F6F0E3; padding: 10px 14px; color: #5B4A3C; }

    /* Cap besar di latar */
    .tanda-air { position: absolute; top: 330px; left: 0; width: 100%; text-align: center; font-size: 86px; font-weight: bold; letter-spacing: 10px; color: {{ $warnaCap }}; opacity: .06; transform: rotate(-24deg); text-transform: uppercase; }

    /* Kaki di setiap halaman */
    .kaki { position: fixed; bottom: 0; left: 0; right: 0; }
    .kaki-isi { padding: 0 44px 16px; }
    .kaki-garis { border-top: 1px solid #E5DCC6; padding-top: 11px; font-size: 7.5px; color: #8C7B67; line-height: 1.6; }
    .kaki-pita { background: #4E3423; color: #E4B98F; font-size: 7px; letter-spacing: 3px; text-transform: uppercase; text-align: center; padding: 7px 0; }
</style>
</head>
<body>
    @if ($batal || $lunas)
        <div class="tanda-air">{{ $cap }}</div>
    @endif

    {{-- Kaki tetap di bawah setiap halaman --}}
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
                            Nota ini dibuat otomatis oleh sistem dan sah tanpa tanda tangan.<br>
                            Dicetak {{ now()->translatedFormat('j F Y, H.i') }} WITA
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <div class="kaki-pita">Terima kasih telah memilih Wisma Trans · Simple · Clean · Favour</div>
    </div>

    {{-- Kepala --}}
    <table class="kepala">
        <tr>
            <td style="width: 58%;">
                <img src="{{ public_path('ds/logo/wismatrans-nota.png') }}" alt="Wisma Trans" style="width: 190px; height: auto;">
                <div class="tagline">Penginapan · Resto · Aula — Malili, Luwu Timur</div>
            </td>
            <td class="kanan">
                <div class="jenis">{{ $judulJenis }}</div>
                <div class="judul">NOTA</div>
                <div class="nomor">{{ $nota->invoice_number }}</div>
            </td>
        </tr>
    </table>
    <div class="garis-aksen"></div>

    <div class="isi">
        {{-- Pihak, rincian nota, dan jumlah tagihan --}}
        <table>
            <tr>
                <td class="kartu" style="width: 34%;">
                    <div>
                        <div class="label">Ditagihkan kepada</div>
                        <div class="nama">{{ $nota->customer?->full_name }}</div>
                        @if ($r?->institution)<div class="tebal" style="color: #5B4A3C;">{{ $r->institution->name }}</div>@endif
                        <div class="redup" style="margin-top: 3px;">{{ $nota->customer?->phone }}</div>
                        @if ($nota->customer?->email)<div class="redup">{{ $nota->customer->email }}</div>@endif
                        @if ($nota->customer?->address)<div class="redup">{{ $nota->customer->address }}</div>@endif
                    </div>
                </td>
                <td style="width: 1.5%;"></td>
                <td class="kartu" style="width: 36%;">
                    <div>
                        <table class="info">
                            <tr>
                                <td style="width: 50%;"><div class="label">Tanggal nota</div>{{ $tanggalNota->translatedFormat('j M Y') }}</td>
                                <td>
                                    @if ($r)
                                        <div class="label">Reservasi</div>{{ $r->reservation_code }}
                                    @elseif ($aula)
                                        <div class="label">Pemesanan</div>{{ $aula->booking_code }}
                                    @elseif ($pesanan)
                                        <div class="label">Pesanan</div>{{ $pesanan }}
                                    @endif
                                </td>
                            </tr>
                            @if ($masuk)
                                <tr>
                                    <td><div class="label">Check-in</div>{{ $masuk->translatedFormat('D, j M Y') }}</td>
                                    <td><div class="label">Check-out</div>{{ $keluar->translatedFormat('D, j M Y') }}</td>
                                </tr>
                            @elseif ($aula)
                                <tr><td colspan="2"><div class="label">Jadwal acara · {{ $aula->hall?->name }}</div>{{ $aula->jadwal() }} WITA</td></tr>
                            @endif
                            @if ($nota->due_date && ! $lunas && ! $batal)
                                <tr><td colspan="2"><div class="label">Jatuh tempo</div><span class="tebal" style="color: #B23B33;">{{ $nota->due_date->translatedFormat('j F Y') }}</span></td></tr>
                            @elseif ($kamar)
                                <tr><td colspan="2"><div class="label">Nomor kamar</div>{{ $kamar }}</td></tr>
                            @endif
                        </table>
                    </div>
                </td>
                <td style="width: 1.5%;"></td>
                <td class="tagihan-besar" style="width: 27%;">
                    <div>
                        <div class="label">{{ $lunas ? 'Total dibayar' : ($batal ? 'Total nota' : 'Sisa tagihan') }}</div>
                        <div class="angka-besar">{{ $rp($lunas || $batal ? $nota->total_amount : $sisa) }}</div>
                        <div style="margin-top: 10px;"><span class="cap">{{ $cap }}</span></div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Rincian --}}
        <div class="judul-bagian" style="margin-top: 26px;">Rincian tagihan</div>
        <table class="rincian">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 39%;">Keterangan</th>
                    <th style="width: 14%;">Tanggal</th>
                    <th class="kanan" style="width: 11%;">Jumlah</th>
                    <th class="kanan" style="width: 15%;">Harga satuan</th>
                    <th class="kanan" style="width: 16%;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($nota->items as $item)
                    <tr @class(['genap' => $loop->even])>
                        <td class="redup">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</td>
                        <td>
                            <div class="tebal" style="color: #3E2A1C;">{{ $item->description }}</div>
                            <div class="sub">{{ $jenisBaris[$item->item_type] ?? '' }}</div>
                            @if ((float) $item->discount_amount > 0)
                                <div class="potong">Potongan{{ $item->discount_type === 'percent' ? ' '.$angka($item->discount_value).'%' : '' }}: -{{ $rp($item->discount_amount) }}</div>
                            @elseif ((float) $item->unit_price === 0.0)
                                <div class="potong">Tanpa biaya</div>
                            @endif
                        </td>
                        <td class="redup">{{ $item->service_date ? Carbon::parse($item->service_date)->translatedFormat('j M Y') : '—' }}</td>
                        <td class="kanan">{{ $angka($item->quantity) }} {{ $satuan[$item->item_type] ?? '' }}</td>
                        <td class="kanan">{{ $rp($item->unit_price) }}</td>
                        <td class="kanan tebal" style="color: #3E2A1C;">{{ $rp($item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Pembayaran dan jumlah --}}
        <table style="margin-top: 18px;">
            <tr>
                <td style="width: 54%; padding-right: 26px;">
                    @if ($nota->payments->isNotEmpty())
                        <div class="judul-bagian">Riwayat pembayaran</div>
                        <table class="bayar">
                            @foreach ($nota->payments as $bayar)
                                <tr>
                                    <td>
                                        <span class="tebal" style="color: #3E2A1C;">{{ $bayar->transaction_type === 'refund' ? 'Pengembalian dana' : ($jenisBayar[$bayar->payment_purpose] ?? 'Pembayaran') }}</span>
                                        <span class="redup">· {{ Payment::METHODS[$bayar->method] ?? $bayar->method }}</span><br>
                                        <span class="redup" style="font-size: 8px;">{{ $bayar->paid_at?->translatedFormat('j M Y, H.i') }} WITA · {{ $bayar->payment_code }}</span>
                                    </td>
                                    <td class="kanan tebal" style="color: {{ $bayar->transaction_type === 'refund' ? '#B23B33' : '#3F7D4A' }};">{{ $bayar->transaction_type === 'refund' ? '-' : '' }}{{ $rp($bayar->amount) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    @if ($sisa > 0 && ! $batal && $r && $r->status !== 'checked_out')
                        <div class="catatan" style="margin-top: 14px;">
                            Sisa pembayaran dilunasi di resepsionis saat menginap, tunai atau non-tunai. Check-in mulai pukul {{ str_replace(':', '.', substr($wisma['checkIn'] ?? '14:00', 0, 5)) }} WITA,
                            check-out paling lambat pukul {{ str_replace(':', '.', substr($wisma['checkOut'] ?? '12:00', 0, 5)) }} WITA.
                        </div>
                    @elseif ($nota->note && ! $aula && ! $pesanan)
                        <div class="catatan" style="margin-top: 14px;">{{ $nota->note }}</div>
                    @endif
                </td>
                <td style="width: 46%;">
                    <table class="jumlah" style="border: 1px solid #E8DECB; background: #FBF7EF;">
                        <tr><td class="redup" style="padding-top: 10px;">Subtotal</td><td class="kanan" style="padding-top: 10px;">{{ $rp($nota->subtotal) }}</td></tr>
                        @if ((float) $nota->discount_amount > 0)
                            <tr><td class="redup">Diskon{{ $nota->discount_type === 'percent' ? ' '.$angka($nota->discount_value).'%' : '' }}</td><td class="kanan" style="color: #3F7D4A;">-{{ $rp($nota->discount_amount) }}</td></tr>
                        @endif
                        <tr class="total"><td>Total</td><td class="kanan">{{ $rp($nota->total_amount) }}</td></tr>
                        <tr><td class="redup" style="padding-bottom: 10px;">Sudah dibayar</td><td class="kanan" style="padding-bottom: 10px;">{{ $rp($dibayar) }}</td></tr>
                        @if ($batal)
                            <tr class="sisa"><td style="background: #8C7B67;">Nota dibatalkan</td><td class="kanan" style="background: #8C7B67;">Tidak ada tagihan</td></tr>
                        @else
                            <tr class="sisa"><td>{{ $lunas ? 'Sisa tagihan · lunas' : 'Sisa yang harus dibayar' }}</td><td class="kanan">{{ $rp($sisa) }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
