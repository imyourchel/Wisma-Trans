<?php

namespace App\Livewire\Staf\Reservasi;

use App\Models\Customer;
use App\Models\Institution;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Rules\EmailWajar;
use App\Services\Kamar\KetersediaanKamar;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Pelanggan\ProfilPelanggan;
use App\Services\Reservasi\ReservasiService;
use App\Support\NamaInstansi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengajuan reservasi instansi. Front Office dan Manager melihat; hanya Manager yang menyetujui
 * (menetapkan instansi, kamar, harga atau potongan, dan skema penagihan) atau menolak dengan alasan.
 * Instansi: pakai yang terdaftar (pencarian dan saran dari NamaInstansi) atau buat baru; tanpa pilihan bawaan bila
 * pengajuan belum tertaut. Narahubung pemohon tidak dipindah dari instansi lain dan tidak dijadikan utama tanpa diminta.
 */
class PengajuanInstansi extends Component
{
    /** menunggu | diputuskan */
    #[Url(except: 'menunggu')]
    public string $tab = 'menunggu';

    #[Locked]
    public ?int $selectedId = null;

    /** setuju | tolak */
    public string $mode = '';

    /** id instansi, atau 'baru' */
    public string $instansi = '';

    /** '' | terdaftar | baru */
    public string $pilihan = '';

    public string $cariInstansi = '';

    public string $namaBaru = '';

    /** Data instansi baru (institutions.address / phone / email); email kantor kosong = tagihan ke narahubung utama */
    public string $alamatBaru = '';

    public string $teleponBaru = '';

    public string $emailBaru = '';

    /** Pemohon tercatat sebagai narahubung instansi lain: biarkan | pindah */
    public string $pindahNarahubung = 'biarkan';

    /** Jadikan pemohon narahubung utama (tujuan tagihan) instansi terpilih */
    public bool $jadikanUtama = false;

    public string $skema = 'due_after_checkout';

    public string $tanggalTagih = '';

    public string $jatuhTempo = '14';

    public string $skemaLayanan = 'satu_nota';

    /** @var list<string> id kamar terpilih */
    public array $kamar = [];

    /** Saring cepat daftar kamar menurut jenis kasur ('' = semua) */
    public string $saringKasur = '';

    /**
     * id kamar => ['harga' => harga dasar tipe (tidak bisa diubah), 'jenis' => ''|percent|amount|free, 'nilai' => '',
     * 'sendiri' => potongan diubah sendiri, tidak ikut potongan bersama]. Nominal (amount) diisi per kamar per malam.
     */
    public array $harga = [];

    /** Potongan untuk semua kamar: ['jenis' => ''|percent|amount|free, 'nilai' => ''] */
    public array $potonganBersama = ['jenis' => '', 'nilai' => ''];

    /** Potongan bersama sudah diterapkan: kamar yang dipilih sesudahnya dan kamar yang tidak diatur sendiri ikut memakainya */
    public bool $bersamaAktif = false;

    /** Baris kamar yang pengatur potongannya sedang terbuka */
    public string $bukaPotongan = '';

    public string $catatan = '';

    public string $alasan = '';

    public function mount(): void
    {
        Gate::authorize('view-reservations');
    }

    public function open(int $id, string $mode): void
    {
        Gate::authorize('approve-reservations');
        $r = Reservation::with('customer')->where('status', 'waiting_approval')->findOrFail($id);
        $this->resetValidation();
        $this->reset('instansi', 'pilihan', 'cariInstansi', 'namaBaru', 'alamatBaru', 'teleponBaru', 'emailBaru', 'pindahNarahubung', 'jadikanUtama', 'kamar', 'saringKasur', 'harga', 'potonganBersama', 'bersamaAktif', 'bukaPotongan', 'catatan', 'alasan', 'tanggalTagih', 'jatuhTempo');
        $this->selectedId = $r->id;
        $this->mode = $mode === 'tolak' ? 'tolak' : 'setuju';
        // Hanya instansi yang tertaut ke pengajuan ini yang terpilih; instansi narahubung tidak dipakai sebagai bawaan
        $inst = $r->institution;
        $this->instansi = $inst ? (string) $inst->id : '';
        $this->pilihan = $inst ? 'terdaftar' : '';
        $this->skema = $inst?->default_billing_scheme ?? 'due_after_checkout';
        $this->isiTagihanInstansi($inst);
        $this->skemaLayanan = 'satu_nota';
        // Nama tulisan pemohon menjadi usulan nama bila Manager memilih "Buat instansi baru"
        $this->namaBaru = (string) self::namaTulis($r);
        $this->aturNarahubung();
    }

