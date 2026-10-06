<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Enums\RoomStatus;
use App\Http\Requests\RoomRequest;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UC-MK02 Mengelola Kamar (Admin). Status kamar tidak diubah di sini, hanya di Papan Status Kamar.
 * Tidak ada fitur hapus kamar, supaya riwayat reservasi tetap utuh.
 */
class KamarController extends MasterController
{
    public function index(Request $request): View
    {
        $saring = [
            'cari' => trim((string) $request->query('cari', '')),
            'tipe' => (string) $request->query('tipe', ''),
            'lantai' => (string) $request->query('lantai', ''),
            'status' => (string) $request->query('status', ''),
            'aktif' => (string) $request->query('aktif', ''),
        ];
        $cari = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $saring['cari']);
        $status = RoomStatus::tryFrom($saring['status']);

        $kamar = Room::with('roomType')
            ->when($cari !== '', fn ($q) => $q->where('room_number', 'like', "%{$cari}%"))
            ->when(ctype_digit($saring['tipe']), fn ($q) => $q->where('room_type_id', (int) $saring['tipe']))
            ->when(ctype_digit($saring['lantai']), fn ($q) => $q->where('floor', (int) $saring['lantai']))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when(in_array($saring['aktif'], ['0', '1'], true), fn ($q) => $q->where('is_active', $saring['aktif'] === '1'))
            // Urut nomor kamar: 99 sebelum 101
            ->orderByRaw('CHAR_LENGTH(room_number)')->orderBy('room_number')
            ->paginate(15)->withQueryString();

        return view('staf.admin.kamar', [
            'kamar' => $kamar,
            'tipe' => RoomType::orderBy('base_price')->get(),
            'lantai' => Room::query()->distinct()->orderBy('floor')->pluck('floor'),
            'saring' => $saring,
        ]);
    }

    public function store(RoomRequest $request): JsonResponse|RedirectResponse
    {
        // Kamar baru selalu berstatus available (nilai bawaan kolom status)
        Room::create($request->validated() + ['is_active' => true]);

        return $this->selesai($request, 'Kamar berhasil ditambahkan.');
    }

    public function update(RoomRequest $request, Room $kamar): JsonResponse|RedirectResponse
    {
        $kamar->update($request->validated());

        return $this->selesai($request, 'Kamar berhasil diubah.');
    }

    /** Nonaktifkan / aktifkan. Mengaktifkan lagi tidak mengubah status kamar. */
    public function toggle(Room $kamar): RedirectResponse
    {
        if ($kamar->is_active && $kamar->status->isOccupied()) {
            return back()->with('error', 'Kamar sedang terisi tamu, tidak bisa dinonaktifkan.');
        }
        // Kamar yang sudah ditetapkan untuk reservasi mendatang tidak boleh hilang dari daftar
        if ($kamar->is_active && ($pesan = $kamar->pemesananBerjalan(['booked', 'checked_in'])->first())) {
            return back()->with('error', "Kamar {$kamar->room_number} sudah dipesan untuk {$pesan->keterangan()}. Pindahkan reservasinya dulu.");
        }

        return $this->balikAktif($kamar, 'Kamar '.$kamar->room_number);
    }
}
