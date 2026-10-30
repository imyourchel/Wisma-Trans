<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Http\Requests\RoomTypeRequest;
use App\Models\Facility;
use App\Models\RoomType;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * UC-MK01 Mengelola Tipe Kamar (Admin). Validasi di RoomTypeRequest.
 * Harga dasar baru hanya berlaku untuk reservasi berikutnya; reservasi lama menyimpan harganya sendiri.
 */
class TipeKamarController extends MasterController
{
    /** Galeri tipe kamar tanpa batas jumlah dan ukuran foto. */
    protected ?int $maksFoto = null;

    protected ?int $maksUkuranFotoKb = null;

    private const FOLDER_FOTO = 'room-types';

    /** Foto standar di storage/app/public/kamar dipakai semua tipe kamar dan foto kartu jenis kasur. */
    protected function fotoBersama(string $path): bool
    {
        return str_starts_with($path, RoomType::FOLDER_FOTO_STANDAR.'/');
    }

    public function index(): View
    {
        return view('staf.admin.tipe-kamar', [
            'tipe' => RoomType::with('facilities')
                ->withCount(['rooms', 'rooms as kamar_aktif_count' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('base_price')->get(),
            'fasilitas' => Facility::where('category', 'room')->orderBy('name')->get(),
        ]);
    }

    public function store(RoomTypeRequest $request): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $tipe = RoomType::create($request->dataTipe() + ['photos' => $this->susunFoto($request, [], self::FOLDER_FOTO)]);
            $tipe->facilities()->sync($request->validated('fasilitas', []));
        });

        return $this->selesai($request, 'Tipe kamar berhasil ditambahkan.');
    }

    public function update(RoomTypeRequest $request, RoomType $tipeKamar): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request, $tipeKamar) {
            $tipeKamar->update($request->dataTipe() + ['photos' => $this->susunFoto($request, $tipeKamar->photos ?? [], self::FOLDER_FOTO)]);
            // Formulir hanya memuat fasilitas kamar; fasilitas umum (misalnya WiFi) yang sudah tercatat tetap dipertahankan
            $umum = $tipeKamar->facilities()->where('category', 'public')->pluck('facilities.id')->all();
            $tipeKamar->facilities()->sync([...$request->validated('fasilitas', []), ...$umum]);
        });

        return $this->selesai($request, 'Tipe kamar berhasil diubah.');
    }

    public function destroy(RoomType $tipeKamar): RedirectResponse
    {
        $aktif = $tipeKamar->rooms()->where('is_active', true)->count();
        if ($aktif > 0) {
            return back()->with('error', "Tipe kamar ini masih dipakai oleh {$aktif} kamar. Pindahkan atau nonaktifkan kamarnya dulu.");
        }

        // Kamar nonaktif tetap terhubung ke tipenya (riwayat reservasi), jadi harus dipindahkan dulu
        $nonaktif = $tipeKamar->rooms()->count();
        if ($nonaktif > 0) {
            return back()->with('error', "Tipe kamar ini masih tercatat pada {$nonaktif} kamar nonaktif. Pindahkan kamarnya ke tipe lain dulu.");
        }

        $foto = $tipeKamar->photos ?? [];
        try {
            DB::transaction(function () use ($tipeKamar) {
                $tipeKamar->facilities()->detach();
                $tipeKamar->delete();
            });
        } catch (QueryException) {
            return back()->with('error', "Tipe kamar {$tipeKamar->name} sudah dipakai di data reservasi, jadi tidak bisa dihapus.");
        }

        // Berkas dihapus setelah data terhapus, supaya foto tidak hilang bila penghapusan gagal
        Storage::disk('public')->delete(array_filter($foto, fn ($p) => str_contains($p, '/') && ! $this->fotoBersama($p)));

        return back()->with('status', "Tipe kamar {$tipeKamar->name} berhasil dihapus.");
    }
}
