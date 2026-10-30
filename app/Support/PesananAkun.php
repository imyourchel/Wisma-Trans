<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Semua pesanan milik satu akun untuk halaman Pesanan saya: kamar, makanan, laundry, dan aula,
 * disatukan dalam bentuk yang sama agar bisa ditampilkan dan disaring bersama.
 *
 * Pesanan dikenali dari data pelanggan yang tertaut ke akun, atau yang belum tertaut tetapi
 * bernomor HP sama dengan akun (pesanan lama sebelum akun ditautkan).
 * Bentuk yang sama dipakai tab "Pesanan terakhir" untuk tamu tanpa akun (dariKode, lihat PesananPerangkat).
 */
final class PesananAkun
{
    public const JENIS = ['kamar' => 'Kamar', 'makanan' => 'Makanan', 'laundry' => 'Laundry', 'aula' => 'Aula'];

    public const KEADAAN = [
        'semua' => 'Semua',
        'belum-bayar' => 'Belum dibayar',
        'diproses' => 'Aktif',
        'selesai' => 'Selesai',
        'batal' => 'Dibatalkan',
    ];

    /**
     * @return Collection<int, array{jenis: string, kode: string, judul: string, waktu: string, total: float,
     *     keadaan: string, label: string, catatan: ?string, aksi: list<array{0: string, 1: string, 2: bool}>, urut: int}>
     */
    public static function untuk(User $user): Collection
    {
        $pelanggan = self::pelanggan($user);

        if ($pelanggan->isEmpty()) {
            return collect();
        }

        return self::susun(
            fn ($q) => $q->whereIn('customer_id', $pelanggan),
            fn ($q) => $q->whereIn('customer_id', $pelanggan),
            fn ($q) => $q->whereIn('hall_bookings.customer_id', $pelanggan),
        );
    }

    /**
     * Pesanan tertentu menurut kodenya (kode reservasi, kode pesanan makanan/laundry, kode sewa aula), dalam bentuk yang
     * sama dengan untuk(). Pemanggil yang memastikan pesanan itu boleh dilihat (lihat PesananPerangkat).
     */
    public static function dariKode(array $kamar, array $layanan, array $aula): Collection
    {
        return self::susun(
            fn ($q) => $q->whereIn('reservation_code', $kamar),
            fn ($q) => $q->whereIn('order_code', $layanan),
            fn ($q) => $q->whereIn('hall_bookings.booking_code', $aula),
        );
    }

    /** Gabungan kamar, layanan, dan aula; tiap penyaring diterapkan ke kueri jenisnya. */
    private static function susun(Closure $kamar, Closure $layanan, Closure $aula): Collection
    {
        return collect()
            ->concat(self::kamar($kamar))
            ->concat(self::layanan($layanan))
            ->concat(self::aula($aula))
            ->sortByDesc('urut')
            // Tanggal dan bulan pemesanan: untuk penyaring periode dan pengelompokan, misalnya "Oktober 2026"
            ->map(function ($p) {
                $waktu = CarbonImmutable::createFromTimestamp($p['urut'], config('app.timezone'));

                return $p + ['tanggal' => $waktu->toDateString(), 'bulan' => $waktu->translatedFormat('F Y')];
            })
            ->values();
    }

    /** Id pelanggan milik akun: yang tertaut, atau yang belum tertaut tetapi bernomor HP sama. */
    public static function pelanggan(User $user): Collection
    {
        return Customer::withTrashed()
            ->where('user_id', $user->id)
            ->when(filled($user->phone), fn ($q) => $q->orWhere(fn ($q) => $q->whereNull('user_id')->where('phone', $user->phone)))
            ->pluck('id');
    }

    /** Reservasi yang akan atau sedang menginap, untuk kartu kode kamar (salin dan bagikan). */
    public static function kodeKamar(User $user): Collection
    {
        return Reservation::with(['rooms' => fn ($q) => $q->where('status', '!=', 'moved')->with('room')])
            ->whereIn('customer_id', self::pelanggan($user))->whereIn('status', ['confirmed', 'checked_in'])
            ->whereNotNull('access_token')->orderByRaw("FIELD(status, 'checked_in', 'confirmed')")->orderBy('id')->get();
    }

