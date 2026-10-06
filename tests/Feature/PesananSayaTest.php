<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Halaman Pesanan saya memuat semua jenis pesanan akun: kamar, makanan, laundry, dan aula. Hari ini 6 Okt 2026. */
class PesananSayaTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private int $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        $this->pelanggan = DB::table('customers')->insertGetId([
            'user_id' => $this->akun('customer')->id, 'full_name' => 'Citra Lestari', 'phone' => '081277770001', 'is_active' => 1,
        ]);
    }

    private function reservasi(string $kode, string $status, ?string $batasDp = null): void
    {
        $id = DB::table('reservations')->insertGetId([
            'reservation_code' => $kode, 'customer_id' => $this->pelanggan, 'booking_channel' => 'online', 'status' => $status,
            'dp_due_at' => $batasDp, 'created_at' => '2026-10-05 09:00:00',
        ]);
        foreach (['DLX', 'DLX', 'STD'] as $tipe) {
            DB::table('reservation_rooms')->insert([
                'reservation_id' => $id, 'room_type_id' => $this->tipe($tipe)->id, 'check_in_date' => '2026-10-10', 'check_out_date' => '2026-10-12',
                'price_per_night' => $this->tipe($tipe)->base_price, 'status' => 'booked',
            ]);
        }
    }

    public function test_PS01_semua_jenis_pesanan_tampil_dengan_keadaannya(): void
    {
        $this->reservasi('RSV/2026/10/0101', 'confirmed');
        $this->reservasi('RSV/2026/10/0102', 'pending_payment', '2026-10-05 20:00:00'); // batas uang muka lewat
        $this->reservasi('RSV/2026/10/0103', 'pending_payment', '2026-10-07 09:00:00');

        $cuci = DB::table('services')->insertGetId([
            'code' => 'L-CS', 'name' => 'Cuci setrika', 'category' => 'laundry', 'department' => 'housekeeping', 'unit' => 'kg', 'price' => 12000, 'is_active' => 1,
        ]);
        $laundry = DB::table('service_orders')->insertGetId([
            'order_code' => 'ORD/2026/10/0101', 'order_type' => 'in_house', 'customer_id' => $this->pelanggan, 'payment_mode' => 'charge_to_room',
            'order_date' => '2026-10-04 08:00:00', 'status' => 'in_progress',
        ]);
        DB::table('service_order_items')->insert(['service_order_id' => $laundry, 'service_id' => $cuci, 'quantity' => 3, 'unit_price' => 12000, 'subtotal' => 36000]);

        $aula = DB::table('halls')->insertGetId(['name' => 'Aula Utama', 'capacity' => 100, 'is_active' => 1]);
        DB::table('hall_bookings')->insert([
            'booking_code' => 'AUL/2026/10/0001', 'hall_id' => $aula, 'customer_id' => $this->pelanggan, 'start_at' => '2026-10-20 08:00:00',
            'end_at' => '2026-10-20 12:00:00', 'participant_count' => 60, 'rental_price' => 1500000, 'purpose' => 'Rapat dinas', 'status' => 'pending',
        ]);

        $this->actingAs($this->akun('customer'))->get(route('pesanan'))
            ->assertOk()
            ->assertViewHas('pesanan', fn ($p) => $p->pluck('keadaan', 'kode')->sortKeys()->all() === [
                'AUL/2026/10/0001' => 'diproses', 'ORD/2026/10/0101' => 'diproses',
                'RSV/2026/10/0101' => 'diproses', 'RSV/2026/10/0102' => 'batal', 'RSV/2026/10/0103' => 'belum-bayar',
            ])
            ->assertViewHas('pesanan', fn ($p) => $p->pluck('jenis')->unique()->sort()->values()->all() === ['aula', 'kamar', 'laundry'])
            ->assertSee('Deluxe Room ×2, Standard Room')
            ->assertSee('Sab, 10 Okt – Sen, 12 Okt 2026 · 2 malam · 3 kamar')
            ->assertSee('Rp2.560.000') // (460.000 × 2 + 360.000) × 2 malam
            ->assertSee('Terkonfirmasi')
            ->assertSee('Batas uang muka lewat')
            ->assertSee('Menunggu uang muka')
            ->assertSee('Cuci setrika 3 kg')
            ->assertSee('Rp36.000')
            ->assertSee('Sedang dicuci')
            ->assertSee('Ditagihkan ke kamar')
            ->assertSee('Aula Utama · Rapat dinas')
            ->assertSee('Sel, 20 Okt 2026 · 08.00–12.00 WITA · 60 peserta')
            ->assertSee('Menunggu konfirmasi')
            ->assertDontSee('?status=', false);

        // Pesanan tagihan kamar tidak dibatalkan otomatis walau belum dibayar
        $this->assertSame('in_progress', DB::table('service_orders')->where('id', $laundry)->value('status'));
    }

    public function test_PS03_total_kamar_tidak_menjumlahkan_layanan_yang_ditagihkan_ke_kamar(): void
    {
        $this->reservasi('RSV/2026/10/0101', 'checked_out');
        $rsv = DB::table('reservations')->value('id');
        $nota = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV/2026/10/0101', 'invoice_type' => 'stay', 'customer_id' => $this->pelanggan, 'reservation_id' => $rsv,
            'payment_term' => 'immediate', 'issue_date' => '2026-10-12', 'subtotal' => 2600000, 'total_amount' => 2600000, 'paid_amount' => 2600000, 'status' => 'paid',
        ]);
        foreach ([['room', 1300000], ['room', 1260000], ['fnb', 40000]] as [$jenis, $n]) {
            DB::table('invoice_items')->insert(['invoice_id' => $nota, 'item_type' => $jenis, 'description' => $jenis, 'quantity' => 1, 'unit_price' => $n, 'subtotal' => $n]);
        }

        $this->actingAs($this->akun('customer'))->get(route('pesanan'))
            ->assertViewHas('pesanan', fn ($p) => $p->firstWhere('kode', 'RSV/2026/10/0101')['total'] === 2560000.0);
    }

    public function test_PS02_pesanan_pelanggan_lain_tidak_tampil(): void
    {
        $lain = DB::table('customers')->insertGetId(['full_name' => 'Orang Lain', 'phone' => '081277770099', 'is_active' => 1]);
        DB::table('reservations')->insert(['reservation_code' => 'RSV/2026/10/0199', 'customer_id' => $lain, 'booking_channel' => 'online', 'status' => 'confirmed']);

        $this->actingAs($this->akun('customer'))->get(route('pesanan'))
            ->assertOk()
            ->assertViewHas('pesanan', fn ($p) => $p->isEmpty())
            ->assertDontSee('RSV/2026/10/0199');
    }
}