    /** Tanggal tagih dan jatuh tempo dari instansi terdaftar (billing_day / due_days); kosong bila instansi belum mengisinya. */
    private function isiTagihanInstansi(?Institution $inst): void
    {
        if ($inst) {
            $this->tanggalTagih = $inst->billing_day ? (string) $inst->billing_day : '';
            $this->jatuhTempo = $inst->due_days ? (string) $inst->due_days : '';
        }
    }

    /** Nama instansi yang ditulis pemohon di situs ("Instansi: …" di catatan), bila belum tertaut saat diajukan. */
    public static function namaTulis(Reservation $r): ?string
    {
        return $r->instansiTulis();
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->mode = '';
        $this->resetValidation();
    }

    public function updatedKamar(): void
    {
        // Harga kamar mengikuti harga dasar tipenya dan tidak diubah; kesepakatan dicatat sebagai potongan
        $harga = Room::with('roomType')->whereIn('id', array_map('intval', $this->kamar))->get()->keyBy('id');
        // Kamar yang baru dipilih memakai potongan bersama yang sedang aktif
        $bersama = $this->bersamaAktif ? $this->potonganBersama : ['jenis' => '', 'nilai' => ''];
        foreach ($this->kamar as $id) {
            $this->harga[$id] ??= ['harga' => (string) (int) $harga->get((int) $id)?->roomType->base_price, 'jenis' => $bersama['jenis'], 'nilai' => $bersama['nilai'], 'sendiri' => false];
        }
        $this->harga = array_intersect_key($this->harga, array_flip($this->kamar));
        if (! isset($this->harga[$this->bukaPotongan])) {
            $this->bukaPotongan = '';
        }
    }

    /** Ganti jenis potongan satu kamar; nilai lama dikosongkan agar persen tidak terbaca sebagai rupiah. Kamar ini lalu diatur sendiri. */
    public function jenisPotongan(string $id, string $jenis): void
    {
        Gate::authorize('approve-reservations');
        if (isset($this->harga[$id]) && in_array($jenis, ['', 'percent', 'amount', 'free'], true)) {
            $this->harga[$id]['jenis'] = $jenis;
            $this->harga[$id]['nilai'] = '';
            $this->harga[$id]['sendiri'] = true;
            $this->resetValidation("harga.{$id}.nilai");
        }
    }

    /** Besar potongan satu kamar diubah dari isiannya: kamar itu diatur sendiri. */
    public function updatedHarga(mixed $nilai, string $kunci): void
    {
        [$id, $kolom] = array_pad(explode('.', $kunci, 2), 2, null);
        if ($kolom === 'nilai' && isset($this->harga[$id])) {
            $this->harga[$id]['sendiri'] = true;
        }
    }

    /** Buka atau tutup pengatur potongan satu baris kamar. */
    public function ubahPotongan(string $id): void
    {
        $this->bukaPotongan = $this->bukaPotongan === $id || ! isset($this->harga[$id]) ? '' : $id;
    }

    /** Ganti jenis potongan bersama. Bila sudah diterapkan, kamar yang tidak diatur sendiri langsung ikut. */
    public function jenisBersama(string $jenis): void
    {
        Gate::authorize('approve-reservations');
        if (in_array($jenis, ['', 'percent', 'amount', 'free'], true)) {
            $this->potonganBersama = ['jenis' => $jenis, 'nilai' => ''];
            $this->resetValidation('potonganBersama.nilai');
            $this->sinkronBersama();
        }
    }

    public function updatedPotonganBersama(): void
    {
        $this->sinkronBersama();
    }

