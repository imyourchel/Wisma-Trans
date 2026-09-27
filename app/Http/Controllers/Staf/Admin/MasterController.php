<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Pola bersama halaman data master: tabel + panel formulir.
 * Formulir dikirim lewat fetch (JSON) sehingga galat tampil tanpa memuat ulang halaman;
 * tanpa JavaScript tetap berjalan sebagai formulir biasa.
 */
abstract class MasterController extends Controller
{
    /** Batas galeri; null berarti tanpa batas (Tipe Kamar). */
    protected ?int $maksFoto = 10;

    protected ?int $maksUkuranFotoKb = 4096;

    protected function selesai(Request $request, string $pesan): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            // Halaman dimuat ulang oleh skrip, lalu pesan tampil sebagai notifikasi
            $request->session()->flash('status', $pesan);

            return response()->json(['pesan' => $pesan]);
        }

        return back()->with('status', $pesan);
    }

    /** Menonaktifkan tidak menghapus data, jadi riwayat transaksi tetap utuh. */
    protected function balikAktif(Model $model, string $nama, string $kolom = 'is_active'): RedirectResponse
    {
        $model->update([$kolom => ! $model->{$kolom}]);

        return back()->with('status', $model->{$kolom} ? "{$nama} diaktifkan." : "{$nama} dinonaktifkan.");
    }

    /** Aturan validasi galeri: urutan[] berisi "lama:<path>" atau "baru:<indeks foto_baru>". */
    protected function aturanFoto(): array
    {
        return self::aturanGaleri($this->maksFoto, $this->maksUkuranFotoKb);
    }

    /** Dipakai juga oleh FormRequest. */
    public static function aturanGaleri(?int $maksFoto, ?int $maksUkuranKb): array
    {
        $jumlah = $maksFoto ? ['max:'.$maksFoto] : [];

        return [
            'urutan' => ['nullable', 'array', ...$jumlah],
            'urutan.*' => ['string', 'max:255'],
            'foto_baru' => ['nullable', 'array', ...$jumlah],
            'foto_baru.*' => ['image', 'mimes:jpg,jpeg,png,webp', ...($maksUkuranKb ? ['max:'.$maksUkuranKb] : [])],
        ];
    }

    protected function pesanFoto(): array
    {
        return self::pesanGaleri($this->maksFoto, $this->maksUkuranFotoKb);
    }

    public static function pesanGaleri(?int $maksFoto, ?int $maksUkuranKb): array
    {
        return [
            'urutan.max' => 'Maksimal '.$maksFoto.' foto.',
            'foto_baru.max' => 'Maksimal '.$maksFoto.' foto.',
            'foto_baru.*.image' => 'Berkas harus berupa gambar.',
            'foto_baru.*.mimes' => 'Foto harus JPG, PNG, atau WEBP.',
            'foto_baru.*.max' => 'Ukuran foto maksimal '.intdiv((int) $maksUkuranKb, 1024).' MB.',
        ];
    }

    /**
     * Susun galeri sesuai urutan dari formulir. Foto pertama menjadi foto utama.
     * Foto lama yang dibuang ikut dihapus dari penyimpanan.
     *
     * @param  list<string>  $lama
     * @return list<string>
     */
    protected function susunFoto(Request $request, array $lama, string $folder): array
    {
        $baru = $request->file('foto_baru', []);
        // foto_diatur dikirim formulir panel; urutan kosong berarti semua foto dihapus
        $urutan = $request->boolean('foto_diatur') ? $request->input('urutan', []) : null;

        // Tanpa JavaScript: foto lama tetap, foto baru ditambahkan di belakang
        if (! is_array($urutan)) {
            $urutan = array_merge(
                array_map(fn ($p) => "lama:$p", $lama),
                array_map(fn ($i) => "baru:$i", array_keys($baru)),
            );
        }

        $hasil = [];
        foreach ($urutan as $item) {
            [$jenis, $nilai] = array_pad(explode(':', $item, 2), 2, '');
            if ($jenis === 'lama' && in_array($nilai, $lama, true)) {
                $hasil[] = $nilai;
            } elseif ($jenis === 'baru' && isset($baru[(int) $nilai])) {
                $hasil[] = $baru[(int) $nilai]->store($folder, 'public');
            }
        }
        $hasil = array_slice(array_values(array_unique($hasil)), 0, $this->maksFoto);  // null = tanpa batas

        foreach (array_diff($lama, $hasil) as $dibuang) {
            if (str_contains($dibuang, '/')) {
                Storage::disk('public')->delete($dibuang);
            }
        }

        return $hasil;
    }

    /**
     * Data galeri untuk formulir. Nama berkas lama dari data contoh (misalnya std-1.jpg)
     * belum punya berkas, jadi tampil sebagai kotak bernama.
     *
     * @param  list<string>|null  $foto
     * @return list<array{path: string, url: ?string}>
     */
    public static function daftarFoto(?array $foto): array
    {
        return array_map(fn (string $path) => [
            'path' => $path,
            'url' => str_contains($path, '/') && Storage::disk('public')->exists($path) ? asset('storage/'.$path) : null,
        ], $foto ?? []);
    }
}
