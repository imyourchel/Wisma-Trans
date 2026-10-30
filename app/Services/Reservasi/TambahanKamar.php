<?php

namespace App\Services\Reservasi;

use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Setting;
use App\Models\User;
use App\Support\Nomor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Tambahan pemesanan kamar: Extra bed (EXB01) dan Sarapan tambahan (SRP01), dipilih per kamar saat memesan.
 *
 * Tanpa tabel baru: satu pesanan layanan berdurasi per baris kamar (service_orders dengan service_start_date dan
 * service_end_date, kode TMB/2026/10/0001, ditagihkan ke kamar). Butirnya: jumlah = extra bed atau porsi per malam,
 * harga = harga layanan saat memesan, subtotal = jumlah × harga × malam. Tagihannya masuk nota menginap sebagai baris
 * amenity (Extra bed) dan fnb (Sarapan tambahan) dan disusun ulang setiap nota kamar disusun ulang (ubah tanggal,
 * perpanjang, pindah kamar, keluar lebih awal), jadi ikut uang muka, folio, check-out, nota, dan laporan pendapatan.
 *
 * Aturan: Extra bed maksimal extra_bed_max_qty per kamar (0 = tidak ditawarkan). Sarapan tambahan hanya bila Extra bed
 * dipilih, 1 sampai kapasitas dewasa kamar (RoomType::kapasitasKasur bila jenis kasurnya diketahui) + jumlah extra bed
 * orang per malam. Extra bed juga menambah daya tampung kamar 1 dewasa per unit (RoomType::muatKasur).
 * Extra bed TIDAK termasuk sarapan; sarapan tamu extra bed dipesan terpisah sebagai Sarapan tambahan.
 * Judul, keterangan, dan teks harga untuk tamu ditulis di sini (bukan dari keterangan di tabel services) supaya sama
 * di semua halaman, pesan, dan nota.
 */
class TambahanKamar
{
    public const AWALAN_KODE = 'TMB/';

    /** Petunjuk netral saat Extra bed dipilih tanpa Sarapan tambahan. */
    public const PETUNJUK_SARAPAN = 'Tamu extra bed belum mendapat sarapan. Centang sarapan tambahan bila diperlukan.';

    /** Judul pilihan: 'extra' => "Extra bed", 'sarapan' => "Sarapan tambahan". */
    public const NAMA = ['extra' => 'Extra bed', 'sarapan' => 'Sarapan tambahan'];

    /** Keterangan pilihan untuk tamu. */
    public static function keterangan(string $jenis): string
    {
        return $jenis === 'sarapan'
            ? 'Satu kali sarapan untuk 1 orang, disajikan setiap pagi selama menginap.'
            : 'Kasur tambahan, maksimal '.self::maksExtraBed().' per kamar. Belum termasuk sarapan.';
    }

    /** Satu baris rincian: "Extra bed × 1 · 3 malam" atau "Sarapan tambahan · 2 orang × 3 malam". */
    public static function teksRincian(string $jenis, int $jumlah, int $malam): string
    {
        return $jenis === 'sarapan'
            ? self::NAMA['sarapan']." · {$jumlah} orang × {$malam} malam"
            : self::NAMA['extra']." × {$jumlah} · {$malam} malam";
    }

    public static function maksExtraBed(): int
    {
        return max(0, (int) Setting::get('extra_bed_max_qty', 1));
    }

    public static function maksSarapan(RoomType $tipe, int $extraBed, ?string $kasur = null): int
    {
        $kap = $kasur ? $tipe->kapasitasKasur($kasur) : $tipe->kapasitasTerbesar();

        return $kap['dewasa'] + $extraBed;
    }

    /** Extra bed yang bisa ditambahkan ke satu kamar saat ini (0 bila layanannya tidak ditawarkan). */
    public function extraTersedia(): int
    {
        return $this->layanan()['extra'] ? self::maksExtraBed() : 0;
    }

    /**
     * Layanan yang sedang ditawarkan; harga dan keterangan dibaca dari tabel services.
     *
     * @return array{extra: ?Service, sarapan: ?Service}
     */
    public function layanan(): array
    {
        $s = Service::whereIn('code', [Service::EXTRA_BED, Service::SARAPAN_TAMBAHAN])->where('is_active', true)->whereNotNull('price')->get()->keyBy('code');
        $extra = self::maksExtraBed() > 0 ? $s->get(Service::EXTRA_BED) : null;

        return ['extra' => $extra, 'sarapan' => $extra ? $s->get(Service::SARAPAN_TAMBAHAN) : null];
    }

    /** "+Rp100.000/malam" (Extra bed) atau "+Rp80.000/orang" (Sarapan tambahan). */
    public static function hargaTeks(Service $s): string
    {
        return '+'.FolioService::rp($s->price).'/'.($s->code === Service::SARAPAN_TAMBAHAN ? 'orang' : $s->unit);
    }

