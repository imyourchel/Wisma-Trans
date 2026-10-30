<?php

namespace App\Livewire\Staf\Pelanggan;

use App\Models\Customer;
use App\Models\Institution;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\Keuangan\PiutangService;
use App\Services\Laporan\PeringkatPelanggan;
use App\Services\Laporan\Periode;
use App\Services\Pelanggan\GabungInstansi;
use App\Services\Pelanggan\ProfilPelanggan;
use App\Support\NamaInstansi;
use App\Support\PersonName;
use App\Support\Phone;
use Carbon\CarbonImmutable;
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
 * Instansi (Manager): daftar dengan jumlah reservasi, malam kamar, dan pendapatan dalam periode (PeringkatPelanggan,
 * bawaan 12 bulan terakhir) serta piutang berjalan (PiutangService); tambah/ubah data dan skema penagihan;
 * narahubung (jadikan utama, nonaktifkan, aktifkan lagi, tambah atau tautkan lewat HP); gabungkan instansi duplikat.
 */
class Instansi extends Component
{
    public const PERIODE = ['12-bulan' => '12 bulan terakhir'] + Periode::PRESET;

    #[Url(except: '12-bulan')]
    public string $periode = '12-bulan';

    #[Url(except: '')]
    public string $dari = '';

    #[Url(except: '')]
    public string $sampai = '';

    #[Url(except: '')]
    public string $cari = '';

    #[Locked]
    public ?int $instansiId = null;

    /** '' | tambah | ubah */
    #[Locked]
    public string $formulir = '';

    public string $kode = '';

    public string $nama = '';

    public string $alamat = '';

    public string $telepon = '';

    public string $email = '';

    public string $skema = 'due_after_checkout';

    public string $tanggalTagih = '';

    public string $jatuhTempo = '';

    public bool $tambahNarahubung = false;

    public string $hpNarahubung = '';

    public string $namaNarahubung = '';

    public string $emailNarahubung = '';

    /** Panel "Gabungkan ke…" terbuka */
    #[Locked]
    public bool $gabung = false;

    public string $cariGabung = '';

    #[Locked]
    public ?int $tujuanGabung = null;

    public function mount(): void
    {
        Gate::authorize('manage-institutions');
    }

    /* ---------- Detail ---------- */

    public function open(int $id): void
    {
        $this->instansiId = Institution::findOrFail($id)->id;
        $this->tutupFormulir();
        $this->tutupNarahubung();
        $this->tutupGabung();
    }

    public function close(): void
    {
        $this->instansiId = null;
        $this->tutupFormulir();
        $this->tutupNarahubung();
        $this->tutupGabung();
    }

    /* ---------- Tambah / ubah ---------- */

    public function mulaiTambah(): void
    {
        Gate::authorize('manage-institutions');
        $this->close();
        $this->formulir = 'tambah';
    }

    public function mulaiUbah(): void
    {
        Gate::authorize('manage-institutions');
        $i = Institution::findOrFail($this->instansiId);
        $this->tutupNarahubung();
        $this->kode = (string) $i->code;
        $this->nama = $i->name;
        $this->alamat = (string) $i->address;
        $this->telepon = (string) $i->phone;
        $this->email = (string) $i->email;
        $this->skema = $i->default_billing_scheme;
        $this->tanggalTagih = (string) ($i->billing_day ?? '');
        $this->jatuhTempo = (string) ($i->due_days ?? '');
        $this->resetValidation();
        $this->formulir = 'ubah';
    }

    public function tutupFormulir(): void
    {
        $this->formulir = '';
        $this->reset('kode', 'nama', 'alamat', 'telepon', 'email', 'skema', 'tanggalTagih', 'jatuhTempo');
        $this->resetValidation();
    }

