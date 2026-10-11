<?php

namespace App\Services\Laporan;

use App\Models\Payment;
use App\Support\SegmenPelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pendapatan:
 *  - diakui: baris nota yang tidak batal menurut tanggal layanannya. Diskon nota dialokasikan proporsional ke barisnya.
 *    Baris kamar (beberapa malam, service_date = malam pertama) disebar rata per malam, termasuk diskonnya.
 *    Baris tanpa tanggal layanan memakai tanggal terbit nota.
 *  - kas masuk: pembayaran berhasil dikurangi pengembalian dana menurut paid_at (termasuk tagihan bulanan).
 */
class LaporanPendapatan
{
    use MenyusunDokumen;

    public const KATEGORI = [
        'kamar' => ['Kamar', '#4E3423'], 'fnb' => ['F&B', '#B5714A'], 'laundry' => ['Laundry', '#5B86B8'],
        'aula' => ['Aula', '#5C9E67'], 'terlambat' => ['Biaya terlambat', '#D69A2E'], 'lainnya' => ['Lainnya', '#9B8B78'],
    ];

    public const CATATAN_SELISIH = 'Hunian memakai nilai kamar terjual; Pendapatan memakai nota yang sudah terbit. Selisihnya adalah tamu yang masih menginap.';

    public static function kategori(string $jenisBaris): string
    {
        return match ($jenisBaris) {
            'room' => 'kamar', 'fnb' => 'fnb', 'laundry' => 'laundry', 'hall' => 'aula', 'late_checkout' => 'terlambat',
            default => 'lainnya',
        };
    }

