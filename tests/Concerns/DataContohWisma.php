<?php

namespace Tests\Concerns;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Data contoh modul kamar, sama dengan SIM_Wisma_Trans_Struktur_dan_Data.sql
 * (tanpa seeder; database tes dikosongkan tiap tes oleh RefreshDatabase).
 * Waktu acuan: Selasa, 6 Oktober 2026 pukul 10.00 WITA.
 */
trait DataContohWisma
{
    /** Kode akun uji => id pengguna. fo_hk (id 13) hanya ada di tes: staf Front Office + Housekeeping. */
    private array $akunUji = [
        'admin' => 1, 'manager' => 2, 'fo' => 3, 'fnb' => 4, 'hk' => 6, 'customer' => 11, 'fo_hk' => 13,
    ];

    protected function siapkanDataContoh(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00');

        DB::table('roles')->insert([
            ['id' => 1, 'code' => 'admin', 'name' => 'Administrator'],
            ['id' => 2, 'code' => 'manager', 'name' => 'Manager'],
            ['id' => 3, 'code' => 'front_office', 'name' => 'Front Office'],
            ['id' => 4, 'code' => 'housekeeping', 'name' => 'Housekeeping'],
            ['id' => 5, 'code' => 'fnb', 'name' => 'Food & Beverage'],
            ['id' => 6, 'code' => 'customer', 'name' => 'Customer'],
        ]);

        $sandi = Hash::make('password');
        $pengguna = [
            1 => ['Hendra Wijaya', 'admin@wismatrans.id', [1]],
            2 => ['Lina Hartono', 'manager@wismatrans.id', [2]],
            3 => ['Yusuf Ramadhan', 'yusuf@wismatrans.id', [3]],
            4 => ['Maya Sari', 'maya@wismatrans.id', [5]],
            5 => ['Bagus Setiawan', 'bagus@wismatrans.id', [5]],
            6 => ['Rahmat Hidayat', 'rahmat@wismatrans.id', [4]],
            7 => ['Rina Wulandari', 'rina@wismatrans.id', [4]],
            8 => ['Dewi Anggraini', 'dewi@wismatrans.id', [5, 4]],
            9 => ['Anto Saputra', 'anto@wismatrans.id', [5, 4]],
            10 => ['Sari Puspita', 'sari@wismatrans.id', [3, 5]],
            11 => ['Citra Lestari', 'citra@contoh.id', [6]],
            12 => ['Budi Santoso', 'budi@contoh.id', [6]],
            13 => ['Uji FO dan HK', 'fohk@wismatrans.id', [3, 4]],
        ];
        foreach ($pengguna as $id => [$nama, $email, $peran]) {
            DB::table('users')->insert(['id' => $id, 'name' => $nama, 'email' => $email, 'password' => $sandi, 'is_active' => 1, 'email_verified_at' => '2026-08-01 08:00:00']);
            foreach ($peran as $roleId) {
                DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
            }
        }

        DB::table('facilities')->insert([
            ['id' => 1, 'name' => 'AC', 'category' => 'room', 'is_maintainable' => 1],
            ['id' => 2, 'name' => 'Kloset', 'category' => 'room', 'is_maintainable' => 1],
            ['id' => 3, 'name' => 'TV', 'category' => 'room', 'is_maintainable' => 0],
            ['id' => 4, 'name' => 'WiFi', 'category' => 'public', 'is_maintainable' => 1],
            ['id' => 5, 'name' => 'Water Heater', 'category' => 'room', 'is_maintainable' => 1],
        ]);

        DB::table('room_types')->insert([
            ['id' => 1, 'code' => 'STD', 'name' => 'Standard Room', 'description' => 'Kamar standar dengan satu tempat tidur ganda atau dua tempat tidur tunggal', 'base_price' => 360000, 'max_capacity' => 2, 'photos' => '["std-1.jpg","std-2.jpg"]'],
            ['id' => 2, 'code' => 'DLX', 'name' => 'Deluxe Room', 'description' => 'Kamar lebih luas dengan pemanas air', 'base_price' => 460000, 'max_capacity' => 2, 'photos' => '["dlx-1.jpg","dlx-2.jpg","dlx-3.jpg"]'],
            ['id' => 3, 'code' => 'FAM', 'name' => 'Family Room', 'description' => 'Kamar keluarga untuk empat orang', 'base_price' => 550000, 'max_capacity' => 4, 'photos' => '["fam-1.jpg","fam-2.jpg"]'],
            ['id' => 4, 'code' => 'VIP', 'name' => 'VIP Room / Suite', 'description' => 'Suite dengan ruang duduk terpisah', 'base_price' => 650000, 'max_capacity' => 2, 'photos' => '["vip-1.jpg","vip-2.jpg"]'],
        ]);
        foreach ([1 => [1, 2, 3, 4], 2 => [1, 2, 3, 4, 5], 3 => [1, 2, 3, 4, 5], 4 => [1, 2, 3, 4, 5]] as $tipe => $fasilitas) {
            foreach ($fasilitas as $f) {
                DB::table('room_type_facilities')->insert(['room_type_id' => $tipe, 'facility_id' => $f]);
            }
        }

        // Keadaan kamar pada 6 Okt 2026 pukul 10.00
        $keadaan = [
            '103' => ['maintenance', null],
            '105' => ['vacant_dirty', '2026-10-06 07:15:00'],
            '112' => ['vacant_dirty', '2026-10-06 09:40:00'],
            '201' => ['vacant_dirty', '2026-10-06 09:15:00'],
            '202' => ['cleaning', null],
            '203' => ['occupied_dirty', '2026-10-06 09:30:00'],
            '204' => ['occupied_clean', null], '205' => ['occupied_clean', null], '206' => ['occupied_clean', null],
            '207' => ['occupied_clean', null], '208' => ['occupied_clean', null],
        ];
        $nomor = [...range(101, 116), ...range(201, 216)];
        foreach ($nomor as $i => $n) {
            [$tipe, $kasur] = match (true) {
                $n <= 110 => [1, 'twin'],
                $n <= 209 => [2, 'double'],
                $n <= 214 => [3, 'king'],
                default => [4, 'king'],
            };
            [$status, $kotor] = $keadaan[(string) $n] ?? ['available', null];
            DB::table('rooms')->insert([
                'id' => $i + 1, 'room_number' => (string) $n, 'room_type_id' => $tipe, 'floor' => intdiv($n, 100),
                'bed_type' => $kasur, 'status' => $status, 'dirty_since' => $kotor, 'is_active' => 1,
            ]);
        }

        DB::table('room_status_logs')->insert([
            ['room_id' => 18, 'status' => 'vacant_dirty', 'changed_by' => 3, 'changed_at' => '2026-10-05 12:00:00', 'note' => 'Tamu sebelumnya keluar'],
            ['room_id' => 3, 'status' => 'maintenance', 'changed_by' => 7, 'changed_at' => '2026-10-05 20:10:00', 'note' => 'AC tidak dingin, lihat MNT-0003'],
            ['room_id' => 5, 'status' => 'occupied_clean', 'changed_by' => 3, 'changed_at' => '2026-10-05 15:05:00', 'note' => 'Check-in RSV-0011'],
            ['room_id' => 19, 'status' => 'occupied_clean', 'changed_by' => 10, 'changed_at' => '2026-10-05 16:10:00', 'note' => 'Check-in RSV-0012'],
            ['room_id' => 5, 'status' => 'vacant_dirty', 'changed_by' => 3, 'changed_at' => '2026-10-06 07:15:00', 'note' => 'Check-out RSV-0011'],
            ['room_id' => 18, 'status' => 'cleaning', 'changed_by' => 6, 'changed_at' => '2026-10-06 09:00:00', 'note' => 'Mulai dibersihkan Rahmat dan Rina'],
            ['room_id' => 17, 'status' => 'vacant_dirty', 'changed_by' => 3, 'changed_at' => '2026-10-06 09:15:00', 'note' => 'Check-out RSV-0010'],
            ['room_id' => 19, 'status' => 'occupied_dirty', 'changed_by' => 10, 'changed_at' => '2026-10-06 09:30:00', 'note' => 'Tamu minta kamar dibersihkan'],
            ['room_id' => 12, 'status' => 'vacant_dirty', 'changed_by' => 3, 'changed_at' => '2026-10-06 09:40:00', 'note' => 'Check-out RSV-0010'],
        ]);
    }