    public function simpan(): void
    {
        Gate::authorize('manage-institutions');
        $ubah = $this->formulir === 'ubah' ? Institution::findOrFail($this->instansiId) : null;
        $this->kode = Str::upper(trim($this->kode));
        $this->nama = trim(preg_replace('/\s+/', ' ', $this->nama));
        $this->alamat = trim($this->alamat);
        $this->telepon = trim($this->telepon);
        $this->email = Str::lower(trim($this->email));
        $this->tanggalTagih = trim($this->tanggalTagih);
        $this->jatuhTempo = trim($this->jatuhTempo);
        $this->validate([
            'kode' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('institutions', 'code')->ignore($ubah?->id)],
            'nama' => ['required', 'string', 'min:3', 'max:150'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'telepon' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['nullable', 'max:150', new EmailWajar],
            'skema' => ['required', Rule::in(array_keys(Institution::SKEMA))],
            'tanggalTagih' => [Rule::requiredIf($this->skema === 'monthly_fixed_date'), 'nullable', 'integer', 'between:1,31'],
            'jatuhTempo' => [Rule::requiredIf($this->skema === 'due_after_checkout'), 'nullable', 'integer', 'between:1,365'],
        ], [
            'kode.unique' => 'Kode ini sudah dipakai instansi lain.',
            'kode.regex' => 'Gunakan huruf besar, angka, atau tanda hubung.',
            'kode.max' => 'Maksimal 20 karakter.',
            'nama.required' => 'Isi nama instansi.',
            'nama.min' => 'Minimal 3 huruf.',
            'telepon.regex' => 'Isi angka, misalnya 0474 321123.',
            'skema.*' => 'Pilih skema penagihan.',
            'tanggalTagih.required' => 'Isi tanggal tagih untuk tagihan bulanan.',
            'tanggalTagih.*' => 'Tanggal tagih 1–31.',
            'jatuhTempo.required' => 'Isi jumlah hari jatuh tempo.',
            'jatuhTempo.*' => 'Jatuh tempo 1–365 hari.',
        ]);
        if ($ada = NamaInstansi::sama($this->nama, $ubah?->id)) {
            $this->addError('nama', "Sudah terdaftar sebagai {$ada->name}.");

            return;
        }

        // Mengubah skema hanya berlaku untuk reservasi dan tagihan berikutnya; data yang sudah ada tidak disentuh
        $data = [
            'code' => $this->kode ?: null,
            'name' => $this->nama,
            'address' => $this->alamat ?: null,
            'phone' => $this->telepon ?: null,
            'email' => $this->email ?: null,
            'default_billing_scheme' => $this->skema,
            'billing_day' => $this->skema === 'monthly_fixed_date' ? (int) $this->tanggalTagih : null,
            'due_days' => in_array($this->skema, ['due_after_checkout', 'monthly_fixed_date'], true) && $this->jatuhTempo !== '' ? (int) $this->jatuhTempo : null,
        ];
        if ($ubah) {
            $ubah->update($data);
            $this->tutupFormulir();
            $this->dispatch('toast', teks: "Data {$ubah->name} disimpan.");

            return;
        }
        $baru = Institution::create($data);
        $this->tutupFormulir();
        $this->open($baru->id);
        $this->dispatch('toast', teks: "Instansi {$baru->name} ditambahkan. Tambahkan narahubungnya.");
    }

    /* ---------- Narahubung ---------- */

    private function narahubung(int $customerId): Customer
    {
        return Customer::where('institution_id', $this->instansiId)->findOrFail($customerId);
    }

    public function jadikanUtama(int $customerId): void
    {
        Gate::authorize('manage-institutions');
        $c = $this->narahubung($customerId);
        if (! $c->is_active) {
            $this->addError('narahubung', 'Aktifkan lagi narahubung ini sebelum dijadikan utama.');

            return;
        }
        Institution::whereKey($this->instansiId)->update(['primary_contact_id' => $c->id]);
        $this->dispatch('toast', teks: "{$c->full_name} menjadi narahubung utama.");
    }

    public function nonaktifkan(int $customerId): void
    {
        Gate::authorize('manage-institutions');
        $c = $this->narahubung($customerId);
        if (Institution::whereKey($this->instansiId)->value('primary_contact_id') === $c->id) {
            $this->addError('narahubung', 'Narahubung utama tidak bisa dinonaktifkan. Jadikan narahubung lain sebagai utama dulu.');

            return;
        }
        // Riwayat (reservasi, nota) tetap utuh; hanya ditandai tidak menjabat
        $c->update(['is_active' => false]);
        $this->dispatch('toast', teks: "{$c->full_name} dinonaktifkan.");
    }

