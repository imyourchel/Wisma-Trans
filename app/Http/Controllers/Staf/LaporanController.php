<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Services\Laporan\Format;
use App\Services\Laporan\LaporanHunian;
use App\Services\Laporan\LaporanOperasional;
use App\Services\Laporan\LaporanPelanggan;
use App\Services\Laporan\LaporanPendapatan;
use App\Services\Laporan\Periode;
use App\Support\BerkasCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan Manager (pengganti rekap harian Excel). Setiap laporan disusun sekali sebagai dokumen
 * (ringkasan, grafik, tabel, catatan) lalu ditampilkan sebagai halaman, PDF (?format=pdf), atau CSV (?format=csv)
 * dengan penyaring periode yang sama.
 */
class LaporanController extends Controller
{
    public function pendapatan(Request $request, LaporanPendapatan $laporan): View|Response|StreamedResponse
    {
        return $this->tampil($request, 'pendapatan', fn (Periode $p) => $laporan->dokumen($p));
    }

    public function hunian(Request $request, LaporanHunian $laporan): View|Response|StreamedResponse
    {
        return $this->tampil($request, 'hunian', fn (Periode $p) => $laporan->dokumen($p));
    }

    public function pelanggan(Request $request, LaporanPelanggan $laporan): View|Response|StreamedResponse
    {
        return $this->tampil($request, 'pelanggan', fn (Periode $p) => $laporan->dokumen($p));
    }

    public function operasional(Request $request, LaporanOperasional $laporan): View|Response|StreamedResponse
    {
        return $this->tampil($request, 'operasional', fn (Periode $p) => $laporan->dokumen($p));
    }

    private function tampil(Request $request, string $jenis, callable $susun): View|Response|StreamedResponse
    {
        $p = Periode::dariRequest($request);
        $d = $susun($p);
        $nama = 'Laporan-'.Str::ucfirst($jenis).'-'.$p->mulai->format('Ymd').'-'.$p->akhir->format('Ymd');

        return match ($request->query('format')) {
            'csv' => $this->csv($d, $p, $nama.'.csv'),
            'pdf' => response(Pdf::loadView('pdf.laporan', ['d' => $d, 'p' => $p])->setPaper('a4', 'landscape')->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$nama.'.pdf"',
            ]),
            default => view('staf.laporan.halaman', ['d' => $d, 'p' => $p, 'jenis' => $jenis]),
        };
    }

    /** CSV untuk Excel berbahasa Indonesia: BOM UTF-8, pemisah titik koma, angka tanpa pemisah ribuan. */
    private function csv(array $d, Periode $p, string $nama): StreamedResponse
    {
        return BerkasCsv::unduh($nama, function (callable $tulis) use ($d, $p) {
            $tulis([$d['judul']]);
            $tulis(['Periode', $p->label(), 'Pembanding', $p->sebelumnya()->label()]);
            $tulis([]);
            $tulis(['Ringkasan', 'Nilai', 'Periode sebelumnya', 'Perubahan %']);
            foreach ($d['ringkasan'] as $k) {
                $u = Format::perubahan($k['nilai'], $k['sebelum']);
                $tulis([$k['label'], Format::csv($k['nilai'], $k['format']), Format::csv($k['sebelum'], $k['format']), Format::csv($u['persen'], 'persen')]);
            }
            foreach ($d['tabel'] as $t) {
                $tulis([]);
                $tulis([$t['judul']]);
                $tulis(array_column($t['kolom'], 0));
                foreach ([...$t['baris'], ...(isset($t['total']) ? [$t['total']] : [])] as $baris) {
                    $tulis(array_map(fn ($v, $i) => Format::csv($v, $t['kolom'][$i][1] ?? 'teks'), $baris, array_keys($baris)));
                }
            }
        });
    }
}