    /**
     * Kriteria Smart Cleaning (nama sesuai naskah) dan bobot AHP dari penelitian terdahulu, seperti di basis data utama.
     * Matriks perbandingan: C1:C2 = 3, C1:C3 = 5, C2:C3 = 2.
     */
    protected function siapkanKriteriaAhp(): void
    {
        DB::table('cleaning_criteria')->insert([
            ['id' => 1, 'code' => 'C1', 'name' => 'Waktu menuju check-in tamu berikutnya', 'attribute_type' => 'cost',
                'description' => 'Menit sampai jam check-in tamu berikutnya; tanpa kedatangan hari ini = 1440', 'is_active' => 1],
            ['id' => 2, 'code' => 'C2', 'name' => 'Status kamar', 'attribute_type' => 'benefit',
                'description' => 'Kosong kotor = 3, terisi kotor = 2', 'is_active' => 1],
            ['id' => 3, 'code' => 'C3', 'name' => 'Durasi kamar sudah kotor', 'attribute_type' => 'benefit',
                'description' => 'Menit sejak kamar menjadi kotor (rooms.dirty_since)', 'is_active' => 1],
        ]);
        DB::table('ahp_sessions')->insert([
            'id' => 1, 'session_name' => 'Bobot dari penelitian terdahulu', 'calculated_at' => '2026-09-01 08:00:00', 'lambda_max' => 3.003697,
            'consistency_index' => 0.001848, 'random_index' => 0.58, 'consistency_ratio' => 0.003187, 'is_active' => 1,
        ]);
        DB::table('ahp_comparisons')->insert([
            ['ahp_session_id' => 1, 'criteria_row_id' => 1, 'criteria_column_id' => 2, 'comparison_value' => 3],
            ['ahp_session_id' => 1, 'criteria_row_id' => 1, 'criteria_column_id' => 3, 'comparison_value' => 5],
            ['ahp_session_id' => 1, 'criteria_row_id' => 2, 'criteria_column_id' => 3, 'comparison_value' => 2],
        ]);
        DB::table('ahp_criteria_weights')->insert([
            ['ahp_session_id' => 1, 'criteria_id' => 1, 'weight' => 0.647947],
            ['ahp_session_id' => 1, 'criteria_id' => 2, 'weight' => 0.229871],
            ['ahp_session_id' => 1, 'criteria_id' => 3, 'weight' => 0.122182],
        ]);
    }