    /** Potongan bersama yang aktif mengikuti ke kamar yang tidak diatur sendiri; kamar yang diatur sendiri tidak ditimpa. */
    private function sinkronBersama(): void
    {
        if (! $this->bersamaAktif) {
            return;
        }
        foreach ($this->harga as $id => $h) {
            if (! ($h['sendiri'] ?? false)) {
                $this->harga[$id]['jenis'] = $this->potonganBersama['jenis'];
                $this->harga[$id]['nilai'] = $this->potonganBersama['nilai'];
            }
        }
    }

    /** "Terapkan ke semua kamar": semua kamar terpilih, termasuk yang diatur sendiri (dikonfirmasi di tampilan), memakai potongan bersama. */
    public function terapkanBersama(): void
    {
        Gate::authorize('approve-reservations');
        $jenis = $this->potonganBersama['jenis'] ?? '';
        $nilai = (float) ($this->potonganBersama['nilai'] ?? 0);
        $termurah = collect($this->harga)->min(fn ($h) => (float) $h['harga']);
        $pesan = match (true) {
            ! in_array($jenis, ['', 'percent', 'amount', 'free'], true) => 'Pilih jenis potongan.',
            in_array($jenis, ['percent', 'amount'], true) && $nilai <= 0 => 'Isi besar potongan, atau pilih Tanpa.',
            $jenis === 'percent' && $nilai > 100 => 'Potongan maksimal 100%.',
            $jenis === 'amount' && $termurah !== null && $nilai > $termurah => 'Potongan melebihi harga per malam kamar termurah ('.self::rupiah($termurah).').',
            default => null,
        };
        if ($pesan) {
            $this->addError('potonganBersama.nilai', $pesan);

            return;
        }
        $this->resetValidation('potonganBersama.nilai');
        foreach ($this->harga as $id => $h) {
            $this->harga[$id] = ['harga' => $h['harga'], 'jenis' => $jenis, 'nilai' => $this->potonganBersama['nilai'], 'sendiri' => false];
            $this->resetValidation("harga.{$id}.nilai");
        }
        $this->bersamaAktif = true;
        $this->dispatch('toast', teks: count($this->harga) > 0 ? 'Potongan diterapkan ke '.count($this->harga).' kamar.' : 'Potongan dipakai untuk kamar yang dipilih berikutnya.');
    }

    private static function rupiah(float $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }

    public function updatedInstansi(string $nilai): void
    {
        if ($nilai === 'baru') {
            $this->pilihan = 'baru';
        } elseif ($inst = Institution::find((int) $nilai)) {
            $this->pilihan = 'terdaftar';
            $this->skema = $inst->default_billing_scheme;
            $this->isiTagihanInstansi($inst);
        }
        $this->resetValidation(['instansi', 'namaBaru', 'alamatBaru', 'teleponBaru', 'emailBaru']);
        $this->aturNarahubung();
    }

    public function updatedPilihan(string $nilai): void
    {
        $this->instansi = match ($nilai) {
            'baru' => 'baru',
            'terdaftar' => ctype_digit($this->instansi) ? $this->instansi : '',
            default => '',
        };
        $this->resetValidation(['instansi', 'namaBaru', 'alamatBaru', 'teleponBaru', 'emailBaru']);
        $this->aturNarahubung();
    }

    public function updatedPindahNarahubung(): void
    {
        $this->resetValidation('pindahNarahubung');
        $this->aturNarahubung(false);
    }

    /** Pilih instansi terdaftar dari hasil pencarian atau saran. */
    public function pakaiInstansi(int $id): void
    {
        Gate::authorize('approve-reservations');
        $this->instansi = (string) Institution::findOrFail($id)->id;
        $this->cariInstansi = '';
        $this->updatedInstansi($this->instansi);
    }

    /** Ganti instansi terdaftar yang sudah dipilih (kembali ke pencarian). */
    public function gantiInstansi(): void
    {
        $this->instansi = '';
        $this->aturNarahubung();
    }

    /** Pemohon pengajuan yang terbuka. */
    private function pemohon(): ?Customer
    {
        return $this->selectedId ? Reservation::find($this->selectedId)?->customer : null;
    }