    private static function kamar(Closure $saring): Collection
    {
        $reservasi = DB::table('reservations')->where($saring)->get();
        if ($reservasi->isEmpty()) {
            return collect();
        }
        $kamar = DB::table('reservation_rooms')
            ->join('room_types', 'room_types.id', '=', 'reservation_rooms.room_type_id')
            ->whereIn('reservation_id', $reservasi->pluck('id'))
            ->where('reservation_rooms.status', '!=', 'moved')
            ->get(['reservation_rooms.*', 'room_types.name as tipe'])
            ->groupBy('reservation_id');
        // Biaya kamar dari nota menginap. Makanan dan laundry yang ditagihkan ke kamar tampil sebagai pesanan sendiri,
        // jadi tidak ikut dijumlahkan di sini.
        $biayaKamar = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.invoice_type', 'stay')->where('invoices.status', '!=', 'cancelled')
            ->whereIn('invoices.reservation_id', $reservasi->pluck('id'))
            ->whereIn('invoice_items.item_type', ['room', 'late_checkout'])
            ->groupBy('invoices.reservation_id')
            ->selectRaw('invoices.reservation_id, SUM(invoice_items.subtotal) as jumlah')
            ->pluck('jumlah', 'reservation_id');

        $pengajuan = DB::table('reservation_requests')->whereIn('reservation_id', $reservasi->pluck('id'))->get()->keyBy('reservation_id');

        return $reservasi->map(function ($r) use ($kamar, $biayaKamar, $pengajuan) {
            $baris = $kamar->get($r->id, collect());
            $aju = $pengajuan->get($r->id);
            $masuk = $baris->isEmpty() ? ($aju ? CarbonImmutable::parse($aju->check_in_date) : null) : CarbonImmutable::parse($baris->min('check_in_date'));
            $keluar = $baris->isEmpty() ? ($aju ? CarbonImmutable::parse($aju->check_out_date) : null) : CarbonImmutable::parse($baris->max('check_out_date'));
            $malam = $masuk ? (int) $masuk->diffInDays($keluar) : 0;
            $batasDp = $r->dp_due_at ? CarbonImmutable::parse($r->dp_due_at) : null;

            [$keadaan, $label, $catatan] = match ($r->status) {
                'pending_payment' => $batasDp?->isPast()
                    ? ['batal', 'Batas uang muka lewat', 'Uang muka tidak dibayar sampai batas waktu']
                    : ['belum-bayar', 'Menunggu uang muka', $batasDp ? 'Bayar uang muka sebelum '.$batasDp->translatedFormat('j M, H.i').' WITA' : null],
                'waiting_approval' => ['diproses', 'Menunggu persetujuan', 'Kami menghubungi Anda setelah pengajuan diperiksa'],
                'confirmed' => ['diproses', 'Terkonfirmasi', $masuk ? 'Check-in '.$masuk->translatedFormat('l, j M') : null],
                'checked_in' => ['diproses', 'Sedang menginap', $keluar ? 'Check-out '.$keluar->translatedFormat('l, j M') : null],
                'checked_out' => ['selesai', 'Selesai', null],
                'rejected' => ['batal', 'Ditolak', null],
                'no_show' => ['batal', 'Tidak hadir', null],
                default => ['batal', 'Dibatalkan', null],
            };

            $tipe = $baris->countBy('tipe')->map(fn ($n, $t) => $n > 1 ? "{$t} ×{$n}" : $t)->values()->join(', ');
            $url = $r->access_token ? route('reservasi.status', ['kode' => $r->reservation_code, 't' => $r->access_token]) : null;

            return [
                'jenis' => 'kamar',
                'kode' => $r->reservation_code,
                'judul' => $tipe !== '' ? $tipe : ($aju ? 'Pengajuan instansi'.($aju->event_name ? ' · '.$aju->event_name : '') : 'Reservasi kamar'),
                'waktu' => $masuk ? $masuk->translatedFormat('D, j M').' – '.$keluar->translatedFormat('D, j M Y').' · '.$malam.' malam · '.($baris->count() ?: ($aju->room_count ?? 0)).' kamar' : '',
                'total' => $biayaKamar->has($r->id)
                    ? (float) $biayaKamar[$r->id]
                    : (float) $baris->sum(fn ($k) => $k->price_per_night * CarbonImmutable::parse($k->check_in_date)->diffInDays(CarbonImmutable::parse($k->check_out_date))),
                'keadaan' => $keadaan,
                'label' => $label,
                'catatan' => $catatan,
                'aksi' => match (true) {
                    $url === null => [],
                    $keadaan === 'belum-bayar' => [['Bayar uang muka', $url, true]],
                    $keadaan === 'batal' => [['Pesan lagi', route('kamar'), false], ['Lihat detail', $url, false]],
                    default => [['Lihat detail', $url, false]],
                },
                'urut' => CarbonImmutable::parse($r->created_at ?? $masuk)->getTimestamp(),
            ];
        });
    }

