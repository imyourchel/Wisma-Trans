<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class RoomType extends Model
{
    /**
     * Foto standar kamar di storage/app/public/kamar (dipakai bersama semua tipe, tidak ikut terhapus saat dibuang dari galeri):
     * ruangan/{jenis kasur}.png (tampak dari pintu), kasur/{jenis kasur}.png, fasilitas/*.png, dan condiment/condiment_{kapasitas}.png.
     * Galeri tiap tipe diisi dari database/sql/2026-10-23-foto-kamar.sql.
     */
    public const FOLDER_FOTO_STANDAR = 'kamar';

    /** Fasilitas yang sama di semua kamar. */
    public const FOTO_FASILITAS = [
        'kamar/fasilitas/kamar_mandi.png',
        'kamar/fasilitas/shower.png',
        'kamar/fasilitas/perlengkapan_mandi.png',
        'kamar/fasilitas/tv_meja_lemari.png',
        'kamar/fasilitas/kulkas.png',
    ];

    protected $fillable = ['code', 'name', 'description', 'base_price', 'max_capacity', 'photos'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'photos' => 'array',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'room_type_facilities');
    }

    /**
     * URL foto yang berkasnya benar-benar ada, sesuai urutan galeri (foto pertama = utama).
     *
     * @return list<string>
     */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])
            ->filter(fn ($p) => is_string($p) && str_contains($p, '/') && Storage::disk('public')->exists($p))
            ->map(fn ($p) => asset('storage/'.$p))
            ->values()
            ->all();
    }

    /** Tiga keunggulan per kode tipe untuk kartu di /kamar dan halaman detail; tipe tanpa entri tidak menampilkannya. */
    public const KEUNGGULAN = [
        'STD' => ['Sarapan 2 orang', 'Air panas', 'Kulkas mini'],
        'DLX' => ['Sarapan 2 orang', 'Ruang lebih lega', 'Kulkas mini'],
        'FAM' => ['Sarapan hingga 4 orang', '3 pilihan kasur', 'Kulkas mini'],
        'VIP' => ['Sarapan 2 orang', 'Ruang duduk', 'Ruangan paling luas'],
    ];

    /** @return list<string> */
    public function keunggulan(): array
    {
        return self::KEUNGGULAN[strtoupper((string) $this->code)] ?? [];
    }

    /** Badge karakter per kode tipe di atas nama tipe pada kartu /kamar; tipe tanpa entri tidak menampilkannya. */
    public const BADGE = [
        'STD' => 'Paling hemat',
        'DLX' => 'Lebih lega',
        'FAM' => 'Pilihan keluarga',
        'VIP' => 'Terbaik kami',
    ];

    public function badge(): ?string
    {
        return self::BADGE[strtoupper((string) $this->code)] ?? null;
    }

    /**
     * Aturan kapasitas per kode tipe, satu-satunya tempat aturan ini ditulis. Dewasa = kapasitas jenis kasur
     * (Room::BED_KAPASITAS) dibatasi 'dewasa' (Family: King 2, Twin Queen 4, Triple Single 3). Anak (di bawah 8 tahun)
     * tidak dihitung ke kapasitas dewasa, hanya dibatasi 'anak'. Extra bed menambah 1 dewasa per unit; batas anak tetap.
     * 'sarapan' = sarapan yang termasuk per kamar, tetap per tipe. Tipe tanpa entri: dewasa = kapasitas kasur dibatasi
     * max_capacity, tanpa anak, sarapan sebanyak dewasa.
     */
    public const ATURAN_KAPASITAS = [
        'STD' => ['dewasa' => 2, 'anak' => 1, 'sarapan' => 2],
        'DLX' => ['dewasa' => 2, 'anak' => 1, 'sarapan' => 2],
        'VIP' => ['dewasa' => 2, 'anak' => 1, 'sarapan' => 2],
        'FAM' => ['dewasa' => 4, 'anak' => 2, 'sarapan' => 4],
    ];

    /** Kecocokan penghuni satu kamar dengan jenis kasur (kecocokan()), urut dari yang terbaik. */
    public const MUAT = 'muat';

    public const MUAT_EXTRA = 'muat-extra';

    public const TIDAK_MUAT = 'tidak-muat';

    public const URUT_KECOCOKAN = [self::MUAT => 0, self::MUAT_EXTRA => 1, self::TIDAK_MUAT => 2];

    /**
     * Kapasitas satu jenis kasur di tipe ini menurut ATURAN_KAPASITAS.
     *
     * @return array{dewasa: int, anak: int}
     */
    public function kapasitasKasur(string $bed): array
    {
        $aturan = $this->aturanKapasitas();

        return ['dewasa' => min(Room::BED_KAPASITAS[$bed] ?? $aturan['dewasa'], $aturan['dewasa']), 'anak' => $aturan['anak']];
    }

    /** @return array{dewasa: int, anak: int, sarapan: int} */
    private function aturanKapasitas(): array
    {
        return self::ATURAN_KAPASITAS[strtoupper((string) $this->code)]
            ?? ['dewasa' => (int) $this->max_capacity, 'anak' => 0, 'sarapan' => (int) $this->max_capacity];
    }

    /** Sarapan yang termasuk per kamar tipe ini (Standard/Deluxe/VIP 2 orang, Family 4 orang). */
    public function sarapan(): int
    {
        return $this->aturanKapasitas()['sarapan'];
    }

    /** MUAT tanpa extra bed, MUAT_EXTRA bila cukup ditambah extra bed yang tersedia, atau TIDAK_MUAT. */
    public function kecocokan(string $bed, int $dewasa, int $anak, int $extraTersedia): string
    {
        $perlu = $this->extraPerlu($bed, $dewasa, $anak);

        return match (true) {
            $perlu === 0 => self::MUAT,
            $perlu !== null && $perlu <= $extraTersedia => self::MUAT_EXTRA,
            default => self::TIDAK_MUAT,
        };
    }

    /** Apakah dewasa dan anak muat di jenis kasur ini; tiap extra bed menambah 1 dewasa. */
    public function muatKasur(string $bed, int $dewasa, int $anak, int $extraBed = 0): bool
    {
        $k = $this->kapasitasKasur($bed);

        return $dewasa <= $k['dewasa'] + max(0, $extraBed) && $anak <= $k['anak'];
    }

    /** Extra bed paling sedikit agar tamu muat di jenis kasur ini (0 bila sudah muat); null bila anaknya melebihi batas anak. */
    public function extraPerlu(string $bed, int $dewasa, int $anak): ?int
    {
        $k = $this->kapasitasKasur($bed);

        return $anak > $k['anak'] ? null : max(0, $dewasa - $k['dewasa']);
    }

    /**
     * Apakah jenis kasur ini (di tipe ini) menampung lebih sedikit dewasa atau anak daripada kapasitas pesanan.
     * Jumlah tamu tidak disimpan, jadi Check-in dan pindah kamar membandingkan dengan kapasitas kasur yang dipesan.
     *
     * @param  array{dewasa: int, anak: int}  $pesanan
     */
    public function kurangDari(string $bed, array $pesanan): bool
    {
        $k = $this->kapasitasKasur($bed);

        return $k['dewasa'] < $pesanan['dewasa'] || $k['anak'] < $pesanan['anak'];
    }

    /** "2 dewasa + 1 anak" (tanpa anak bila batasnya 0) untuk pesan kepada tamu dan staf. */
    public function teksKapasitas(string $bed): string
    {
        return self::teksBatas($this->kapasitasKasur($bed));
    }

    /** @param  array{dewasa: int, anak: int}  $k */
    public static function teksBatas(array $k): string
    {
        return $k['dewasa'].' dewasa'.($k['anak'] > 0 ? ' + '.$k['anak'].' anak' : '');
    }

    /**
     * Dewasa dan anak terbesar dari jenis kasur yang dimiliki tipe ini, misalnya Family 4 dewasa + 2 anak.
     * Jenis kasur dari atribut kodeKasur (diisi pencarian kamar) atau dari kamar aktifnya.
     *
     * @param  list<string>|null  $kasur
     * @return array{dewasa: int, anak: int}
     */
    public function kapasitasTerbesar(?array $kasur = null): array
    {
        $kasur ??= $this->getAttribute('kodeKasur') ?? $this->rooms()->where('is_active', true)->distinct()->pluck('bed_type')->all();
        if ($kasur === []) {
            return array_intersect_key($this->aturanKapasitas(), ['dewasa' => 0, 'anak' => 0]);
        }
        $kap = array_map(fn ($b) => $this->kapasitasKasur($b), $kasur);

        return ['dewasa' => max(array_column($kap, 'dewasa')), 'anak' => max(array_column($kap, 'anak'))];
    }

    /** Batas per kamar di panel pencarian tamu: dewasa dan anak terbesar dari semua tipe di ATURAN_KAPASITAS. */
    public static function batasPencarian(): array
    {
        return ['dewasa' => max(array_column(self::ATURAN_KAPASITAS, 'dewasa')), 'anak' => max(array_column(self::ATURAN_KAPASITAS, 'anak'))];
    }

    /**
     * Foto condiment (gelas, cangkir, kopi/teh) mengikuti jumlah orang di kamar (kapasitasKasur): tersedia untuk 2, 3, dan 4 orang.
     */
    public static function fotoCondiment(int $orang): string
    {
        return 'kamar/condiment/condiment_'.min(4, max(2, $orang)).'.png';
    }

    /** Foto pertama = foto utama. Nama berkas data contoh (std-1.jpg) belum punya berkas, jadi memakai gambar pengganti. */
    protected function mainPhotoUrl(): Attribute
    {
        return Attribute::get(function () {
            $utama = $this->photos[0] ?? null;

            return $utama && str_contains($utama, '/') && Storage::disk('public')->exists($utama)
                ? asset('storage/'.$utama)
                : asset('ds/img/bangunan_depan.png');
        });
    }
}
