<?php

namespace App\Livewire\Staf\Reservasi;

use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\KetersediaanKamar;
use App\Services\ReservasiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Check-in: reservasi terkonfirmasi yang tiba hari ini (dan yang terlambat datang tetapi belum lewat tanggal keluar).
 * FO menetapkan nomor kamar yang berstatus Siap dan mencatat KTP yang ditahan. Manager hanya melihat.
 */
class CheckIn extends Component
{
    #[Url(except: '')]
    public string $cari = '';

    #[Locked]
    public ?int $selectedId = null;

    /** id baris reservasi => id kamar */
    public array $penempatan = [];

    public bool $ktpDitahan = true;

    public string $namaKtp = '';

    public function mount(): void
    {
        Gate::authorize('view-reservations');
    }

    public function open(int $id): void
    {
        Gate::authorize('manage-reservations');
        $r = Reservation::with('rooms', 'customer')->where('status', 'confirmed')->findOrFail($id);
        $this->resetValidation();
        $this->selectedId = $r->id;
        $this->penempatan = $r->rooms->where('status', 'booked')->mapWithKeys(fn ($b) => [$b->id => (string) ($b->room_id ?? '')])->all();
        $this->ktpDitahan = true;
        $this->namaKtp = (string) $r->customer?->full_name;
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->resetValidation();
    }

    public function simpan(ReservasiService $layanan): void
    {
        Gate::authorize('manage-reservations');
        $r = Reservation::findOrFail($this->selectedId);
        $layanan->checkIn($r, array_map('intval', array_filter($this->penempatan)), $this->ktpDitahan, $this->namaKtp, Auth::user());
        $this->close();
        $this->dispatch('toast', teks: "Check-in {$r->reservation_code} berhasil.");
    }

    public function render(KetersediaanKamar $ketersediaan): View
    {
        $kata = trim($this->cari);
        $daftar = Reservation::query()
            ->with(['customer', 'institution', 'rooms.roomType', 'rooms.room', 'stayInvoice'])
            ->where('status', 'confirmed')
            ->whereHas('rooms', fn ($q) => $q->where('status', 'booked')
                ->where('check_in_date', '<=', today()->toDateString())->where('check_out_date', '>', today()->toDateString()))
            ->when($kata !== '', fn ($q) => $q->where(fn ($q) => $q->where('reservation_code', 'like', "%{$kata}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$kata}%"))))
            ->orderBy('id')
            ->get();

        $r = $this->selectedId ? Reservation::with('rooms.roomType', 'rooms.room', 'customer')->find($this->selectedId) : null;
        $pilihanKamar = [];
        if ($r) {
            // Kamar siap yang tidak dipakai reservasi lain, per tipe; kamar yang sudah ditetapkan tetap ditawarkan
            foreach ($r->rooms->where('status', 'booked') as $b) {
                $pilihanKamar[$b->id] = $ketersediaan->kamarBebas($b->check_in_date, $b->check_out_date, [$b->room_type_id], $r->id)
                    ->mapWithKeys(fn ($k) => [$k->id => ['label' => 'Kamar '.$k->room_number.($k->status === 'available' ? '' : ' (belum siap)'),
                        'warna' => $k->status === 'available' ? '#5C9E67' : '#D4514A']])
                    ->all();
            }
        }

        return view('livewire.staf.reservasi.check-in', [
            'daftar' => $daftar,
            'r' => $r,
            'pilihanKamar' => $pilihanKamar,
            'bolehKelola' => Gate::allows('manage-reservations'),
            'belumWaktunya' => now()->format('H:i') < substr((string) Setting::get('check_in_time', '14:00'), 0, 5),
            'jamCheckIn' => ReservasiService::jamCheckIn(),
            'namaTipe' => RoomType::pluck('name', 'id'),
        ])->layout('components.layouts.staf', ['title' => 'Check-in']);
    }
}