    /** Pesanan makanan dan laundry. Permintaan perlengkapan kamar saja (amenity) tidak ditampilkan. */
    private static function layanan(Closure $saring): Collection
    {
        return ServiceOrder::with(['invoice', 'items.service'])
            ->where($saring)
            ->get()
            ->each(fn (ServiceOrder $o) => $o->batalkanBilaKedaluwarsa())
            ->filter(fn (ServiceOrder $o) => $o->items->contains(fn ($i) => in_array($i->service?->category, ['fnb', 'laundry'], true)))
            ->map(function (ServiceOrder $o) {
                [$keadaan, $label] = $o->keadaanTamu();
                $laundry = $o->jenisLayanan() === 'laundry';
                // Halaman status ada untuk pesanan makanan dan laundry yang punya tautan (situs, kasir, atau resepsionis)
                $online = (bool) $o->access_token;

                // Bayar sekarang dan Lihat detail membuka halaman yang sama, jadi hanya satu yang ditampilkan
                $aksi = match (true) {
                    ! $online => [],
                    $keadaan === 'belum-bayar' => [['Bayar sekarang', $o->urlStatus(), true]],
                    $keadaan === 'batal' => [['Pesan lagi', route($laundry ? 'laundry' : 'menu'), false], ['Lihat detail', $o->urlStatus(), false]],
                    default => [['Lihat detail', $o->urlStatus(), false]],
                };

                return [
                    'jenis' => $laundry ? 'laundry' : 'makanan',
                    'kode' => $o->order_code,
                    'judul' => $o->items->map(fn ($i) => ($i->service?->name ?? 'Layanan').($laundry && $i->service?->unit ? ' '.(float) $i->quantity.' '.$i->service->unit : ' ×'.(float) $i->quantity))->join(', '),
                    'waktu' => $o->order_date->translatedFormat('D, j M Y · H.i').' WITA'
                        .($laundry ? '' : ' · '.(ServiceOrder::SERVING[$o->serving_type] ?? 'Antar ke kamar')),
                    // Pesanan tamu menginap memakai nota kamar bersama, jadi totalnya dihitung dari item pesanan ini saja
                    'total' => (float) $o->items->sum('subtotal'),
                    'keadaan' => $keadaan,
                    'label' => $label,
                    'catatan' => match (true) {
                        $keadaan === 'belum-bayar' => 'Bayar sebelum '.$o->batasBayar()->translatedFormat($laundry ? 'j M, H.i' : 'H.i').' WITA',
                        $keadaan === 'batal' && $online && ! $laundry => 'Tidak dibayar sampai batas waktu',
                        $o->payment_mode === 'charge_to_room' => 'Ditagihkan ke kamar',
                        default => null,
                    },
                    'aksi' => $aksi,
                    'urut' => $o->order_date->getTimestamp(),
                ];
            })
            ->values();
    }

    private static function aula(Closure $saring): Collection
    {
        return DB::table('hall_bookings')
            ->join('halls', 'halls.id', '=', 'hall_bookings.hall_id')
            ->leftJoin('invoices', 'invoices.id', '=', 'hall_bookings.invoice_id')
            ->where($saring)
            ->get(['hall_bookings.*', 'halls.name as aula', 'invoices.total_amount as total_nota'])
            ->map(function ($b) {
                $mulai = CarbonImmutable::parse($b->start_at);
                $selesai = CarbonImmutable::parse($b->end_at);
                [$keadaan, $label] = match ($b->status) {
                    'pending' => ['diproses', 'Menunggu konfirmasi'],
                    'confirmed' => ['diproses', 'Terkonfirmasi'],
                    'completed' => ['selesai', 'Selesai'],
                    'rejected' => ['batal', 'Ditolak'],
                    default => ['batal', 'Dibatalkan'],
                };

                return [
                    'jenis' => 'aula',
                    'kode' => $b->booking_code,
                    'judul' => $b->aula.($b->purpose ? ' · '.$b->purpose : ''),
                    'waktu' => ($mulai->isSameDay($selesai)
                        ? $mulai->translatedFormat('D, j M Y · H.i').'–'.$selesai->format('H.i')
                        : $mulai->translatedFormat('D, j M H.i').' – '.$selesai->translatedFormat('D, j M Y H.i'))
                        .' WITA'.($b->participant_count ? ' · '.$b->participant_count.' peserta' : ''),
                    'total' => (float) ($b->total_nota ?? $b->rental_price),
                    'keadaan' => $keadaan,
                    'label' => $label,
                    'catatan' => $keadaan === 'diproses' && $b->status === 'pending' ? 'Kami menghubungi Anda setelah jadwal diperiksa' : null,
                    'aksi' => [],
                    'urut' => CarbonImmutable::parse($b->created_at ?? $b->start_at)->getTimestamp(),
                ];
            });
    }
}
