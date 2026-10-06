<?php

namespace App\Livewire\Staf\Reservasi;

use App\Models\Institution;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\KetersediaanKamar;
use App\Services\NotifikasiReservasi;
use App\Services\ReservasiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengajuan reservasi instansi. Front Office dan Manager melihat; hanya Manager yang menyetujui
 * (menetapkan instansi, kamar, harga atau potongan, dan skema penagihan) atau menolak dengan alasan.
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

    public string $namaBaru = '';

    public string $skema = 'due_after_checkout';

    public string $tanggalTagih = '';

    public string $jatuhTempo = '14';

    public string $skemaLayanan = 'satu_nota';

    /** @var list<string> id kamar terpilih */
    public array $kamar = [];

    /** id kamar => ['harga' => harga dasar tipe (tidak bisa diubah), 'jenis' => ''|percent|amount|free, 'nilai' => ''] */
    public array $harga = [];

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
        $this->reset('instansi', 'namaBaru', 'kamar', 'harga', 'catatan', 'alasan', 'tanggalTagih');
        $this->selectedId = $r->id;
        $this->mode = $mode === 'tolak' ? 'tolak' : 'setuju';
        $inst = $r->institution ?? $r->customer?->institution;
        $this->instansi = $inst ? (string) $inst->id : '';
        $this->skema = $inst?->default_billing_scheme ?? 'due_after_checkout';
        $this->skemaLayanan = 'satu_nota';
        // Nama instansi yang ditulis pemohon (belum terdaftar) dipakai sebagai usulan nama baru
        if (! $inst && preg_match('/^Instansi: (.+)$/m', (string) $r->special_request, $m)) {
            $this->instansi = 'baru';
            $this->namaBaru = trim($m[1]);
        }
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
        foreach ($this->kamar as $id) {
            $this->harga[$id] ??= ['harga' => (string) (int) $harga->get((int) $id)?->roomType->base_price, 'jenis' => '', 'nilai' => ''];
        }
        $this->harga = array_intersect_key($this->harga, array_flip($this->kamar));
    }

    /** Ganti jenis potongan satu kamar; nilai lama dikosongkan agar persen tidak terbaca sebagai rupiah. */
    public function jenisPotongan(string $id, string $jenis): void
    {
        Gate::authorize('approve-reservations');
        if (isset($this->harga[$id]) && in_array($jenis, ['', 'percent', 'amount', 'free'], true)) {
            $this->harga[$id]['jenis'] = $jenis;
            $this->harga[$id]['nilai'] = '';
            $this->resetValidation("harga.{$id}.nilai");
        }
    }

    public function updatedInstansi(string $nilai): void
    {
        if ($nilai !== 'baru' && $inst = Institution::find((int) $nilai)) {
            $this->skema = $inst->default_billing_scheme;
        }
    }

    public function setujui(ReservasiService $layanan, NotifikasiReservasi $notifikasi): void
    {
        Gate::authorize('approve-reservations');
        $r = Reservation::with('request', 'customer')->findOrFail($this->selectedId);
        $baru = $this->instansi === 'baru';
        $this->validate([
            'instansi' => ['required', $baru ? 'string' : Rule::exists('institutions', 'id')],
            'namaBaru' => [$baru ? 'required' : 'nullable', 'string', 'min:3', 'max:150', Rule::unique('institutions', 'name')],
            'skema' => ['required', Rule::in(array_keys(Institution::SKEMA))],
            'tanggalTagih' => [$baru && $this->skema === 'monthly_fixed_date' ? 'required' : 'nullable', 'integer', 'min:1', 'max:28'],
            'jatuhTempo' => [$baru && $this->skema === 'due_after_checkout' ? 'required' : 'nullable', 'integer', 'min:1', 'max:90'],
            'skemaLayanan' => ['required', Rule::in(array_keys(Reservation::SKEMA_LAYANAN))],
            'kamar' => ['required', 'array', 'min:1'],
            'harga.*.jenis' => ['nullable', Rule::in(['', 'percent', 'amount', 'free'])],
            'harga.*.nilai' => ['nullable', 'numeric', 'min:0'],
            'catatan' => ['nullable', 'string', 'max:200'],
        ], [
            'instansi.required' => 'Pilih instansi.',
            'namaBaru.required' => 'Isi nama instansi baru.',
            'namaBaru.unique' => 'Instansi ini sudah terdaftar; pilih dari daftar.',
            'tanggalTagih.required' => 'Isi tanggal tagih bulanan.',
            'jatuhTempo.required' => 'Isi jatuh tempo (hari).',
            'kamar.required' => 'Pilih kamar untuk pengajuan ini.',
            'harga.*.nilai.*' => 'Potongan tidak valid.',
        ]);
        foreach ($this->harga as $id => $h) {
            $jenis = $h['jenis'] ?? '';
            $nilai = (float) ($h['nilai'] ?? 0);
            $pesan = match (true) {
                in_array($jenis, ['percent', 'amount'], true) && $nilai <= 0 => 'Isi besar potongan, atau pilih Tanpa.',
                $jenis === 'percent' && $nilai > 100 => 'Potongan maksimal 100%.',
                default => null,
            };
            if ($pesan) {
                $this->addError("harga.{$id}.nilai", $pesan);

                return;
            }
        }
        // Harga selalu diambil ulang dari harga dasar tipe kamar, bukan dari isian
        $dasar = Room::with('roomType')->whereIn('id', array_map('intval', $this->kamar))->get()->mapWithKeys(fn ($k) => [$k->id => (float) $k->roomType->base_price]);

        DB::transaction(function () use ($r, $layanan, $baru, $dasar) {
            $inst = $baru
                ? Institution::create([
                    'name' => trim($this->namaBaru),
                    'phone' => $r->customer?->phone,
                    'email' => $r->customer?->email,
                    'primary_contact_id' => $r->customer_id,
                    'default_billing_scheme' => $this->skema,
                    'billing_day' => $this->skema === 'monthly_fixed_date' ? (int) $this->tanggalTagih : null,
                    'due_days' => $this->skema === 'due_after_checkout' ? (int) $this->jatuhTempo : null,
                ])
                : Institution::findOrFail((int) $this->instansi);

            $layanan->setujui($r, $inst, collect($this->kamar)->map(function ($id) use ($dasar) {
                $jenis = $this->harga[$id]['jenis'] ?? '';
                $potong = in_array($jenis, ['percent', 'amount'], true);

                // Kamar gratis dicatat dengan harga 0 pada baris itu
                return [
                    'room_id' => (int) $id,
                    'price' => $jenis === 'free' ? 0.0 : (float) ($dasar[(int) $id] ?? 0),
                    'discount_type' => $potong ? $jenis : null,
                    'discount_value' => $potong ? (float) $this->harga[$id]['nilai'] : null,
                ];
            })->all(), $this->skema, $this->skemaLayanan, trim($this->catatan) ?: null, Auth::user());
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
        $kamarBebas = collect();
        $sisa = [];
        if ($r && $this->mode === 'setuju') {
            $kamarBebas = $ketersediaan->kamarBebas($r->request->check_in_date, $r->request->check_out_date, null, $r->id);
            $sisa = $ketersediaan->sisa($r->request->check_in_date, $r->request->check_out_date, null, $r->id);
        }
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
                'amount' => min($kotor, $nilai),
                'free' => $kotor,
                default => 0,
            };

            return ['kotor' => $kotor, 'potong' => $potong, 'bersih' => $kotor - $potong];
        });
        $total = $hitung->sum('bersih');

        return view('livewire.staf.reservasi.pengajuan-instansi', [
            'daftar' => $daftar,
            'jumlahMenunggu' => Reservation::where('status', 'waiting_approval')->whereHas('request')->count(),
            'r' => $r,
            'kamarBebas' => $kamarBebas->groupBy('room_type_id'),
            'sisa' => $sisa,
            'tipe' => $tipe,
            'malam' => $malam,
            'total' => $total,
            'hitung' => $hitung,
            'daftarInstansi' => Institution::orderBy('name')->pluck('name', 'id'),
            'bolehPutuskan' => Gate::allows('approve-reservations'),
        ])->layout('components.layouts.staf', ['title' => 'Pengajuan Instansi']);
    }
}
