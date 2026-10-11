<?php

namespace App\Services\Laporan;

use App\Enums\RoomStatus;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\SegmenPelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hunian:
 *   tersedia per malam = jumlah kamar aktif; terisi = malam dari reservation_rooms checked_in/checked_out/moved.
 *   Okupansi = terisi / tersedia. ADR = nilai kamar terjual / malam terisi; RevPAR = nilai kamar terjual / malam tersedia.
 * Nilai kamar terjual diambil dari reservation_rooms (harga per malam setelah potongan), sumber yang sama dengan malam terisi,
 * sehingga tamu instansi yang masih menginap (belum bernota) tidak membuat ADR turun.
 * Malam hilang karena Perbaikan: status kamar pukul 14.00 menurut log terakhir sebelum waktu itu.
 */
class LaporanHunian
{
    use MenyusunDokumen;

    public const JAM_ACUAN = '14:00:00';

    /**
     * Setiap malam terisi dalam periode.
     *
     * @return Collection<int, object{tanggal: string, tipe: int, nilai: float, reservasi: int, pelanggan: int, instansi: ?int, b2b: bool, asal: string}>
     */
    public function malamTerisi(Periode $p): Collection
    {
        $baris = DB::table('reservation_rooms as rr')->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->whereIn('rr.status', ['checked_in', 'checked_out', 'moved'])
            ->where('rr.check_in_date', '<=', $p->akhir->toDateString())->where('rr.check_out_date', '>', $p->mulai->toDateString())
            ->selectRaw('rr.room_type_id, rr.check_in_date, rr.check_out_date, rr.price_per_night, rr.discount_type, rr.discount_value,
                r.id AS reservasi, r.customer_id, r.institution_id, '.SegmenPelanggan::ekspresiReservasi().' AS b2b, '.SegmenPelanggan::ekspresiAsal().' AS asal')
            ->get();

        $hasil = collect();
        foreach ($baris as $b) {
            $rr = (new ReservationRoom)->forceFill(['check_in_date' => $b->check_in_date, 'check_out_date' => $b->check_out_date,
                'price_per_night' => $b->price_per_night, 'discount_type' => $b->discount_type, 'discount_value' => $b->discount_value]);
            $malam = $rr->malam();
            if ($malam <= 0) {
                continue;
            }
            $perMalam = $rr->biaya() / $malam;
            $awal = CarbonImmutable::parse($b->check_in_date);
            for ($i = 0; $i < $malam; $i++) {
                $t = $awal->addDays($i)->toDateString();
                if ($p->berisi($t)) {
                    $hasil->push((object) ['tanggal' => $t, 'tipe' => (int) $b->room_type_id, 'nilai' => $perMalam, 'reservasi' => (int) $b->reservasi,
                        'pelanggan' => (int) $b->customer_id, 'instansi' => $b->institution_id ? (int) $b->institution_id : null, 'b2b' => (bool) $b->b2b, 'asal' => $b->asal]);
                }
            }
        }

        return $hasil;
    }

    public function ringkas(Periode $p): array
    {
        $malam = $this->malamTerisi($p);
        $kamar = Room::active()->selectRaw('room_type_id, COUNT(*) AS n')->groupBy('room_type_id')->pluck('n', 'room_type_id')->map(fn ($n) => (int) $n);
        $hari = $p->jumlahHari();
        $tersedia = $kamar->sum() * $hari;
        $terisi = $malam->count();
        $nilai = (float) $malam->sum('nilai');

        return [
            'tersedia' => $tersedia,
            'terisi' => $terisi,
            'okupansi' => self::bagi($terisi, $tersedia) * 100,
            'nilai' => $nilai,
            'adr' => self::bagi($nilai, $terisi),
            'revpar' => self::bagi($nilai, $tersedia),
            'perTipe' => RoomType::orderBy('id')->get(['id', 'name'])->map(function ($t) use ($malam, $kamar, $hari) {
                $m = $malam->where('tipe', $t->id);
                $sedia = ($kamar[$t->id] ?? 0) * $hari;

                return ['nama' => $t->name, 'kamar' => $kamar[$t->id] ?? 0, 'tersedia' => $sedia, 'terisi' => $m->count(),
                    'okupansi' => self::bagi($m->count(), $sedia) * 100, 'nilai' => (float) $m->sum('nilai'), 'adr' => self::bagi((float) $m->sum('nilai'), $m->count())];
            })->all(),
            // Okupansi per kelompok grafik (hari atau bulan)
            'kelompok' => collect($p->kelompok())->map(function ($label, $kunci) use ($malam, $kamar, $p) {
                $hariDiKelompok = $p->hari()->filter(fn ($d) => $p->kunci($d) === $kunci)->count();

                return self::bagi($malam->filter(fn ($x) => $p->kunci($x->tanggal) === $kunci)->count(), $kamar->sum() * $hariDiKelompok) * 100;
            })->all(),
            'perHari' => $p->hari()->mapWithKeys(fn ($d) => [$d->toDateString() => $malam->where('tanggal', $d->toDateString())->count()])->all(),
        ];
    }

    /** Check-in, check-out, tidak datang, dan pembatalan dalam periode. */
    public function pergerakan(Periode $p): array
    {
        $rentang = [$p->mulai->startOfDay(), $p->akhir->endOfDay()];
        $log = fn (string $status) => DB::table('reservation_logs')->where('event_type', 'status_change')->where('status', $status)
            ->whereBetween('changed_at', $rentang)->distinct()->count('reservation_id');

        return [
            // Baris hasil pindah kamar bukan check-in baru
            'checkIn' => ReservationRoom::whereNull('moved_from_id')->whereBetween('actual_check_in_at', $rentang)->count(),
            'checkOut' => ReservationRoom::where('status', 'checked_out')->whereBetween('actual_check_out_at', $rentang)->count(),
            'tidakDatang' => $log('no_show'),
            'batal' => $log('cancelled'),
        ];
    }

    /** Malam kamar aktif yang hilang karena berstatus Perbaikan pada pukul 14.00 tanggal itu. */
    public function hilangPerbaikan(Periode $p): int
    {
        $rooms = Room::active()->get(['id', 'status']);
        $awal = $p->mulai->toDateString().' '.self::JAM_ACUAN;
        $akhir = $p->akhir->toDateString().' '.self::JAM_ACUAN;
        // Status terakhir sebelum pukul 14.00 hari pertama (termasuk log jauh sebelum periode)
        $sebelum = DB::query()->fromSub(DB::table('room_status_logs')->where('changed_at', '<=', $awal)
            ->selectRaw('room_id, status, ROW_NUMBER() OVER (PARTITION BY room_id ORDER BY changed_at DESC, id DESC) AS urut'), 't')
            ->where('urut', 1)->pluck('status', 'room_id');
        $dalam = DB::table('room_status_logs')->where('changed_at', '>', $awal)->where('changed_at', '<=', $akhir)
            ->orderBy('changed_at')->orderBy('id')->get(['room_id', 'status', 'changed_at'])->groupBy('room_id');
        $pernahLog = DB::table('room_status_logs')->distinct()->pluck('room_id')->flip();

        $hilang = 0;
        foreach ($rooms as $room) {
            // Kamar tanpa log sama sekali memakai statusnya sekarang
            $status = $sebelum[$room->id] ?? ($pernahLog->has($room->id) ? null : $room->status->value);
            $log = $dalam->get($room->id, collect())->values();
            $i = 0;
            foreach ($p->hari() as $d) {
                $acuan = $d->toDateString().' '.self::JAM_ACUAN;
                while ($i < $log->count() && (string) $log[$i]->changed_at <= $acuan) {
                    $status = $log[$i]->status;
                    $i++;
                }
                if ($status === RoomStatus::Maintenance->value) {
                    $hilang++;
                }
            }
        }

        return $hilang;
    }

    public function dokumen(Periode $p): array
    {
        $ini = $this->ringkas($p);
        $lalu = $this->ringkas($p->sebelumnya());
        $gerak = $this->pergerakan($p);
        $gerakLalu = $this->pergerakan($p->sebelumnya());
        $hilang = $this->hilangPerbaikan($p);

        return [
            'judul' => 'Laporan Hunian',
            'ringkasan' => [
                $this->kartu('Okupansi', $ini['okupansi'], $lalu['okupansi'], 'persen', $ini['terisi'].' dari '.$ini['tersedia'].' malam kamar'),
                $this->kartu('ADR', $ini['adr'], $lalu['adr'], 'rp', 'Rata-rata nilai kamar per malam terisi'),
                $this->kartu('RevPAR', $ini['revpar'], $lalu['revpar'], 'rp', 'Nilai kamar per malam tersedia'),
                $this->kartu('Nilai kamar terjual', $ini['nilai'], $lalu['nilai'], 'rp'),
                $this->kartu('Check-in', $gerak['checkIn'], $gerakLalu['checkIn'], 'angka'),
                $this->kartu('Check-out', $gerak['checkOut'], $gerakLalu['checkOut'], 'angka'),
                $this->kartu('Tidak datang', $gerak['tidakDatang'], $gerakLalu['tidakDatang'], 'angka'),
                $this->kartu('Pembatalan', $gerak['batal'], $gerakLalu['batal'], 'angka'),
                $this->kartu('Malam hilang karena Perbaikan', $hilang, $this->hilangPerbaikan($p->sebelumnya()), 'angka', 'Status kamar pukul 14.00'),
            ],
            'grafik' => [[
                'judul' => 'Okupansi per '.($p->bulanan() ? 'bulan' : 'hari'),
                'format' => 'persen',
                'maks' => 100,
                'label' => array_values($p->kelompok()),
                'seri' => [['nama' => 'Okupansi', 'warna' => '#4E3423', 'nilai' => array_values($ini['kelompok'])]],
            ]],
            'tabel' => [[
                'judul' => 'Rincian per tipe kamar',
                'kolom' => [['Tipe', 'teks'], ['Kamar', 'angka'], ['Malam tersedia', 'angka'], ['Malam terisi', 'angka'], ['Okupansi', 'persen'], ['Nilai terjual', 'rp'], ['ADR', 'rp']],
                'baris' => collect($ini['perTipe'])->map(fn ($t) => [$t['nama'], $t['kamar'], $t['tersedia'], $t['terisi'], $t['okupansi'], $t['nilai'], $t['adr']])->all(),
                'total' => ['Semua tipe', collect($ini['perTipe'])->sum('kamar'), $ini['tersedia'], $ini['terisi'], $ini['okupansi'], $ini['nilai'], $ini['adr']],
            ]],
            'catatan' => [LaporanPendapatan::CATATAN_SELISIH, 'Kamar tersedia per malam = jumlah kamar aktif saat ini.'],
        ];
    }
}