    /**
     * Pilihan satu kamar yang dirapikan untuk formulir: jumlah dibatasi, Sarapan tambahan dilepas bila Extra bed tidak dipilih.
     *
     * @return array{extra: int, sarapan: int}
     */
    public function rapikan(array $pilih, RoomType $tipe, ?string $kasur = null): array
    {
        $layanan = $this->layanan();
        $extra = $layanan['extra'] ? min(max(0, (int) ($pilih['extra'] ?? 0)), self::maksExtraBed()) : 0;
        $sarapan = $extra > 0 && $layanan['sarapan'] ? min(max(0, (int) ($pilih['sarapan'] ?? 0)), self::maksSarapan($tipe, $extra, $kasur)) : 0;

        return ['extra' => $extra, 'sarapan' => $sarapan];
    }

    /**
     * Pemeriksaan di server sebelum disimpan.
     *
     * @return array{extra: int, sarapan: int}
     *
     * @throws ValidationException
     */
    public function periksa(array $pilih, RoomType $tipe, string $kamar = 'Kamar ini', string $kunci = 'tambahan', ?string $kasur = null): array
    {
        $extra = (int) ($pilih['extra'] ?? 0);
        $sarapan = (int) ($pilih['sarapan'] ?? 0);
        $layanan = $this->layanan();
        $galat = match (true) {
            $extra < 0 || $sarapan < 0 => 'Jumlah tambahan tidak valid.',
            $extra > 0 && ! $layanan['extra'] => 'Extra bed sedang tidak tersedia.',
            $extra > self::maksExtraBed() => 'Extra bed maksimal '.self::maksExtraBed().' per kamar.',
            $sarapan > 0 && $extra === 0 => 'Sarapan tambahan hanya bisa dipilih bersama extra bed.',
            $sarapan > 0 && ! $layanan['sarapan'] => 'Sarapan tambahan sedang tidak tersedia.',
            $sarapan > self::maksSarapan($tipe, $extra, $kasur) => "Sarapan tambahan {$kamar} maksimal ".self::maksSarapan($tipe, $extra, $kasur).' orang.',
            default => null,
        };
        if ($galat) {
            throw ValidationException::withMessages([$kunci => $galat]);
        }

        return ['extra' => $extra, 'sarapan' => $sarapan];
    }

    /**
     * Rincian harga satu kamar untuk ringkasan pemesanan.
     *
     * @return list<array{jenis: string, nama: string, jumlah: int, malam: int, teks: string, subtotal: float}>
     */
    public function rincian(array $pilih, int $malam): array
    {
        $layanan = $this->layanan();
        $baris = [];
        foreach (self::NAMA as $k => $nama) {
            $n = (int) ($pilih[$k] ?? 0);
            if ($n > 0 && $layanan[$k]) {
                $baris[] = ['jenis' => $k, 'nama' => $nama, 'jumlah' => $n, 'malam' => $malam, 'teks' => self::teksRincian($k, $n, $malam),
                    'subtotal' => $n * (float) $layanan[$k]->price * $malam];
            }
        }

        return $baris;
    }

    public function biaya(array $pilih, int $malam): float
    {
        return (float) collect($this->rincian($pilih, $malam))->sum('subtotal');
    }

    /** Simpan tambahan satu baris kamar (panggil di dalam transaksi, setelah periksa). Harga dibaca dari services saat ini. */
    public function simpan(ReservationRoom $baris, Reservation $r, array $pilih, ?User $oleh): ?ServiceOrder
    {
        $layanan = $this->layanan();
        $butir = collect(['extra', 'sarapan'])->filter(fn ($k) => (int) ($pilih[$k] ?? 0) > 0 && $layanan[$k]);
        if ($butir->isEmpty()) {
            return null;
        }
        $o = ServiceOrder::create([
            'order_code' => Nomor::berikut('service_orders', 'order_code', self::AWALAN_KODE.now()->format('Y/m').'/'),
            'order_type' => 'in_house',
            'customer_id' => $r->customer_id,
            'reservation_room_id' => $baris->id,
            'service_start_date' => $baris->check_in_date->toDateString(),
            'service_end_date' => $baris->check_out_date->toDateString(),
            'serving_type' => 'room_service',
            'payment_mode' => 'charge_to_room',
            'order_date' => now(),
            'status' => 'pending',
            'created_by' => $oleh?->id,
        ]);
        foreach ($butir as $k) {
            $n = (int) $pilih[$k];
            $o->items()->create([
                'service_id' => $layanan[$k]->id,
                'quantity' => $n,
                'unit_price' => $layanan[$k]->price,
                'subtotal' => $n * (float) $layanan[$k]->price * $baris->malam(),
            ]);
        }

        return $o;
    }

