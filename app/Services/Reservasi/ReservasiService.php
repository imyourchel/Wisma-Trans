<?php

namespace App\Services\Reservasi;

use App\Enums\RoomStatus;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationLog;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Kamar\RoomStatusService;
use App\Services\Keuangan\NotaService;
use App\Services\Keuangan\PembayaranService;
use App\Services\SmartCleaning\TugasHousekeepingService;
use App\Support\Nomor;
use App\Support\PilihanKamar;
use App\Support\TokenAkses;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Semua perubahan reservasi lewat kelas ini: membuat (online, langsung, instansi), menyetujui, mengubah tanggal,
 * membatalkan, check-in, perpanjangan, dan pindah kamar. Setiap perubahan dibungkus transaksi dan dicatat
 * di reservation_logs. Angka aturan (uang muka, batas bayar, jam check-in, pembatalan) dibaca dari settings.
 */
class ReservasiService
{
    public const MAKS_MALAM = 30;

    /** Batas malam pengajuan instansi (form /instansi, pengajuan oleh staf, ajukanInstansi); satu-satunya tempat angka ini. */
    public const MAKS_MALAM_INSTANSI = 180;

    public function __construct(
        private KetersediaanKamar $ketersediaan,
        private NotaService $nota,
        private RoomStatusService $statusKamar,
        private TambahanKamar $tambahan,
    ) {}

    /* ======================== Pengaturan ======================== */

    public static function persenUangMuka(): int
    {
        return (int) Setting::get('dp_min_percent', 50);
    }

    public static function jamBatasBayar(): int
    {
        return (int) Setting::get('dp_due_hours', 24);
    }

    /** "14.00" */
    public static function jamCheckIn(): string
    {
        return str_replace(':', '.', substr((string) Setting::get('check_in_time', '14:00'), 0, 5));
    }

    /** "06.00": tamu yang tiba mulai jam ini boleh early check-in bila kamarnya sudah Siap. */
    public static function jamEarlyCheckIn(): string
    {
        return str_replace(':', '.', substr((string) Setting::get('early_check_in_from', '06:00'), 0, 5));
    }

    /** Kalimat untuk tamu di halaman reservasi dan pesan konfirmasi. */
    public static function teksEarlyCheckIn(): string
    {
        return 'Early check-in mulai pukul '.self::jamEarlyCheckIn().', bila kamar sudah siap.';
    }

    /**
     * Masa check-in pada hari kedatangan menurut jam sekarang: 'normal' mulai check_in_time, 'early' mulai
     * early_check_in_from sampai sebelum check_in_time, dan 'terlalu_pagi' sebelum early_check_in_from.
     *
     * @return 'normal'|'early'|'terlalu_pagi'
     */
    public static function masaCheckIn(): string
    {
        $jam = now()->format('H:i');

        return match (true) {
            $jam >= str_replace('.', ':', self::jamCheckIn()) => 'normal',
            $jam >= str_replace('.', ':', self::jamEarlyCheckIn()) => 'early',
            default => 'terlalu_pagi',
        };
    }

    public static function hariMinimalBatal(): int
    {
        return (int) Setting::get('cancellation_min_days', 1);
    }

    public static function persenPengembalian(): int
    {
        return (int) Setting::get('cancellation_refund_percent', 50);
    }

    public function uangMuka(float $total): float
    {
        return round($total * self::persenUangMuka() / 100);
    }

    /* ======================== A. Reservasi online ======================== */