    /**
     * Keadaan narahubung untuk instansi terpilih: instansi lain tempat pemohon tercatat, apakah pemohon akan menjadi
     * narahubung instansi terpilih, dan usulan centang narahubung utama.
     *
     * @return array{pemohon: ?Customer, lain: ?Institution, terkait: bool, utamaLain: bool, usulUtama: bool}
     */
    private function keadaanNarahubung(): array
    {
        $pic = $this->pemohon();
        $baru = $this->instansi === 'baru';
        $inst = ctype_digit($this->instansi) ? Institution::with('primaryContact')->find((int) $this->instansi) : null;
        $lain = $pic?->institution_id && ($baru || ($inst && $pic->institution_id !== $inst->id)) ? $pic->institution : null;
        $terkait = $pic && ($baru || $inst) && (! $lain || $this->pindahNarahubung === 'pindah');

        return [
            'pemohon' => $pic,
            'lain' => $lain,
            'terkait' => (bool) $terkait,
            // Satu orang hanya bisa menjadi narahubung utama satu instansi; yang utama di instansi lain diganti dulu
            'utamaLain' => (bool) ($lain && $lain->primary_contact_id === $pic->id),
            // Dicentang otomatis bila instansi belum punya narahubung utama atau narahubung utamanya nonaktif
            'usulUtama' => (bool) ($terkait && $inst && $inst->primary_contact_id !== $pic->id && (! $inst->primaryContact || ! $inst->primaryContact->is_active)),
        ];
    }

    private function aturNarahubung(bool $ulangPindah = true): void
    {
        if ($ulangPindah) {
            $this->pindahNarahubung = 'biarkan';
        }
        $this->jadikanUtama = $this->keadaanNarahubung()['usulUtama'];
    }