    /**
     * Susun ulang baris tambahan di nota menginap mengikuti tanggal kamar sekarang. Dipanggil NotaService::susunKamar,
     * jadi ubah tanggal, perpanjang, pindah kamar, dan keluar lebih awal ikut terhitung. Baris nota dikenali dari kode pesanan.
     */
    public function susunNota(Invoice $nota, Reservation $r): void
    {
        $pesanan = ServiceOrder::tambahanKamar()->with('items.service', 'reservationRoom.room')
            ->whereIn('reservation_room_id', $r->rooms()->select('id'))->where('status', '!=', 'cancelled')->orderBy('id')->get();

        foreach ($pesanan as $o) {
            $nota->items()->where('description', 'like', '%('.$o->order_code.')')->delete();
            $b = $o->reservationRoom;
            if (! $b || $b->status === 'cancelled') {
                $o->update(['status' => 'cancelled']);

                continue;
            }
            // Pindah kamar: tambahan dipindah ke baris baru tetapi tetap dihitung sejak tanggal mulai semula
            $mulai = $b->moved_from_id ? $o->service_start_date : $b->check_in_date;
            $malam = max(0, (int) $mulai->diffInDays($b->check_out_date));
            $o->update(['service_start_date' => $mulai->toDateString(), 'service_end_date' => $b->check_out_date->toDateString(), 'invoice_id' => $nota->id]);

            foreach ($o->items as $i) {
                $subtotal = (float) $i->quantity * (float) $i->unit_price * $malam;
                $i->update(['subtotal' => $subtotal]);
                if ($malam === 0) {
                    continue;
                }
                // Extra bed dan Sarapan tambahan selalu dua baris nota terpisah
                $jenis = $i->service?->code === Service::SARAPAN_TAMBAHAN ? 'sarapan' : 'extra';
                $nota->items()->create([
                    'item_type' => $i->service?->category === 'fnb' ? 'fnb' : 'amenity',
                    'description' => mb_substr(self::teksRincian($jenis, (int) $i->quantity, $malam)
                        .($b->room ? ', Kamar '.$b->room->room_number : '').' ('.$o->order_code.')', 0, 150),
                    'service_date' => $mulai->toDateString(),
                    'quantity' => (int) $i->quantity * $malam,
                    'unit_price' => $i->unit_price,
                    'subtotal' => $subtotal,
                ]);
            }
        }
    }

    /** Pindah kamar: tambahan ikut ke baris kamar yang baru. */
    public function pindahkan(ReservationRoom $lama, ReservationRoom $baru): void
    {
        ServiceOrder::tambahanKamar()->where('reservation_room_id', $lama->id)->where('status', '!=', 'cancelled')
            ->update(['reservation_room_id' => $baru->id]);
    }

    /** Reservasi batal, ditolak, atau tidak datang: tambahan ikut batal. */
    public function batalkan(Reservation $r): void
    {
        ServiceOrder::tambahanKamar()->whereIn('reservation_room_id', $r->rooms()->select('id'))->update(['status' => 'cancelled']);
    }

    /**
     * Tambahan satu baris kamar untuk tampilan staf, tamu, dan notifikasi.
     *
     * @return list<string> misalnya ["Extra bed × 1", "Sarapan tambahan 2 orang"]
     */
    public static function daftar(ReservationRoom $b): array
    {
        $o = $b->relationLoaded('tambahan') ? $b->tambahan : $b->tambahan()->with('items.service')->first();

        return $o ? $o->items->map(fn ($i) => $i->service?->code === Service::SARAPAN_TAMBAHAN
            ? self::NAMA['sarapan'].' '.(int) $i->quantity.' orang'
            : self::NAMA['extra'].' × '.(int) $i->quantity)->values()->all() : [];
    }

    /** "Extra bed × 1 · Sarapan tambahan 2 orang", atau null bila tidak ada. */
    public static function teks(ReservationRoom $b): ?string
    {
        return implode(' · ', self::daftar($b)) ?: null;
    }

    /**
     * Tambahan semua kamar sebuah reservasi, untuk pesan WhatsApp dan email.
     *
     * @return Collection<int, string> misalnya ["Kamar 111: Extra bed × 1 · Sarapan tambahan 2 orang"]
     */
    public static function ringkasan(Reservation $r): Collection
    {
        $r->loadMissing('rooms.roomType', 'rooms.room', 'rooms.tambahan.items.service');

        return $r->rooms->where('status', '!=', 'cancelled')
            ->map(fn (ReservationRoom $b) => ($t = self::teks($b)) ? ($b->room ? 'Kamar '.$b->room->room_number : $b->roomType->name).': '.$t : null)
            ->filter()->values();
    }
}
