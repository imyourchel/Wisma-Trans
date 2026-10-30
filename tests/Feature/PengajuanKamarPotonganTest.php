<?php

namespace Tests\Feature;

use App\Livewire\Staf\Reservasi\PengajuanInstansi;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\Reservasi\ReservasiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\DataContohWisma;
use Tests\TestCase;

/** Dialog setujui pengajuan instansi: hanya kamar bebas dengan jenis kasur, potongan bersama, dan ringkasan tagihan. */
class PengajuanKamarPotonganTest extends TestCase
{
    use DataContohWisma, RefreshDatabase;

    private Institution $pln;

    private Customer $pic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanDataContoh();
        Notification::fake();
        $this->pln = Institution::create(['code' => 'PLN', 'name' => 'PT PLN UP3 Palopo', 'default_billing_scheme' => 'due_after_checkout', 'due_days' => 14]);
        $this->pic = Customer::create(['full_name' => 'Fajar Nugroho', 'phone' => '081455500006', 'institution_id' => $this->pln->id, 'is_active' => true]);
        $this->pln->update(['primary_contact_id' => $this->pic->id]);
    }

    /** Pengajuan 12–14 Okt (2 malam). */
    private function ajukan(int $kamar = 3): Reservation
    {
        return app(ReservasiService::class)->ajukanInstansi($this->pic, $this->pln, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-14'), $kamar, 'Pemeliharaan jaringan', null);
    }

    /** Pengajuan lain yang disetujui memakai $nomor pada tanggal yang beririsan. */
    private function pakai(string $nomor, string $masuk = '2026-10-13', string $keluar = '2026-10-15'): void
    {
        $layanan = app(ReservasiService::class);
        $r = $layanan->ajukanInstansi($this->pic, $this->pln, CarbonImmutable::parse($masuk), CarbonImmutable::parse($keluar), 1, null, null);
        $layanan->setujui($r, $this->pln, [['room_id' => $this->kamar($nomor)->id, 'price' => 360000]], null, 'satu_nota', null, $this->akun('manager'));
    }

    private function dialog(Reservation $r)
    {
        return Livewire::actingAs($this->akun('manager'))->test(PengajuanInstansi::class)->call('open', $r->id, 'setuju');
    }

    private function id(string $nomor): string
    {
        return (string) $this->kamar($nomor)->id;
    }

    public function test_PK01_hanya_kamar_bebas_di_tanggal_pengajuan(): void
    {
        $this->pakai('104');
        // 103 sedang Perbaikan (data contoh); 104 dipakai reservasi lain yang beririsan; 105 dipakai di luar rentang
        $this->pakai('105', '2026-10-14', '2026-10-16');
        $this->dialog($this->ajukan())
            ->assertDontSee('data-kamar-bebas="103"', false)->assertDontSee('data-kamar-bebas="104"', false)
            ->assertSee('data-kamar-bebas="105"', false)->assertSee('data-kamar-bebas="101"', false)
            ->assertSee('Standard Room')->assertSee('tersedia di tanggal ini')->assertDontSee('bisa dijual');
    }

    public function test_PK02_tombol_kamar_menampilkan_kasur_dan_saring_chip(): void
    {
        $layar = $this->dialog($this->ajukan())
            ->assertSeeHtml('data-kelompok-kasur="twin_single"')->assertSeeHtml('data-kelompok-kasur="king"');
        // Tombol kamar: nomor lalu jenis kasur di bawahnya
        $this->assertMatchesRegularExpression('/data-kamar-bebas="101">\s*<input[^>]*>\s*<span[^>]*>101<\/span>\s*<span[^>]*>Twin Single<\/span>/', $layar->html());
        $this->assertMatchesRegularExpression('/Semua.*Twin Single.*Double Bed.*Queen Bed.*Twin Double.*King Bed/s', $layar->html());
        $layar->set('saringKasur', 'king')
            ->assertDontSee('data-kelompok-kasur="twin_single"', false)->assertDontSee('data-kamar-bebas="101"', false);
        $king = Room::where('bed_type', 'king')->where('status', '!=', 'maintenance')->pluck('room_number');
        foreach ($king as $n) {
            $layar->assertSee('data-kamar-bebas="'.$n.'"', false);
        }

        // Penghitung: kurang, sama, lebih dari pengajuan
        $layar->set('kamar', [$this->id('112')])->assertSee('1 dari 3 diajukan · kurang 2 kamar')
            ->set('kamar', [$this->id('112'), $this->id('114'), $this->id('116')])->assertDontSee('kurang')->assertDontSee('lebih')
            ->set('kamar', [$this->id('112'), $this->id('114'), $this->id('116'), $this->id('202')])->assertSee('lebih 1 dari pengajuan');
    }

    public function test_PK03_potongan_bersama_dan_kamar_yang_diatur_sendiri(): void
    {
        [$a, $b, $c] = [$this->id('101'), $this->id('102'), $this->id('104')];
        $layar = $this->dialog($this->ajukan())->set('kamar', [$a, $b])
            ->call('jenisBersama', 'percent')->set('potonganBersama.nilai', '10')->call('terapkanBersama')->assertHasNoErrors()
            ->assertSet("harga.{$a}.jenis", 'percent')->assertSet("harga.{$a}.nilai", '10')->assertSet("harga.{$b}.nilai", '10')
            // Kamar yang dipilih sesudahnya ikut 10%
            ->set('kamar', [$a, $b, $c])->assertSet("harga.{$c}.jenis", 'percent')->assertSet("harga.{$c}.nilai", '10')
            // B diatur sendiri menjadi Gratis
            ->call('ubahPotongan', $b)->call('jenisPotongan', $b, 'free')->assertSee('diatur sendiri')
            // Potongan bersama diubah: hanya kamar yang tidak diatur sendiri yang ikut
            ->set('potonganBersama.nilai', '20')
            ->assertSet("harga.{$a}.nilai", '20')->assertSet("harga.{$c}.nilai", '20')->assertSet("harga.{$b}.jenis", 'free')
            ->assertSeeHtml('wire:confirm="Timpa potongan 1 kamar yang diatur sendiri?"');

        // "Terapkan ke semua kamar" yang dikonfirmasi menimpa kamar yang diatur sendiri
        $layar->call('terapkanBersama')->assertSet("harga.{$b}.jenis", 'percent')->assertSet("harga.{$b}.nilai", '20')
            ->assertSet("harga.{$b}.sendiri", false)->assertDontSee('data-diatur-sendiri', false);

        // Batas: persen maks 100, nominal tidak melebihi harga per malam
        $layar->set('potonganBersama.nilai', '120')->call('terapkanBersama')->assertHasErrors(['potonganBersama.nilai'])
            ->call('jenisBersama', 'amount')->set('potonganBersama.nilai', '400000')->call('terapkanBersama')->assertHasErrors(['potonganBersama.nilai']);
    }

    public function test_PK04_total_ringkasan_sama_dengan_yang_tersimpan(): void
    {
        $r = $this->ajukan();
        [$a, $b, $c] = [$this->id('101'), $this->id('111'), $this->id('112')];
        $layar = $this->dialog($r)->set('kamar', [$a, $b, $c])
            ->call('jenisBersama', 'percent')->set('potonganBersama.nilai', '10')->call('terapkanBersama')
            // Nominal per kamar per malam: 50.000 × 2 malam
            ->call('jenisPotongan', $b, 'amount')->set("harga.{$b}.nilai", '50000')
            ->call('jenisPotongan', $c, 'free');
        // 101: 360.000 × 2 − 10% = 648.000; 111: 460.000 × 2 − 100.000 = 820.000; 112: gratis
        $layar->assertSeeHtml('data-total-tagihan>Rp1.468.000<')->assertSee('Rp2.560.000')->assertSee('−Rp50.000/malam');

        // Nominal melebihi harga per malam ditolak
        $layar->set("harga.{$b}.nilai", '500000')->call('setujui')->assertHasErrors(["harga.{$b}.nilai"]);
        $layar->set("harga.{$b}.nilai", '50000')->call('setujui')->assertHasNoErrors();

        $baris = $r->rooms()->with('room')->get()->keyBy('room.room_number');
        $this->assertSame(['percent', '10.00'], [$baris['101']->discount_type, $baris['101']->discount_value]);
        $this->assertSame(['amount', '100000.00'], [$baris['111']->discount_type, $baris['111']->discount_value]);
        $this->assertSame('0.00', $baris['112']->price_per_night);
        $this->assertSame(1468000.0, $baris->sum(fn ($k) => $k->biaya()));
    }

    public function test_PK05_kamar_yang_terpakai_sebelum_disimpan_ditolak_dengan_nomornya(): void
    {
        $r = $this->ajukan(1);
        $layar = $this->dialog($r)->set('kamar', [$this->id('110')]);
        $this->pakai('110');
        $layar->call('setujui')->assertHasErrors(['kamar'])->assertSee('Kamar 110 baru saja dipakai reservasi lain. Pilih kamar lain.')
            // Kamar terpilih tetap tampil, ditandai terpakai
            ->assertSee('data-kamar-bebas="110"', false)->assertSee('terpakai');
        $this->assertSame('waiting_approval', $r->fresh()->status);
    }
}