    public function setujui(ReservasiService $layanan, NotifikasiReservasi $notifikasi): void
    {
        Gate::authorize('approve-reservations');
        $r = Reservation::with('request', 'customer')->findOrFail($this->selectedId);
        $baru = $this->instansi === 'baru';
        $this->namaBaru = trim((string) preg_replace('/\s+/', ' ', $this->namaBaru));
        $this->alamatBaru = trim($this->alamatBaru);
        $this->teleponBaru = trim($this->teleponBaru);
        $this->emailBaru = Str::lower(trim($this->emailBaru));
        $this->tanggalTagih = trim($this->tanggalTagih);
        $this->jatuhTempo = trim($this->jatuhTempo);
        $this->validate([
            'instansi' => ['required', $baru ? 'string' : Rule::exists('institutions', 'id')],
            // Instansi baru yang normalisasinya sama dengan instansi terdaftar ditolak ("pt vale indonesia tbk." = "PT Vale Indonesia Tbk")
            'namaBaru' => [$baru ? 'required' : 'nullable', 'string', 'min:3', 'max:150', function ($atribut, $nilai, $gagal) use ($baru) {
                if ($baru && $ada = NamaInstansi::sama((string) $nilai)) {
                    $gagal("Sudah terdaftar sebagai {$ada->name}. Pilih dari daftar.");
                }
            }],
            'alamatBaru' => [$baru ? 'required' : 'nullable', 'string', 'max:500'],
            'teleponBaru' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'emailBaru' => ['nullable', 'max:150', new EmailWajar],
            'skema' => ['required', Rule::in(array_keys(Institution::SKEMA))],
            // Wajib menurut skema, untuk instansi baru maupun terdaftar; rentang sama dengan formulir Pelanggan › Instansi
            'tanggalTagih' => [Rule::requiredIf($this->skema === 'monthly_fixed_date'), 'nullable', 'integer', 'between:1,31'],
            'jatuhTempo' => [Rule::requiredIf($this->skema === 'due_after_checkout'), 'nullable', 'integer', 'between:1,365'],
            'skemaLayanan' => ['required', Rule::in(array_keys(Reservation::SKEMA_LAYANAN))],
            'kamar' => ['required', 'array', 'min:1'],
            'harga.*.jenis' => ['nullable', Rule::in(['', 'percent', 'amount', 'free'])],
            'harga.*.nilai' => ['nullable', 'numeric', 'min:0'],
            'catatan' => ['nullable', 'string', 'max:200'],
        ], [
            'instansi.required' => 'Pilih instansi terdaftar atau buat instansi baru.',
            'instansi.exists' => 'Pilih instansi terdaftar atau buat instansi baru.',
            'namaBaru.required' => 'Isi nama instansi baru.',
            'namaBaru.min' => 'Minimal 3 huruf.',
            'alamatBaru.required' => 'Isi alamat instansi.',
            'alamatBaru.max' => 'Maksimal 500 karakter.',
            'teleponBaru.regex' => 'Isi angka, misalnya 0474 321123.',
            'teleponBaru.max' => 'Maksimal 20 karakter.',
            'emailBaru.max' => 'Maksimal 150 karakter.',
            'tanggalTagih.required' => 'Isi tanggal tagih bulanan.',
            'tanggalTagih.*' => 'Tanggal tagih 1–31.',
            'jatuhTempo.required' => 'Isi jatuh tempo (hari).',
            'jatuhTempo.*' => 'Jatuh tempo 1–365 hari.',
            'kamar.required' => 'Pilih kamar untuk pengajuan ini.',
            'harga.*.nilai.*' => 'Potongan tidak valid.',
        ]);
        // Harga selalu diambil ulang dari harga dasar tipe kamar, bukan dari isian
        $dasar = Room::with('roomType')->whereIn('id', array_map('intval', $this->kamar))->get()->mapWithKeys(fn ($k) => [$k->id => (float) $k->roomType->base_price]);
        foreach ($this->harga as $id => $h) {
            $jenis = $h['jenis'] ?? '';
            $nilai = (float) ($h['nilai'] ?? 0);
            $pesan = match (true) {
                in_array($jenis, ['percent', 'amount'], true) && $nilai <= 0 => 'Isi besar potongan, atau pilih Tanpa.',
                $jenis === 'percent' && $nilai > 100 => 'Potongan maksimal 100%.',
                $jenis === 'amount' && $nilai > (float) ($dasar[(int) $id] ?? 0) => 'Potongan melebihi harga per malam.',
                default => null,
            };
            if ($pesan) {
                $this->addError("harga.{$id}.nilai", $pesan);

                return;
            }
        }
        $nh = $this->keadaanNarahubung();
        if ($this->pindahNarahubung === 'pindah' && $nh['utamaLain']) {
            $this->addError('pindahNarahubung', "{$nh['pemohon']->full_name} narahubung utama {$nh['lain']->name}. Ganti narahubung utama instansi itu dulu di Pelanggan › Instansi.");

            return;
        }
        $malam = $r->request->malam();

        DB::transaction(function () use ($r, $layanan, $baru, $dasar, $nh, $malam) {
            $pic = $r->customer;
            $inst = $baru
                ? Institution::create([
                    'name' => $this->namaBaru,
                    'address' => $this->alamatBaru,
                    'phone' => $this->teleponBaru ?: null,
                    'email' => $this->emailBaru ?: null,
                    // Instansi baru: pemohon menjadi narahubung utama, kecuali ia tetap tercatat di instansi lain
                    'primary_contact_id' => $nh['terkait'] ? $r->customer_id : null,
                    'default_billing_scheme' => $this->skema,
                    'billing_day' => $this->skema === 'monthly_fixed_date' ? (int) $this->tanggalTagih : null,
                    'due_days' => $this->skema === 'due_after_checkout' ? (int) $this->jatuhTempo : null,
                ])
                : Institution::findOrFail((int) $this->instansi);
            // Instansi terdaftar: nilai yang diubah Manager berlaku juga untuk tagihan instansi ini berikutnya
            if (! $baru) {
                $ubah = match ($this->skema) {
                    'monthly_fixed_date' => ['billing_day' => (int) $this->tanggalTagih],
                    'due_after_checkout' => ['due_days' => (int) $this->jatuhTempo],
                    default => [],
                };
                $inst->fill($ubah);
                if ($inst->isDirty()) {
                    $inst->save();
                }
            }

            $layanan->setujui($r, $inst, collect($this->kamar)->map(function ($id) use ($dasar, $malam) {
                $jenis = $this->harga[$id]['jenis'] ?? '';
                $potong = in_array($jenis, ['percent', 'amount'], true);
                $nilai = (float) ($this->harga[$id]['nilai'] ?? 0);

                // Kamar gratis dicatat dengan harga 0 pada baris itu. Nominal diisi per malam; reservation_rooms menyimpan
                // potongan nominal untuk seluruh malam (ReservationRoom::potongan), jadi dikalikan jumlah malam.
                return [
                    'room_id' => (int) $id,
                    'price' => $jenis === 'free' ? 0.0 : (float) ($dasar[(int) $id] ?? 0),
                    'discount_type' => $potong ? $jenis : null,
                    'discount_value' => $potong ? ($jenis === 'amount' ? $nilai * $malam : $nilai) : null,
                ];
            })->all(), $this->skema, $this->skemaLayanan, trim($this->catatan) ?: null, Auth::user());

            // Reservasi tetap atas nama pemohon. Narahubung instansi lain hanya dipindah bila Manager memilih "Pindahkan";
            // narahubung utama hanya diganti bila dicentang.
            if ($pic && $nh['terkait']) {
                $pic->refresh()->update(['institution_id' => $inst->id, 'is_active' => $pic->is_active || $this->jadikanUtama || $this->pindahNarahubung === 'pindah']);
                if (! $baru && $this->jadikanUtama && $inst->primary_contact_id !== $pic->id) {
                    $inst->update(['primary_contact_id' => $pic->id]);
                }
            }
            if ($pic) {
                ProfilPelanggan::lupakan($pic->id, $inst->id);
            }
        });

        $notifikasi->keputusanInstansi($r->fresh(['rooms', 'customer']));
        $this->close();
        $this->dispatch('toast', teks: "Pengajuan {$r->reservation_code} disetujui.");
    }