    public function aktifkan(int $customerId): void
    {
        Gate::authorize('manage-institutions');
        $c = $this->narahubung($customerId);
        $c->update(['is_active' => true]);
        $this->dispatch('toast', teks: "{$c->full_name} aktif lagi.");
    }

    public function mulaiNarahubung(): void
    {
        Gate::authorize('manage-institutions');
        $this->tutupFormulir();
        $this->tutupNarahubung();
        $this->tambahNarahubung = true;
    }

    public function tutupNarahubung(): void
    {
        $this->tambahNarahubung = false;
        $this->reset('hpNarahubung', 'namaNarahubung', 'emailNarahubung');
        $this->resetValidation();
    }

    /** Pelanggan dengan HP yang diisi (termasuk yang sudah dihapus), untuk ditautkan alih-alih dibuat baru. */
    private function calon(): ?Customer
    {
        $hp = Phone::normalizeInput($this->hpNarahubung);

        return Phone::isValidMobile($hp) ? Customer::withTrashed()->with('institution:id,name')->where('phone', $hp)->first() : null;
    }

    public function simpanNarahubung(): void
    {
        Gate::authorize('manage-institutions');
        $i = Institution::findOrFail($this->instansiId);
        $this->hpNarahubung = Phone::normalizeInput($this->hpNarahubung);
        $this->validate(['hpNarahubung' => ['required', new NomorHp]], ['hpNarahubung.required' => 'Isi nomor HP narahubung.']);
        $calon = $this->calon();

        if ($calon) {
            if ($calon->trashed()) {
                $this->addError('hpNarahubung', "Nomor ini dipakai pelanggan yang sudah dihapus ({$calon->full_name})");

                return;
            }
            if ($calon->institution_id === $i->id) {
                $this->addError('hpNarahubung', "{$calon->full_name} sudah menjadi narahubung instansi ini.");

                return;
            }
            if ($calon->institution_id !== null) {
                $this->addError('hpNarahubung', "Sudah menjadi narahubung {$calon->institution?->name}.");

                return;
            }
            $c = $calon;
        } else {
            $this->namaNarahubung = PersonName::normalize($this->namaNarahubung);
            $this->emailNarahubung = Str::lower(trim($this->emailNarahubung));
            $this->validate([
                'namaNarahubung' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
                'emailNarahubung' => ['nullable', 'max:150', new EmailWajar],
            ], ['namaNarahubung.required' => 'Isi nama narahubung.', 'namaNarahubung.min' => 'Minimal 3 huruf.', 'namaNarahubung.regex' => PersonName::MESSAGE]);
            $c = new Customer(['full_name' => $this->namaNarahubung, 'phone' => $this->hpNarahubung, 'email' => $this->emailNarahubung ?: null]);
        }

        DB::transaction(function () use ($c, $i) {
            $c->fill(['institution_id' => $i->id, 'is_active' => true])->save();
            // Instansi tanpa narahubung utama: narahubung pertama langsung menjadi utama
            if (! $i->primary_contact_id) {
                $i->update(['primary_contact_id' => $c->id]);
            }
        });
        ProfilPelanggan::lupakan($c->id);
        $this->tutupNarahubung();
        $this->dispatch('toast', teks: "{$c->full_name} ditambahkan sebagai narahubung {$i->name}.");
    }

    /* ---------- Gabungkan instansi duplikat ---------- */

    public function mulaiGabung(): void
    {
        Gate::authorize('manage-institutions');
        $this->tutupFormulir();
        $this->tutupNarahubung();
        $this->gabung = true;
    }

    public function tutupGabung(): void
    {
        $this->gabung = false;
        $this->cariGabung = '';
        $this->tujuanGabung = null;
        $this->resetValidation('tujuan');
    }