    /**
     * Potongan pendapatan diakui per tanggal dalam periode.
     *
     * @return Collection<int, object{tanggal: string, kategori: string, nilai: float, nota: int, pelanggan: int, instansi: ?int, b2b: bool}>
     */
    public function potongan(Periode $p): Collection
    {
        [$mulai, $akhir] = [$p->mulai->toDateString(), $p->akhir->toDateString()];
        $baris = SegmenPelanggan::joinReservasi(DB::table('invoice_items as it')->join('invoices as i', 'i.id', '=', 'it.invoice_id'))
            ->where('i.status', '!=', 'cancelled')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('it.item_type', 'room')->whereNotNull('it.service_date')->where('it.service_date', '<=', $akhir)
                    ->whereRaw('DATE_ADD(it.service_date, INTERVAL GREATEST(1, CEIL(it.quantity)) DAY) > ?', [$mulai]))
                ->orWhere(fn ($q) => $q->where(fn ($q) => $q->where('it.item_type', '!=', 'room')->orWhereNull('it.service_date'))
                    ->whereRaw('COALESCE(it.service_date, i.issue_date, DATE(i.created_at)) BETWEEN ? AND ?', [$mulai, $akhir])))
            ->selectRaw('it.item_type, it.service_date, it.quantity, it.subtotal, i.id AS nota, i.subtotal AS nota_subtotal, i.discount_amount,
                COALESCE(i.issue_date, DATE(i.created_at)) AS terbit, i.customer_id, COALESCE(i.institution_id, r.institution_id) AS instansi, '
                .SegmenPelanggan::ekspresiNota().' AS b2b')
            ->get();

        $hasil = collect();
        foreach ($baris as $b) {
            // Diskon nota dialokasikan proporsional terhadap subtotal baris
            $faktor = (float) $b->nota_subtotal > 0 ? ((float) $b->nota_subtotal - (float) $b->discount_amount) / (float) $b->nota_subtotal : 1.0;
            $nilai = (float) $b->subtotal * $faktor;
            $kategori = self::kategori($b->item_type);
            $dasar = ['kategori' => $kategori, 'nota' => (int) $b->nota, 'pelanggan' => (int) $b->customer_id,
                'instansi' => $b->instansi ? (int) $b->instansi : null, 'b2b' => (bool) $b->b2b];
            if ($b->item_type === 'room' && $b->service_date) {
                $malam = max(1, (int) round((float) $b->quantity));
                $awal = CarbonImmutable::parse($b->service_date);
                for ($i = 0; $i < $malam; $i++) {
                    $t = $awal->addDays($i)->toDateString();
                    if ($p->berisi($t)) {
                        $hasil->push((object) ([...$dasar, 'tanggal' => $t, 'nilai' => $nilai / $malam]));
                    }
                }
            } else {
                $t = substr((string) ($b->service_date ?? $b->terbit), 0, 10);
                if ($p->berisi($t)) {
                    $hasil->push((object) ([...$dasar, 'tanggal' => $t, 'nilai' => $nilai]));
                }
            }
        }

        return $hasil;
    }

    /** @return array{total: float, kategori: array<string, float>, kelompok: array<string, array<string, float>>} */
    public function diakui(Periode $p): array
    {
        $potongan = $this->potongan($p);
        $kelompok = array_fill_keys(array_keys($p->kelompok()), array_fill_keys(array_keys(self::KATEGORI), 0.0));
        foreach ($potongan as $x) {
            $kelompok[$p->kunci($x->tanggal)][$x->kategori] += $x->nilai;
        }

        return [
            'total' => (float) $potongan->sum('nilai'),
            'kategori' => collect(self::KATEGORI)->map(fn ($v, $k) => (float) $potongan->where('kategori', $k)->sum('nilai'))->all(),
            'kelompok' => $kelompok,
        ];
    }

    /** @return array{masuk: float, refund: float, bersih: float, metode: array<string, float>, kanal: array<string, float>, bulanan: float} */
    public function kasMasuk(Periode $p): array
    {
        $bayar = Payment::where('status', 'success')
            ->whereBetween('paid_at', [$p->mulai->startOfDay(), $p->akhir->endOfDay()])
            ->get(['transaction_type', 'method', 'channel', 'amount', 'billing_statement_id']);
        $bersih = fn (Collection $g) => (float) $g->sum(fn (Payment $x) => $x->jumlahBertanda());

        return [
            'masuk' => (float) $bayar->where('transaction_type', 'payment')->sum('amount'),
            'refund' => (float) $bayar->where('transaction_type', 'refund')->sum('amount'),
            'bersih' => $bersih($bayar),
            'metode' => $bayar->groupBy('method')->map($bersih)->sortDesc()->all(),
            'kanal' => ['online' => $bersih($bayar->where('channel', 'online')), 'manual' => $bersih($bayar->where('channel', 'manual'))],
            'bulanan' => (float) $bayar->whereNotNull('billing_statement_id')->sum(fn (Payment $x) => $x->jumlahBertanda()),
        ];
    }

    public function dokumen(Periode $p): array
    {
        $ini = $this->diakui($p);
        $lalu = $this->diakui($p->sebelumnya());
        $kas = $this->kasMasuk($p);
        $kasLalu = $this->kasMasuk($p->sebelumnya());

        return [
            'judul' => 'Laporan Pendapatan',
            'ringkasan' => [
                $this->kartu('Pendapatan diakui', $ini['total'], $lalu['total'], 'rp'),
                $this->kartu('Kas masuk bersih', $kas['bersih'], $kasLalu['bersih'], 'rp', 'Setelah pengembalian dana '.$this->rp($kas['refund'])),
                ...collect(self::KATEGORI)->map(fn ($v, $k) => $this->kartu($v[0], $ini['kategori'][$k], $lalu['kategori'][$k], 'rp'))->values()->all(),
            ],
            'grafik' => [[
                'judul' => 'Pendapatan diakui per '.($p->bulanan() ? 'bulan' : 'hari'),
                'format' => 'rp',
                'label' => array_values($p->kelompok()),
                'seri' => collect(self::KATEGORI)->map(fn ($v, $k) => ['nama' => $v[0], 'warna' => $v[1],
                    'nilai' => array_values(array_map(fn ($kat) => $kat[$k], $ini['kelompok']))])->values()->all(),
            ]],
            'tabel' => [
                [
                    'judul' => 'Pendapatan diakui per '.($p->bulanan() ? 'bulan' : 'hari'),
                    'kolom' => [['Tanggal', 'teks'], ...collect(self::KATEGORI)->map(fn ($v) => [$v[0], 'rp'])->values()->all(), ['Total', 'rp']],
                    'baris' => collect($ini['kelompok'])->map(fn ($kat, $kunci) => [$p->kelompok()[$kunci], ...array_values($kat), array_sum($kat)])->values()->all(),
                    'total' => ['Total', ...array_values($ini['kategori']), $ini['total']],
                ],
                [
                    'judul' => 'Kas masuk per metode',
                    'kolom' => [['Metode', 'teks'], ['Bersih', 'rp']],
                    'baris' => collect($kas['metode'])->map(fn ($n, $m) => [Payment::METHODS[$m] ?? $m, $n])->values()->all(),
                    'total' => ['Total bersih', $kas['bersih']],
                ],
                [
                    'judul' => 'Kas masuk per kanal',
                    'kolom' => [['Kanal', 'teks'], ['Bersih', 'rp']],
                    'baris' => [['Online (Midtrans)', $kas['kanal']['online']], ['Manual', $kas['kanal']['manual']],
                        ['Termasuk pembayaran tagihan bulanan', $kas['bulanan']], ['Pengembalian dana', -$kas['refund']]],
                ],
            ],
            'catatan' => [
                'Pesanan yang ditagihkan ke kamar masuk laporan setelah tamu check-out, dengan tanggal pesanannya.',
                'Pendapatan kamar disebar per malam; diskon nota dialokasikan proporsional ke baris-barisnya.',
                self::CATATAN_SELISIH,
            ],
        ];
    }
}