    public function tolak(ReservasiService $layanan, NotifikasiReservasi $notifikasi): void
    {
        Gate::authorize('approve-reservations');
        $this->validate(['alasan' => 'required|string|max:200'], ['alasan.required' => 'Isi alasan penolakan; alasan ini dikirim ke pemohon.']);
        $r = Reservation::findOrFail($this->selectedId);
        $layanan->tolak($r, trim($this->alasan), Auth::user());
        $notifikasi->keputusanInstansi($r->fresh(['customer']), trim($this->alasan));
        $this->close();
        $this->dispatch('toast', teks: "Pengajuan {$r->reservation_code} ditolak.");
    }

    public function render(KetersediaanKamar $ketersediaan): View
    {
        $daftar = Reservation::query()
            ->with(['customer.institution', 'institution', 'request', 'rooms.room', 'logs'])
            ->whereHas('request')
            ->when($this->tab === 'menunggu', fn ($q) => $q->where('status', 'waiting_approval')->orderBy('id'),
                fn ($q) => $q->where('status', '!=', 'waiting_approval')->orderByDesc('id')->limit(50))
            ->get();

        $r = $this->selectedId ? Reservation::with('request', 'customer.institution', 'institution')->find($this->selectedId) : null;
        $setuju = $r && $this->mode === 'setuju';
        $namaTulis = $r ? self::namaTulis($r) : null;
        // Saran hanya petunjuk untuk Manager (tidak dipilih otomatis), lengkap dengan riwayat menginap dan narahubung utamanya
        $saran = $setuju && $namaTulis ? NamaInstansi::saran($namaTulis)->load('primaryContact:id,full_name') : collect();
        $hasilCari = $setuju && $this->pilihan === 'terdaftar' && ! ctype_digit($this->instansi)
            ? NamaInstansi::cari($this->cariInstansi)->load('primaryContact:id,full_name') : collect();
        $menginap = Reservation::whereIn('institution_id', $saran->pluck('id')->merge($hasilCari->pluck('id'))->unique()->all())
            ->whereIn('status', ['checked_in', 'checked_out'])->selectRaw('institution_id, COUNT(*) AS n')->groupBy('institution_id')->pluck('n', 'institution_id');
        $kamarBebas = collect();
        $sisa = [];
        if ($setuju) {
            // Hanya kamar aktif yang bebas di rentang pengajuan (tanpa reservasi beririsan, bukan Perbaikan). Kamar yang sudah
            // dipilih tetap tampil walau sejak itu terpakai, ditandai, agar Manager bisa melepasnya; simpan akan menolaknya.
            $kamarBebas = $ketersediaan->kamarBebas($r->request->check_in_date, $r->request->check_out_date, null, $r->id)->map(function ($k) {
                $k->terpakai = false;

                return $k;
            });
            $hilang = array_diff(array_map('intval', $this->kamar), $kamarBebas->pluck('id')->all());
            $kamarBebas = $kamarBebas->concat(Room::whereIn('id', $hilang)->get(['id', 'room_number', 'room_type_id', 'status', 'floor', 'bed_type'])
                ->map(fn ($k) => (object) ($k->only('id', 'room_number', 'room_type_id', 'floor', 'bed_type') + ['status' => $k->status->value, 'terpakai' => true])));
            $sisa = $ketersediaan->sisa($r->request->check_in_date, $r->request->check_out_date, null, $r->id);
        }
        $urutKasur = array_flip(array_keys(Room::BED_TYPES));
        $kasurAda = $kamarBebas->pluck('bed_type')->unique()->sortBy(fn ($b) => $urutKasur[$b] ?? 99)->values();
        $kamarPerTipe = $kamarBebas->sortBy(fn ($k) => [$urutKasur[$k->bed_type] ?? 99, strlen($k->room_number), $k->room_number])->groupBy('room_type_id');
        $infoKamar = $kamarBebas->keyBy('id');
        $tipe = RoomType::orderBy('base_price')->get()->keyBy('id');
        $malam = $r ? $r->request->malam() : 0;
        // Rincian tiap kamar: harga normal untuk seluruh malam, potongan, dan biaya akhirnya. Harga dibaca ulang dari
        // harga dasar tipe, jadi isian harga yang diubah dari peramban tidak berpengaruh.
        $dasar = Room::with('roomType')->whereIn('id', array_map('intval', array_keys($this->harga)))->get()->mapWithKeys(fn ($k) => [$k->id => (float) $k->roomType->base_price]);
        foreach ($this->harga as $id => $h) {
            $this->harga[$id]['harga'] = (string) (int) ($dasar[(int) $id] ?? 0);
        }
        $hitung = collect($this->harga)->map(function ($h) use ($malam) {
            $kotor = (float) ($h['harga'] ?? 0) * $malam;
            $nilai = (float) ($h['nilai'] ?? 0);
            $potong = match ($h['jenis'] ?? '') {
                'percent' => round($kotor * min(100, $nilai) / 100),
                'amount' => min($kotor, $nilai * $malam), // nominal per kamar per malam
                'free' => $kotor,
                default => 0,
            };

            return ['kotor' => $kotor, 'potong' => $potong, 'bersih' => $kotor - $potong];
        });
        $total = $hitung->sum('bersih');
        $nSendiri = collect($this->harga)->filter(fn ($h) => $h['sendiri'] ?? false)->count();

        return view('livewire.staf.reservasi.pengajuan-instansi', [
            'daftar' => $daftar,
            'jumlahMenunggu' => Reservation::where('status', 'waiting_approval')->whereHas('request')->count(),
            'r' => $r,
            'kamarPerTipe' => $kamarPerTipe,
            'kasurAda' => $kasurAda,
            'infoKamar' => $infoKamar,
            'nSendiri' => $nSendiri,
            'sisa' => $sisa,
            'tipe' => $tipe,
            'malam' => $malam,
            'total' => $total,
            'hitung' => $hitung,
            'namaTulis' => $namaTulis,
            'saran' => $saran,
            'hasilCari' => $hasilCari,
            'menginap' => $menginap,
            'instansiTerpilih' => $setuju && ctype_digit($this->instansi) ? Institution::with('primaryContact:id,full_name,is_active')->find((int) $this->instansi) : null,
            'narahubung' => $setuju ? $this->keadaanNarahubung() : null,
            'bolehPutuskan' => Gate::allows('approve-reservations'),
            // Pola rombongan instansi yang sudah pernah datang, untuk bahan keputusan Manager
            'pola' => $r && $this->mode === 'setuju' && ctype_digit($this->instansi) && ($inst = Institution::find((int) $this->instansi))
                ? ['pola' => app(ProfilPelanggan::class)->untukInstansi($inst), 'nama' => $inst->name] : null,
        ])->layout('components.layouts.staf', ['title' => 'Pengajuan Instansi']);
    }
}