    public function pilihTujuan(?int $id): void
    {
        Gate::authorize('manage-institutions');
        $this->resetValidation('tujuan');
        if ($id !== null && $id === $this->instansiId) {
            $this->addError('tujuan', 'Instansi tidak bisa digabung ke dirinya sendiri.');

            return;
        }
        $this->tujuanGabung = $id === null ? null : Institution::findOrFail($id)->id;
    }

    public function gabungkan(GabungInstansi $layanan): void
    {
        Gate::authorize('manage-institutions');
        $sumber = Institution::findOrFail($this->instansiId);
        $tujuan = $this->tujuanGabung ? Institution::find($this->tujuanGabung) : null;
        if (! $tujuan) {
            $this->addError('tujuan', 'Pilih instansi tujuan.');

            return;
        }
        $layanan->gabung($sumber, $tujuan, Auth::user());
        $this->tutupGabung();
        $this->open($tujuan->id);
        $this->dispatch('toast', teks: "{$sumber->name} digabung ke {$tujuan->name}.");
    }

    /* ---------- Tampilan ---------- */

    public function periodeTerpilih(): Periode
    {
        if ($this->periode === 'rentang' || isset(Periode::PRESET[$this->periode])) {
            return Periode::buat($this->periode, $this->dari, $this->sampai);
        }
        $hari = CarbonImmutable::today();

        return new Periode($hari->subYear()->addDay(), $hari, 'rentang');
    }

    public function labelPeriode(Periode $p): string
    {
        return match (true) {
            ! isset(self::PERIODE[$this->periode]) || $this->periode === '12-bulan' => '12 bulan terakhir',
            $this->periode === 'rentang' => $p->label(),
            default => Str::lower(self::PERIODE[$this->periode]),
        };
    }

    public function render(PeringkatPelanggan $peringkat, PiutangService $piutang): View
    {
        $p = $this->periodeTerpilih();
        $nilai = $peringkat->instansi($p, PHP_INT_MAX)->keyBy('id');
        $sisa = $piutang->sisaPerInstansi();
        $kata = trim($this->cari);

        $daftar = Institution::with('primaryContact:id,full_name,phone')
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$kata}%")->orWhere('code', 'like', "%{$kata}%")))
            ->orderBy('name')->get()
            ->map(fn (Institution $i) => [
                'instansi' => $i,
                'reservasi' => (int) ($nilai[$i->id]['kunjungan'] ?? 0),
                'malam' => (int) ($nilai[$i->id]['malam'] ?? 0),
                'pendapatan' => (float) ($nilai[$i->id]['pendapatan'] ?? 0),
                'piutang' => (float) ($sisa[$i->id] ?? 0),
            ]);

        $i = $this->instansiId ? Institution::find($this->instansiId) : null;
        $gabung = $i && $this->gabung;

        return view('livewire.staf.pelanggan.instansi', [
            'daftar' => $daftar,
            'p' => $p,
            'labelPeriode' => $this->labelPeriode($p),
            'i' => $i,
            'pola' => $i ? app(ProfilPelanggan::class)->untukInstansi($i) : null,
            // Jumlah pengajuan instansi yang diajukan tiap narahubung
            'narahubung' => $i ? Customer::where('institution_id', $i->id)->withCount(['reservations as pengajuan' => fn ($q) => $q->whereHas('request')])
                ->orderByDesc('is_active')->orderBy('full_name')->get() : collect(),
            'saranGabung' => $gabung ? NamaInstansi::saran($i->name, 3, $i->id) : collect(),
            'hasilGabung' => $gabung && ! $this->tujuanGabung ? NamaInstansi::cari($this->cariGabung, 8, $i->id) : collect(),
            'tujuan' => $gabung && $this->tujuanGabung ? Institution::find($this->tujuanGabung) : null,
            'ringkasanGabung' => $gabung ? app(GabungInstansi::class)->ringkasan($i) : null,
            'calon' => $this->tambahNarahubung ? $this->calon() : null,
        ])->layout('components.layouts.staf', ['title' => 'Instansi']);
    }
}