    /**
     * Reservasi online tamu perorangan: berstatus menunggu uang muka sampai pembayaran Midtrans berhasil.
     *
     * Uang muka dihitung dari total kamar ditambah Extra bed dan Sarapan tambahan (TambahanKamar).
     * Setiap kamar langsung mendapat nomor kamar fisik dengan jenis kasur pilihan tamu (nomor terkecil yang bebas), jadi
     * jenis kasurnya terjamin. Jumlah tamu per kamar diperiksa dengan kapasitas jenis kasur kamar itu (RoomType::kapasitasKasur)
     * ditambah extra bed yang dipilih; kamar tanpa pilihan kasur mendapat jenis kasur terkecil yang muat untuk tamunya.
     *
     * @param  array<int, int>  $pesanan  id tipe kamar => jumlah kamar
     * @param  array<int, list<array{extra?: int, sarapan?: int}>>  $tambahan  id tipe kamar => pilihan tambahan per kamar, urut kamar
     * @param  array<int, list<string|null>>  $kasur  id tipe kamar => jenis kasur per kamar (Room::BED_TYPES), urut kamar
     * @param  array<int, list<array{dewasa: int, anak: int}|null>>  $orang  id tipe kamar => tamu per kamar, urut kamar; kosong = tidak diperiksa
     */
    public function buatOnline(Customer $pemesan, CarbonInterface $masuk, CarbonInterface $keluar, array $pesanan, ?string $permintaan = null, array $tambahan = [], array $kasur = [], array $orang = []): Reservation
    {
        $this->periksaTanggal($masuk, $keluar);
        $pesanan = array_filter($pesanan, fn ($n) => $n > 0);
        if ($pesanan === []) {
            throw ValidationException::withMessages(['kamar' => 'Pilih minimal satu kamar.']);
        }

        return DB::transaction(function () use ($pemesan, $masuk, $keluar, $pesanan, $permintaan, $tambahan, $kasur, $orang) {
            $tipe = $this->kunciTipe(array_keys($pesanan));
            $this->periksaSisa($tipe, $pesanan, $masuk, $keluar);

            // Kamar fisik per kamar yang dipesan, sesuai jenis kasur; tipe sudah dikunci sehingga pemesanan bersamaan antre
            $kamarFisik = [];
            $diambil = [];
            $pilihan = [];
            $pembagian = [];
            $no = 0;
            foreach ($pesanan as $id => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $no++;
                    $jenis = $kasur[$id][$i] ?? null;
                    $tamu = isset($orang[$id][$i]) ? ['dewasa' => (int) $orang[$id][$i]['dewasa'], 'anak' => (int) ($orang[$id][$i]['anak'] ?? 0)] : null;
                    $extra = max(0, (int) ($tambahan[$id][$i]['extra'] ?? 0));
                    $calon = $this->calonKasur($tipe[$id], $jenis, $tamu, $extra);
                    if ($calon === []) {
                        throw ValidationException::withMessages(['kamar' => "{$tipe[$id]->name} tidak punya jenis kasur untuk ".self::teksTamu($tamu).' dalam satu kamar.']);
                    }
                    $room = null;
                    foreach ($calon as $b) {
                        if ($room = $this->ketersediaan->ambilKamar($id, $b, $masuk, $keluar, null, $diambil)) {
                            break;
                        }
                    }
                    if (! $room) {
                        throw ValidationException::withMessages(['kamar' => $jenis
                            ? 'Maaf, '.PilihanKamar::namaPendek($tipe[$id]).' · '.Room::labelKasur($jenis)." baru saja habis. Silakan pilih jenis kasur lain untuk Kamar {$no}."
                            : "Maaf, {$tipe[$id]->name} baru saja habis untuk tanggal ini. Silakan pilih tipe lain untuk Kamar {$no}."]);
                    }
                    // Tamu melebihi kapasitas kasur: hanya boleh bila extra bed yang dipilih menutup kekurangannya
                    if ($tamu !== null && ! $tipe[$id]->muatKasur($room->bed_type, $tamu['dewasa'], $tamu['anak'], $extra)) {
                        $perlu = $tipe[$id]->extraPerlu($room->bed_type, $tamu['dewasa'], $tamu['anak']);
                        $bisaExtra = $perlu !== null && $perlu <= $this->tambahan->extraTersedia();
                        throw ValidationException::withMessages(['kamar' => Room::labelKasur($room->bed_type).' hanya muat '.$tipe[$id]->teksKapasitas($room->bed_type)
                            .($extra > 0 ? " ditambah {$extra} extra bed" : '').", sedangkan kamar {$no} untuk ".self::teksTamu($tamu).'. '
                            .($bisaExtra ? 'Tambahkan extra bed atau pilih jenis kasur lain.' : 'Silakan pilih jenis kasur lain.')]);
                    }
                    $diambil[] = $room->id;
                    $kamarFisik[$id][$i] = $room;
                    if ($tamu !== null) {
                        $pembagian[] = "Kamar {$no} {$tipe[$id]->name} · ".Room::labelKasur($room->bed_type).": {$tamu['dewasa']} dewasa".($tamu['anak'] > 0 ? " {$tamu['anak']} anak" : '');
                    }
                    $pilihan[$id][$i] = $this->tambahan->periksa($tambahan[$id][$i] ?? [], $tipe[$id], "kamar {$no}", 'tambahan', $room->bed_type);
                }
            }

            $malam = (int) $masuk->diffInDays($keluar);
            $total = collect($pesanan)->sum(fn ($n, $id) => (float) $tipe[$id]->base_price * $malam * $n)
                + collect($pilihan)->flatten(1)->sum(fn ($p) => $this->tambahan->biaya($p, $malam));

            $r = Reservation::create([
                'reservation_code' => Nomor::reservasi(),
                'customer_id' => $pemesan->id,
                'service_billing_scheme' => 'satu_nota',
                'booking_channel' => 'online',
                'status' => 'pending_payment',
                'special_request' => $permintaan,
                'dp_amount_required' => $this->uangMuka($total),
                'dp_due_at' => now()->addHours(self::jamBatasBayar()),
                'access_token' => $this->tokenUnik(),
            ]);
            foreach ($pesanan as $id => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $this->tambahan->simpan($this->barisKamar($r, $tipe[$id], $kamarFisik[$id][$i], $masuk, $keluar), $r, $pilihan[$id][$i], null);
                }
            }
            $this->notaMenginap($r, null);
            // Pembagian tamu tidak punya kolom sendiri: dicatat sebagai teks di log pembuatan (Reservation::pembagianTamu)
            $this->catat($r, 'pending_payment', null, $pembagian ? Reservation::AWALAN_PEMBAGIAN.implode('; ', $pembagian) : null);

            return $r;
        });
    }

    /**
     * Jenis kasur yang dicoba untuk satu kamar: pilihan tamu; tanpa pilihan dan tanpa jumlah tamu semua jenis (null);
     * tanpa pilihan dengan jumlah tamu: jenis kasur tipe itu yang muat (dengan extra bed yang dipilih), dari yang terkecil.
     *
     * @return list<string|null>
     */
    private function calonKasur(RoomType $tipe, ?string $jenis, ?array $tamu, int $extra): array
    {
        if ($jenis !== null || $tamu === null) {
            return [$jenis];
        }

        return Room::where('room_type_id', $tipe->id)->where('is_active', true)->distinct()->pluck('bed_type')
            ->filter(fn ($b) => $tipe->muatKasur($b, $tamu['dewasa'], $tamu['anak'], $extra))
            ->sortBy(fn ($b) => [$tipe->kapasitasKasur($b)['dewasa'], $tipe->kapasitasKasur($b)['anak'], array_search($b, array_keys(Room::BED_TYPES), true)])
            ->values()->all();
    }

    /** "3 dewasa" atau "2 dewasa dan 2 anak". */
    private static function teksTamu(array $tamu): string
    {
        return $tamu['dewasa'].' dewasa'.($tamu['anak'] > 0 ? " dan {$tamu['anak']} anak" : '');
    }

    /* ======================== B. Reservasi langsung (walk-in) ======================== */

    /**
     * Tamu datang langsung: tamu memilih nomor kamar, reservasi langsung terkonfirmasi.
     *
     * @param  list<int>  $kamar  id kamar
     * @param  array<int, array{extra?: int, sarapan?: int}>  $tambahan  id kamar => Extra bed dan Sarapan tambahan (TambahanKamar)
     */
    public function buatLangsung(Customer $pemesan, CarbonInterface $masuk, CarbonInterface $keluar, array $kamar, User $oleh, ?string $permintaan = null, array $tambahan = []): Reservation
    {
        $this->periksaTanggal($masuk, $keluar);
        $kamar = array_values(array_unique(array_map('intval', $kamar)));
        if ($kamar === []) {
            throw ValidationException::withMessages(['kamar' => 'Pilih minimal satu kamar.']);
        }

        return DB::transaction(function () use ($pemesan, $masuk, $keluar, $kamar, $oleh, $permintaan, $tambahan) {
            $rooms = Room::with('roomType')->whereIn('id', $kamar)->lockForUpdate()->get()->keyBy('id');
            $tipe = $this->kunciTipe($rooms->pluck('room_type_id')->unique()->values()->all());
            $pilihan = [];
            foreach ($kamar as $id) {
                $this->periksaKamarBebas($rooms->get($id), $masuk, $keluar, null);
                $pilihan[$id] = $this->tambahan->periksa($tambahan[$id] ?? [], $tipe[$rooms[$id]->room_type_id], 'kamar '.$rooms[$id]->room_number);
            }
            $this->periksaSisa($tipe, $rooms->countBy('room_type_id')->all(), $masuk, $keluar);

            $r = Reservation::create([
                'reservation_code' => Nomor::reservasi(),
                'customer_id' => $pemesan->id,
                'service_billing_scheme' => 'satu_nota',
                'booking_channel' => 'walk_in',
                'status' => 'confirmed',
                'special_request' => $permintaan,
                'access_token' => $this->tokenUnik(),
                'created_by' => $oleh->id,
            ]);
            foreach ($kamar as $id) {
                $this->tambahan->simpan($this->barisKamar($r, $tipe[$rooms[$id]->room_type_id], $rooms[$id], $masuk, $keluar), $r, $pilihan[$id], $oleh);
            }
            $this->notaMenginap($r, $oleh);
            $this->catat($r, 'confirmed', $oleh, 'Tamu datang langsung');

            return $r;
        });
    }

    /* ======================== C. Pengajuan instansi ======================== */

    public function ajukanInstansi(Customer $pic, ?Institution $instansi, CarbonInterface $masuk, CarbonInterface $keluar, int $jumlahKamar, ?string $kegiatan, ?string $catatan, ?User $oleh = null, bool $ikutInstansiNarahubung = true): Reservation
    {
        $this->periksaTanggal($masuk, $keluar, maksMalam: self::MAKS_MALAM_INSTANSI);

        return DB::transaction(function () use ($pic, $instansi, $masuk, $keluar, $jumlahKamar, $kegiatan, $catatan, $oleh, $ikutInstansiNarahubung) {
            $r = Reservation::create([
                'reservation_code' => Nomor::reservasi(),
                'customer_id' => $pic->id,
                // Pengajuan dari situs tidak ikut instansi narahubung: narahubung bisa saja mengajukan untuk instansi lain
                'institution_id' => $instansi?->id ?? ($ikutInstansiNarahubung ? $pic->institution_id : null),
                'service_billing_scheme' => 'satu_nota',
                'booking_channel' => 'online',
                'status' => 'waiting_approval',
                'special_request' => $catatan,
                'access_token' => $this->tokenUnik(),
                'created_by' => $oleh?->id,
            ]);
            $r->request()->create([
                'check_in_date' => $masuk->toDateString(),
                'check_out_date' => $keluar->toDateString(),
                'room_count' => $jumlahKamar,
                'event_name' => $kegiatan,
            ]);
            $this->catat($r, 'waiting_approval', $oleh, "Pengajuan: {$jumlahKamar} kamar, ".self::rentang($masuk, $keluar));

            return $r;
        });
    }

    /**
     * Manager menyetujui pengajuan instansi: menetapkan kamar, harga atau potongan yang disepakati, dan skema penagihan.
     *
     * @param  list<array{room_id: int, price: float|int|string, discount_type?: ?string, discount_value?: float|int|string|null}>  $kamar
     */
    public function setujui(Reservation $r, Institution $instansi, array $kamar, ?string $skemaTagih, string $skemaLayanan, ?string $catatan, User $oleh): void
    {
        if ($r->status !== 'waiting_approval' || ! $r->request) {
            throw ValidationException::withMessages(['status' => 'Pengajuan ini sudah diputuskan.']);
        }
        if ($kamar === []) {
            throw ValidationException::withMessages(['kamar' => 'Tetapkan minimal satu kamar.']);
        }
        $masuk = CarbonImmutable::parse($r->request->check_in_date);
        $keluar = CarbonImmutable::parse($r->request->check_out_date);

        DB::transaction(function () use ($r, $instansi, $kamar, $skemaTagih, $skemaLayanan, $catatan, $oleh, $masuk, $keluar) {
            $ids = array_map(fn ($k) => (int) $k['room_id'], $kamar);
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['kamar' => 'Ada kamar yang dipilih dua kali.']);
            }
            $rooms = Room::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $tipe = $this->kunciTipe($rooms->pluck('room_type_id')->unique()->values()->all());
            foreach ($ids as $id) {
                // Kamar dikunci (lockForUpdate) lalu diperiksa ulang: bisa saja terpakai reservasi lain sejak dialog dibuka
                $this->periksaKamarBebas($rooms->get($id), $masuk, $keluar, $r->id, pesanBentrok: 'Kamar %s baru saja dipakai reservasi lain. Pilih kamar lain.');
            }
            $this->periksaSisa($tipe, $rooms->countBy('room_type_id')->all(), $masuk, $keluar, $r->id);

            foreach ($kamar as $k) {
                $room = $rooms[(int) $k['room_id']];
                $diskon = in_array($k['discount_type'] ?? null, ['percent', 'amount'], true) && (float) ($k['discount_value'] ?? 0) > 0;
                $this->barisKamar($r, $tipe[$room->room_type_id], $room, $masuk, $keluar, (float) $k['price'],
                    $diskon ? $k['discount_type'] : null, $diskon ? (float) $k['discount_value'] : null);
            }

            $r->update([
                'status' => 'confirmed',
                'institution_id' => $instansi->id,
                // Skema sama dengan bawaan instansi disimpan kosong (ikut bawaan)
                'billing_scheme' => $skemaTagih === $instansi->default_billing_scheme ? null : $skemaTagih,
                'service_billing_scheme' => $skemaLayanan,
            ]);
            $pic = $r->customer;
            if ($pic && $pic->institution_id === null) {
                $pic->update(['institution_id' => $instansi->id]);
            }
            $this->catat($r, 'confirmed', $oleh, $catatan ?: 'Disetujui, '.count($kamar).' kamar');
        });
    }

    public function tolak(Reservation $r, string $alasan, User $oleh): void
    {
        if ($r->status !== 'waiting_approval') {
            throw ValidationException::withMessages(['status' => 'Pengajuan ini sudah diputuskan.']);
        }
        DB::transaction(function () use ($r, $alasan, $oleh) {
            $r->update(['status' => 'rejected']);
            $this->catat($r, 'rejected', $oleh, $alasan);
        });
    }

    /* ======================== D. Perubahan dan pembatalan ======================== */

    /** Jumlah hari dari hari ini sampai tanggal kedatangan. */
    public function hariMenujuKedatangan(Reservation $r): int
    {
        $masuk = $r->loadMissing('rooms', 'request')->masuk();

        return $masuk ? (int) today()->diffInDays($masuk, false) : 0;
    }

    /** Ubah tanggal dan batal dengan pengembalian uang muka hanya boleh lebih dari N hari sebelum kedatangan. */
    public function masihBolehDiubah(Reservation $r): bool
    {
        return $this->hariMenujuKedatangan($r) > self::hariMinimalBatal();
    }

    /** Uang muka yang dikembalikan bila reservasi dibatalkan sekarang. */
    public function pengembalian(Reservation $r): float
    {
        if ($r->status !== 'confirmed' || ! $this->masihBolehDiubah($r)) {
            return 0.0;
        }

        return round($r->dpDibayar() * self::persenPengembalian() / 100);
    }

    /**
     * Tanggal baru; harga per malam tetap mengikuti harga saat memesan. Jenis kasur dipertahankan: bila kamar lama sudah
     * dipesan tamu lain pada tanggal baru, dicarikan kamar lain bertipe dan berjenis kasur sama.
     */
    public function ubahTanggal(Reservation $r, CarbonInterface $masuk, CarbonInterface $keluar, ?User $oleh): void
    {
        $r->loadMissing('rooms.room');
        if ($r->status !== 'confirmed' || $r->kamarBerlaku()->contains(fn ($k) => $k->status !== 'booked')) {
            throw ValidationException::withMessages(['tanggal' => 'Tanggal hanya bisa diubah untuk reservasi terkonfirmasi yang belum check-in.']);
        }
        if (! $this->masihBolehDiubah($r)) {
            throw ValidationException::withMessages(['tanggal' => 'Tanggal hanya bisa diubah lebih dari '.self::hariMinimalBatal().' hari sebelum kedatangan.']);
        }
        $this->periksaTanggal($masuk, $keluar);
        $lama = self::rentang($r->masuk(), $r->keluar());

        DB::transaction(function () use ($r, $masuk, $keluar, $oleh, $lama) {
            $baris = $r->kamarBerlaku();
            $tipe = $this->kunciTipe($baris->pluck('room_type_id')->unique()->values()->all());
            $this->periksaSisa($tipe, $baris->countBy('room_type_id')->all(), $masuk, $keluar, $r->id);
            $tetap = $baris->whereNotNull('room_id')->filter(fn ($k) => $this->ketersediaan->bebas($k->room_id, $masuk, $keluar, $r->id));
            $dipakai = $tetap->pluck('room_id')->map(fn ($id) => (int) $id)->all();
            $ganti = [];
            foreach ($baris->whereNotNull('room_id')->diffKeys($tetap) as $k) {
                $kasur = $k->room?->bed_type;
                $lain = $this->ketersediaan->ambilKamar($k->room_type_id, $kasur, $masuk, $keluar, $r->id, $dipakai);
                if (! $lain) {
                    throw ValidationException::withMessages(['tanggal' => "Tidak ada kamar {$k->roomType?->name} ".Room::labelKasur($kasur)
                        .' yang kosong pada tanggal baru. Pilih tanggal lain atau hubungi resepsionis.']);
                }
                $dipakai[] = $lain->id;
                $ganti[$k->id] = $lain;
            }
            foreach ($baris as $k) {
                $k->update(['check_in_date' => $masuk->toDateString(), 'check_out_date' => $keluar->toDateString()]
                    + (isset($ganti[$k->id]) ? ['room_id' => $ganti[$k->id]->id] : []));
            }
            $this->susunUlangNota($r);
            $this->catat($r, 'confirmed', $oleh, "Tanggal diubah dari {$lama} menjadi ".self::rentang($masuk, $keluar).', harga tetap'
                .collect($ganti)->map(fn (Room $k, $id) => '; kamar '.$baris->firstWhere('id', $id)?->room?->room_number.' diganti '.$k->room_number.' (jenis kasur sama)')->join(''));
        });
    }

    /**
     * Batalkan reservasi. Uang muka dikembalikan sesuai cancellation_refund_percent bila pembatalan lebih dari
     * N hari sebelum kedatangan; selain itu uang muka hangus. Pengembalian dananya dicatat di menu Pembayaran.
     *
     * @return float jumlah uang muka yang dikembalikan
     */
    public function batalkan(Reservation $r, ?string $alasan, ?User $oleh): float
    {
        if (! in_array($r->status, ['pending_payment', 'waiting_approval', 'confirmed'], true)) {
            throw ValidationException::withMessages(['status' => 'Reservasi ini tidak bisa dibatalkan lagi.']);
        }
        if ($r->loadMissing('rooms')->rooms->contains('status', 'checked_in')) {
            throw ValidationException::withMessages(['status' => 'Tamu sudah check-in; gunakan check-out.']);
        }
        $kembali = $this->pengembalian($r);
        $dp = $r->dpDibayar();

        DB::transaction(function () use ($r, $alasan, $oleh, $kembali, $dp) {
            $keterangan = match (true) {
                $dp <= 0 => null,
                $kembali > 0 => 'dana kembali '.self::persenPengembalian().'% dari uang muka ('.self::rp($kembali).')',
                default => 'uang muka hangus karena kurang dari '.(self::hariMinimalBatal() + 1).' hari sebelum kedatangan',
            };
            $this->akhiri($r, 'cancelled', $alasan.($keterangan ? '; '.$keterangan : ''), $oleh,
                'Dibatalkan'.($keterangan ? '; '.$keterangan : ''));
            // Uang muka yang kembali masuk antrean pengembalian dana (menu Keuangan > Pembayaran)
            if ($kembali > 0 && $nota = $r->invoices()->where('invoice_type', 'stay')->orderByDesc('id')->first()) {
                $asal = Payment::where('invoice_id', $nota->id)->where('payment_purpose', 'down_payment')
                    ->where('transaction_type', 'payment')->where('status', 'success')->orderBy('id')->first();
                app(PembayaranService::class)->ajukanRefund($nota, $kembali,
                    "Reservasi {$r->reservation_code} dibatalkan, uang muka kembali ".self::persenPengembalian().'%', $asal);
            }
        });

        return $kembali;
    }

    /**
     * Penjadwal: reservasi yang uang mukanya tidak dibayar sampai batas waktu dibatalkan otomatis.
     *
     * @return list<Reservation> reservasi yang dibatalkan
     */
    public function batalkanKedaluwarsa(): array
    {
        return Reservation::where('status', 'pending_payment')->where('dp_due_at', '<=', now())->get()
            ->filter(fn (Reservation $r) => $this->batalkanBilaKedaluwarsa($r))
            ->map->fresh()->values()->all();
    }

    /** Batalkan satu reservasi bila batas bayar uang mukanya sudah lewat. Benar bila dibatalkan sekarang. */
    public function batalkanBilaKedaluwarsa(Reservation $r): bool
    {
        if ($r->status !== 'pending_payment' || ! $r->dp_due_at?->lte(now())) {
            return false;
        }

        return DB::transaction(function () use ($r) {
            $kini = Reservation::whereKey($r->id)->lockForUpdate()->first();
            if ($kini->status !== 'pending_payment') {
                return false;
            }
            $this->akhiri($kini, 'cancelled', 'Batal otomatis: uang muka tidak dibayar dalam '.self::jamBatasBayar().' jam', null,
                'Batal otomatis, uang muka tidak dibayar');
            $r->refresh();

            return true;
        });
    }

    /** Penjadwal: tamu yang tidak datang sampai akhir hari kedatangan ditandai no_show, uang muka hangus. */
    public function tandaiTidakDatang(): int
    {
        $n = 0;
        Reservation::where('status', 'confirmed')
            ->whereHas('rooms', fn ($q) => $q->where('status', 'booked')->where('check_in_date', '<', today()->toDateString()))
            ->whereDoesntHave('rooms', fn ($q) => $q->whereIn('status', ['checked_in', 'checked_out']))
            ->get()
            ->each(function (Reservation $r) use (&$n) {
                DB::transaction(fn () => $this->akhiri($r, 'no_show', 'Tamu tidak datang sampai 23.59, uang muka hangus', null,
                    'Tamu tidak datang, uang muka hangus'));
                $n++;
            });

        return $n;
    }

    /* ======================== E. Check-in ======================== */

    /**
     * Check-in pada hari kedatangan mulai check_in_time. Mulai early_check_in_from tamu boleh early check-in tanpa biaya
     * tambahan, asalkan semua kamarnya sudah Siap; kedatangan sebelum itu dihitung menginap malam sebelumnya.
     * Tamu yang terlambat (tanggal kedatangan sudah lewat) bisa check-in kapan saja.
     * Nomor kamar ditetapkan untuk baris yang belum punya, kamar harus berstatus Siap; status kamar menjadi Terisi lewat RoomStatusService.
     *
     * Reservasi instansi hanya bisa check-in dengan bukti pemesanan yang cocok ($bukti = access_token hasil verifikasi
     * kode bukti), supaya jatah instansi tidak dipakai orang yang tidak ditugaskan.
     *
     * @param  array<int, int>  $penempatan  id baris reservasi => id kamar
     */
    public function checkIn(Reservation $r, array $penempatan, bool $ktpDitahan, ?string $namaKtp, User $oleh, ?string $bukti = null): void
    {
        $r->loadMissing('rooms');
        if ($r->status !== 'confirmed') {
            throw ValidationException::withMessages(['status' => 'Hanya reservasi terkonfirmasi yang bisa check-in.']);
        }
        $instansi = $r->instansi();
        if ($instansi && ! ($bukti && $r->access_token && hash_equals((string) $r->access_token, $bukti))) {
            throw ValidationException::withMessages(['status' => 'Reservasi instansi hanya bisa check-in setelah bukti pemesanan diverifikasi.']);
        }
        $masuk = $r->masuk();
        $sekarang = now();
        if ($masuk === null || $masuk->isAfter(today())) {
            throw ValidationException::withMessages(['status' => 'Check-in baru bisa pada tanggal kedatangan, '.$masuk?->translatedFormat('j F Y').'.']);
        }
        $masa = $masuk->isSameDay(today()) ? self::masaCheckIn() : 'normal';
        if ($masa === 'terlalu_pagi') {
            throw ValidationException::withMessages(['status' => 'Kedatangan sebelum pukul '.self::jamEarlyCheckIn()
                .' dihitung menginap malam sebelumnya. Ubah tanggal masuk reservasi terlebih dahulu.']);
        }
        $early = $masa === 'early';
        if ($ktpDitahan && blank($namaKtp)) {
            throw ValidationException::withMessages(['nama_ktp' => 'Isi nama sesuai KTP yang ditahan.']);
        }

        DB::transaction(function () use ($r, $penempatan, $ktpDitahan, $namaKtp, $oleh, $instansi, $early, $sekarang) {
            $baris = ReservationRoom::where('reservation_id', $r->id)->where('status', 'booked')->lockForUpdate()->get();
            if ($baris->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'Tidak ada kamar yang menunggu check-in.']);
            }
            $dipakai = [];
            foreach ($baris as $k) {
                $roomId = (int) ($penempatan[$k->id] ?? $k->room_id);
                $room = $roomId ? Room::with('roomType')->lockForUpdate()->find($roomId) : null;
                $galat = "kamar.{$k->id}";
                if (! $room) {
                    throw ValidationException::withMessages([$galat => 'Pilih nomor kamar.']);
                }
                if (in_array($room->id, $dipakai, true)) {
                    throw ValidationException::withMessages([$galat => "Kamar {$room->room_number} dipilih dua kali."]);
                }
                if ($room->room_type_id !== $k->room_type_id) {
                    throw ValidationException::withMessages([$galat => "Kamar {$room->room_number} bukan tipe yang dipesan."]);
                }
                if ($room->status !== RoomStatus::Available) {
                    throw ValidationException::withMessages([$galat => $early
                        ? "Kamar {$room->room_number} belum siap. Early check-in hanya untuk kamar berstatus Siap."
                        : "Kamar {$room->room_number} belum siap ({$room->status->label()})."]);
                }
                if (! $this->ketersediaan->bebas($room->id, $k->check_in_date, $k->check_out_date, $r->id)) {
                    throw ValidationException::withMessages([$galat => "Kamar {$room->room_number} sudah dipesan tamu lain."]);
                }
                $dipakai[] = $room->id;

                $k->update(['room_id' => $room->id, 'status' => 'checked_in', 'actual_check_in_at' => now()]);
                $this->statusKamar->change($room, RoomStatus::OccupiedClean, $instansi
                    ? "Check-in instansi, bukti diverifikasi oleh {$oleh->name}" : 'Check-in '.$r->reservation_code, $oleh);
            }

            $r->update(['status' => 'checked_in', 'id_card_held' => $ktpDitahan, 'id_card_holder_name' => $ktpDitahan ? trim((string) $namaKtp) : null]);
            if ($ktpDitahan) {
                $this->catat($r, null, $oleh, 'KTP atas nama '.trim((string) $namaKtp), 'id_card_received');
            }
            $this->catat($r, 'checked_in', $oleh, $early ? 'Early check-in pukul '.$sekarang->format('H.i').' WITA' : null);
            $this->susunUlangNota($r);
        });
    }

    /* ======================== F. Perpanjangan dan pindah kamar ======================== */

    /** Perpanjang menginap di kamar yang sama; harga per malam yang sama berlaku untuk malam tambahan. */
    public function perpanjang(ReservationRoom $k, CarbonInterface $keluarBaru, User $oleh): void
    {
        $k->loadMissing('reservation', 'room');
        $r = $k->reservation;
        if (! in_array($k->status, ['booked', 'checked_in'], true)) {
            throw ValidationException::withMessages(['keluar' => 'Kamar ini tidak bisa diperpanjang.']);
        }
        $keluarLama = CarbonImmutable::parse($k->check_out_date);
        if (! $keluarBaru->gt($keluarLama)) {
            throw ValidationException::withMessages(['keluar' => 'Tanggal check-out baru harus setelah '.$keluarLama->translatedFormat('j F Y').'.']);
        }
        if ($k->check_in_date->diffInDays($keluarBaru) > 366) {
            throw ValidationException::withMessages(['keluar' => 'Perpanjangan terlalu panjang.']);
        }

        DB::transaction(function () use ($k, $r, $keluarLama, $keluarBaru, $oleh) {
            $tipe = $this->kunciTipe([$k->room_type_id]);
            $this->periksaSisa($tipe, [$k->room_type_id => 1], $keluarLama, $keluarBaru, $r->id);
            if ($k->room_id && ! $this->ketersediaan->bebas($k->room_id, $keluarLama, $keluarBaru, $r->id)) {
                throw ValidationException::withMessages(['keluar' => "Kamar {$k->room->room_number} sudah dipesan tamu lain pada tanggal tambahan. Pindahkan tamu ke kamar lain bila perlu."]);
            }
            $k->update(['check_out_date' => $keluarBaru->toDateString()]);
            $this->susunUlangNota($r);
            $this->catat($r, $r->status, $oleh, ($k->room ? 'Kamar '.$k->room->room_number : 'Kamar').' diperpanjang sampai '.$keluarBaru->translatedFormat('j M Y'));
        });
    }

    /**
     * Pindah kamar mulai hari ini: baris lama berstatus moved, baris baru dibuat dengan harga dan potongan yang sama
     * (tamu tidak dirugikan, jumlah malam bersambung). Kamar lama menjadi kotor, kamar baru menjadi terisi.
     */
    public function pindahKamar(ReservationRoom $k, Room $baru, ?string $alasan, User $oleh): ReservationRoom
    {
        $k->loadMissing('reservation', 'room');
        $r = $k->reservation;
        if ($k->status !== 'checked_in') {
            throw ValidationException::withMessages(['kamar_baru' => 'Hanya kamar yang sedang ditempati yang bisa dipindah.']);
        }
        if ($baru->id === $k->room_id) {
            throw ValidationException::withMessages(['kamar_baru' => 'Pilih kamar yang berbeda.']);
        }
        $hariIni = today()->toImmutable();
        $keluar = CarbonImmutable::parse($k->check_out_date);
        if (! $keluar->gt($hariIni)) {
            throw ValidationException::withMessages(['kamar_baru' => 'Tamu dijadwalkan check-out hari ini.']);
        }

        return DB::transaction(function () use ($k, $r, $baru, $alasan, $oleh, $hariIni, $keluar) {
            $baru = Room::whereKey($baru->id)->lockForUpdate()->first();
            $this->periksaKamarBebas($baru, $hariIni, $keluar, $r->id, 'kamar_baru');
            if ($baru->status !== RoomStatus::Available) {
                throw ValidationException::withMessages(['kamar_baru' => "Kamar {$baru->room_number} belum siap ({$baru->status->label()})."]);
            }
            if ($baru->room_type_id !== $k->room_type_id) {
                $this->periksaSisa($this->kunciTipe([$baru->room_type_id]), [$baru->room_type_id => 1], $hariIni, $keluar, $r->id);
            }

            $k->update(['check_out_date' => $hariIni->toDateString(), 'status' => 'moved', 'actual_check_out_at' => now()]);
            $barisBaru = $r->rooms()->create([
                'room_type_id' => $baru->room_type_id,
                'room_id' => $baru->id,
                'check_in_date' => $hariIni->toDateString(),
                'check_out_date' => $keluar->toDateString(),
                'price_per_night' => $k->price_per_night,
                'discount_type' => $k->discount_type,
                'discount_value' => $k->discount_value,
                'actual_check_in_at' => now(),
                'status' => 'checked_in',
                'moved_from_id' => $k->id,
            ]);
            $this->tambahan->pindahkan($k, $barisBaru);

            $lama = $k->room;
            if ($lama && $lama->status->canTransitionTo(RoomStatus::VacantDirty)) {
                $this->statusKamar->change($lama, RoomStatus::VacantDirty, "Tamu {$r->reservation_code} pindah ke kamar {$baru->room_number}", $oleh);
                app(TugasHousekeepingService::class)->sinkron($lama, $lama->fresh()->status, $oleh);
            }
            $this->statusKamar->change($baru, RoomStatus::OccupiedClean, "Pindahan dari kamar {$lama?->room_number}, {$r->reservation_code}", $oleh);

            $this->susunUlangNota($r);
            $this->catat($r, $r->status, $oleh, "Pindah dari kamar {$lama?->room_number} ke {$baru->room_number}".($alasan ? ": {$alasan}" : ''));

            return $barisBaru;
        });
    }

    /* ======================== Bantuan ======================== */

    /** Uang muka berhasil dibayar (dipanggil dari notifikasi Midtrans, di dalam transaksi). */
    public function uangMukaDiterima(Reservation $r, string $keterangan): void
    {
        if ($r->status === 'pending_payment') {
            $r->update(['status' => 'confirmed']);
            $this->catat($r, 'confirmed', null, $keterangan);
        }
    }

    public static function rentang(?CarbonInterface $masuk, ?CarbonInterface $keluar): string
    {
        if (! $masuk || ! $keluar) {
            return '';
        }

        return $masuk->isSameMonth($keluar)
            ? $masuk->day.'–'.$keluar->translatedFormat('j M Y')
            : $masuk->translatedFormat('j M').' – '.$keluar->translatedFormat('j M Y');
    }

    private static function rp(float $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }

    public function catat(Reservation $r, ?string $status, ?User $oleh, ?string $catatan, string $jenis = 'status_change'): void
    {
        ReservationLog::create([
            'reservation_id' => $r->id,
            'event_type' => $jenis,
            'status' => $jenis === 'status_change' ? $status : null,
            'changed_by' => $oleh?->id,
            'changed_at' => now(),
            'note' => $catatan !== null ? mb_substr($catatan, 0, 255) : null,
        ]);
    }

    /** Akhiri reservasi (batal / tidak datang): baris kamar dibatalkan, nota ditutup, tagihan online yang belum dibayar kedaluwarsa. */
    private function akhiri(Reservation $r, string $status, ?string $catatanLog, ?User $oleh, string $catatanNota): void
    {
        $r->update(['status' => $status]);
        $r->rooms()->whereIn('status', ['booked'])->update(['status' => 'cancelled']);
        $this->tambahan->batalkan($r);
        foreach ($r->invoices()->where('status', '!=', 'cancelled')->get() as $nota) {
            $nota->update(['status' => 'cancelled', 'note' => mb_substr($catatanNota, 0, 255)]);
            Payment::where('invoice_id', $nota->id)->where('status', 'pending')->update(['status' => 'expired']);
        }
        $this->catat($r, $status, $oleh, $catatanLog);
    }

    private function susunUlangNota(Reservation $r): void
    {
        $r->unsetRelation('rooms');
        $nota = $r->stayInvoice()->where('status', '!=', 'cancelled')->first();
        if ($nota) {
            $this->nota->susunKamar($nota, $r, app(FolioService::class)->awalBelumDitagih($r));
        }
    }

    private function notaMenginap(Reservation $r, ?User $oleh): Invoice
    {
        $nota = Invoice::create([
            'invoice_number' => Nomor::nota(),
            'invoice_type' => 'stay',
            'customer_id' => $r->customer_id,
            'reservation_id' => $r->id,
            'payment_term' => 'immediate',
            'issue_date' => today()->toDateString(),
            'status' => 'issued',
            'issued_by' => $oleh?->id,
        ]);

        return $this->nota->susunKamar($nota, $r);
    }

    private function barisKamar(Reservation $r, RoomType $tipe, ?Room $room, CarbonInterface $masuk, CarbonInterface $keluar, ?float $harga = null, ?string $jenisDiskon = null, ?float $nilaiDiskon = null): ReservationRoom
    {
        return $r->rooms()->create([
            'room_type_id' => $tipe->id,
            'room_id' => $room?->id,
            'check_in_date' => $masuk->toDateString(),
            'check_out_date' => $keluar->toDateString(),
            'price_per_night' => $harga ?? $tipe->base_price,
            'discount_type' => $jenisDiskon,
            'discount_value' => $nilaiDiskon,
            'status' => 'booked',
        ]);
    }

    /** Kunci baris tipe kamar agar dua pemesanan bersamaan tidak sama-sama mengambil kamar terakhir. */
    private function kunciTipe(array $ids)
    {
        $tipe = RoomType::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($tipe->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['kamar' => 'Tipe kamar tidak ditemukan.']);
        }

        return $tipe;
    }

    /** @param  array<int, int>  $pesanan  id tipe => jumlah */
    private function periksaSisa($tipe, array $pesanan, CarbonInterface $masuk, CarbonInterface $keluar, ?int $kecuali = null): void
    {
        $sisa = $this->ketersediaan->sisa($masuk, $keluar, array_keys($pesanan), $kecuali);
        foreach ($pesanan as $id => $n) {
            $ada = $sisa[$id] ?? 0;
            if ($ada < $n) {
                throw ValidationException::withMessages(['kamar' => $ada === 0
                    ? "{$tipe[$id]->name} sudah penuh pada tanggal itu."
                    : "{$tipe[$id]->name} tinggal {$ada} kamar pada tanggal itu."]);
            }
        }
    }

    /** $pesanBentrok: pesan bila kamar sudah terpakai, dengan %s untuk nomor kamar. */
    private function periksaKamarBebas(?Room $room, CarbonInterface $masuk, CarbonInterface $keluar, ?int $kecuali, string $kunci = 'kamar', string $pesanBentrok = 'Kamar %s sudah dipesan pada tanggal itu.'): void
    {
        if (! $room || ! $room->is_active) {
            throw ValidationException::withMessages([$kunci => 'Kamar tidak ditemukan atau nonaktif.']);
        }
        if ($room->status === RoomStatus::Maintenance) {
            throw ValidationException::withMessages([$kunci => "Kamar {$room->room_number} sedang diperbaiki."]);
        }
        if (! $this->ketersediaan->bebas($room->id, $masuk, $keluar, $kecuali)) {
            throw ValidationException::withMessages([$kunci => sprintf($pesanBentrok, $room->room_number)]);
        }
    }

    private function periksaTanggal(CarbonInterface $masuk, CarbonInterface $keluar, int $maksMalam = self::MAKS_MALAM): void
    {
        $galat = match (true) {
            $masuk->lt(today()) => 'Tanggal check-in tidak boleh sebelum hari ini.',
            $keluar->lte($masuk) => 'Tanggal check-out harus setelah check-in.',
            $masuk->diffInDays($keluar) > $maksMalam => "Maksimal {$maksMalam} malam per pemesanan.",
            default => null,
        };
        if ($galat) {
            throw ValidationException::withMessages(['tanggal' => $galat]);
        }
    }

    private function tokenUnik(): string
    {
        do {
            $token = TokenAkses::baru();
        } while (Reservation::where('access_token', $token)->exists());

        return $token;
    }
}
