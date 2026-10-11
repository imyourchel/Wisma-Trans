<?php

namespace App\Livewire\Staf\Pelanggan;

use App\Models\Customer;
use App\Models\HallBooking;
use App\Models\Institution;
use App\Models\Reservation;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\ProfilPelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use App\Support\SegmenPelanggan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar Pelanggan (Manager, FO): jumlah kunjungan, malam, dan kunjungan terakhir dihitung dengan subquery
 * (bukan ProfilPelanggan per baris); total belanja hanya untuk Manager. Detail (?lihat=) memuat profil, rekomendasi,
 * riwayat kunjungan, dan layanan; kontak boleh diubah FO dan Manager tanpa menyentuh akun pengguna.
 */
class DaftarPelanggan extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $cari = '';

    /** '' | b2b | b2c */
    #[Url(except: '')]
    public string $segmen = '';

    #[Url(except: '')]
    public string $instansi = '';

    #[Url(except: false)]
    public bool $berulang = false;

    /** '' | aktif | nonaktif */
    #[Url(except: '')]
    public string $status = '';

    #[Url(except: null)]
    public ?int $lihat = null;

    public bool $ubah = false;

    public string $nama = '';

    public string $hp = '';

    public string $email = '';

    public string $alamat = '';

    public function mount(): void
    {
        Gate::authorize('view-customers');
    }

    public function updated(string $nama): void
    {
        if (in_array($nama, ['cari', 'segmen', 'instansi', 'berulang', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function resetSaringan(): void
    {
        $this->reset('cari', 'segmen', 'instansi', 'berulang', 'status');
        $this->resetPage();
    }

    public function open(int $id): void
    {
        $this->lihat = Customer::findOrFail($id)->id;
        $this->tutupUbah();
    }

    public function close(): void
    {
        $this->lihat = null;
        $this->tutupUbah();
    }

    public function mulaiUbah(): void
    {
        Gate::authorize('view-customers');
        $c = Customer::findOrFail($this->lihat);
        $this->nama = $c->full_name;
        $this->hp = $c->phone;
        $this->email = (string) $c->email;
        $this->alamat = (string) $c->address;
        $this->resetValidation();
        $this->ubah = true;
    }

    public function tutupUbah(): void
    {
        $this->ubah = false;
        $this->reset('nama', 'hp', 'email', 'alamat');
        $this->resetValidation();
    }

    public function simpanUbah(): void
    {
        Gate::authorize('view-customers');
        $c = Customer::findOrFail($this->lihat);
        $this->nama = PersonName::normalize($this->nama);
        $this->hp = Phone::normalizeInput($this->hp);
        $this->email = Str::lower(trim($this->email));
        $this->alamat = trim($this->alamat);
        $this->validate([
            'nama' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'hp' => ['required', new NomorHp, function ($a, $v, $gagal) use ($c) {
                // Indeks unik nomor HP juga berlaku untuk pelanggan yang sudah dihapus
                $pemilik = Customer::withTrashed()->where('phone', $v)->whereKeyNot($c->id)->first();
                if ($pemilik) {
                    $gagal($pemilik->trashed()
                        ? "Nomor ini dipakai pelanggan yang sudah dihapus ({$pemilik->full_name})"
                        : "Nomor ini sudah dipakai pelanggan {$pemilik->full_name}");
                }
            }],
            'email' => ['nullable', 'max:150', new EmailWajar],
            'alamat' => ['nullable', 'string', 'max:500'],
        ], [
            'nama.required' => 'Isi nama pelanggan.',
            'nama.min' => 'Minimal 3 huruf.',
            'nama.regex' => PersonName::MESSAGE,
            'hp.required' => 'Isi nomor HP.',
            'alamat.max' => 'Alamat maksimal 500 karakter.',
        ]);
        $c->update(['full_name' => $this->nama, 'phone' => $this->hp, 'email' => $this->email ?: null, 'address' => $this->alamat ?: null]);
        $this->tutupUbah();
        $this->dispatch('toast', teks: "Kontak {$c->full_name} disimpan.");
    }

    private function manager(): bool
    {
        return (bool) Auth::user()?->hasRole('manager');
    }

    public function render(ProfilPelanggan $profil): View
    {
        $manager = $this->manager();
        $kata = trim($this->cari);
        $angka = preg_replace('/\D/', '', $kata);
        $b2b = SegmenPelanggan::ekspresiPelanggan('customers');

        $daftar = Customer::query()->with('institution:id,name')
            ->select('customers.*')
            ->selectRaw(ProfilPelanggan::sqlKunjungan().' AS kunjungan')
            ->selectRaw(ProfilPelanggan::sqlMalam().' AS malam')
            ->selectRaw(ProfilPelanggan::sqlTerakhir().' AS terakhir')
            ->selectRaw($b2b.' AS b2b')
            ->when($manager, fn ($q) => $q->selectRaw(ProfilPelanggan::sqlBelanja().' AS belanja'))
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('full_name', 'like', "%{$kata}%")->orWhere('email', 'like', "%{$kata}%")
                ->when(strlen($angka) >= 4, fn ($q) => $q->orWhere('phone', 'like', "%{$angka}%"))))
            ->when($this->segmen === 'b2b', fn ($q) => $q->whereRaw("({$b2b}) = 1"))
            ->when($this->segmen === 'b2c', fn ($q) => $q->whereRaw("({$b2b}) = 0"))
            ->when(ctype_digit($this->instansi), fn ($q) => $q->where(fn ($q) => $q->where('institution_id', (int) $this->instansi)
                ->orWhereHas('reservations', fn ($r) => $r->where('institution_id', (int) $this->instansi))))
            ->when($this->berulang, fn ($q) => $q->whereRaw(ProfilPelanggan::sqlKunjungan().' >= ?', [ProfilPelanggan::MIN_KUNJUNGAN]))
            ->when($this->status === 'aktif', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'nonaktif', fn ($q) => $q->where('is_active', false))
            ->orderByRaw('terakhir IS NULL')->orderByDesc('terakhir')->orderByDesc('customers.id')
            ->paginate(25);

        $c = $this->lihat ? Customer::with(['institution', 'user:id'])->find($this->lihat) : null;

        return view('livewire.staf.pelanggan.daftar-pelanggan', [
            'daftar' => $daftar,
            'manager' => $manager,
            'daftarInstansi' => Institution::orderBy('name')->pluck('name', 'id'),
            'c' => $c,
            'profil' => $c ? $profil->untuk($c) : null,
            'riwayat' => $c ? Reservation::with(['rooms.room', 'institution:id,name'])->where('customer_id', $c->id)
                ->selectRaw("reservations.*, (SELECT COALESCE(SUM(iv.total_amount), 0) FROM invoices iv WHERE iv.reservation_id = reservations.id AND iv.status <> 'cancelled') AS total_nota")
                ->orderByDesc('id')->limit(20)->get() : collect(),
            'aula' => $c ? HallBooking::with('hall:id,name')->where('customer_id', $c->id)->latest('start_at')->limit(10)->get() : collect(),
        ])->layout('components.layouts.staf', ['title' => 'Daftar Pelanggan']);
    }
}