    /**
     * Pengaturan tagihan instansi, termasuk rekening tujuan dari database/sql/2026-10-12-rekening-tagihan.sql,
     * supaya tes PDF tidak bergantung pada file SQL yang dijalankan manual.
     */
    protected function siapkanSettingTagihan(): void
    {
        DB::table('settings')->insert([
            ['setting_key' => 'reminder_days_before', 'value' => '3', 'type' => 'integer', 'description' => 'Pengingat tagihan mulai H-n'],
            ['setting_key' => 'institution_letter_threshold', 'value' => '10000000', 'type' => 'decimal', 'description' => 'Ambang surat tagihan resmi'],
            ['setting_key' => 'bank_name', 'value' => 'Bank BRI', 'type' => 'string', 'description' => 'Bank rekening tujuan'],
            ['setting_key' => 'bank_account_number', 'value' => '0123-01-000456-30-1', 'type' => 'string', 'description' => 'Nomor rekening tujuan'],
            ['setting_key' => 'bank_account_name', 'value' => 'Wisma Trans Malili', 'type' => 'string', 'description' => 'Atas nama rekening'],
        ]);
        Cache::forget('settings.all');
    }

    /** admin, manager, fo, fnb, hk, customer, atau fo_hk. */
    protected function akun(string $kode): User
    {
        return User::findOrFail($this->akunUji[$kode]);
    }

    protected function kamar(string $nomor): Room
    {
        return Room::where('room_number', $nomor)->firstOrFail();
    }

    protected function tipe(string $kode): RoomType
    {
        return RoomType::where('code', $kode)->firstOrFail();
    }
}
