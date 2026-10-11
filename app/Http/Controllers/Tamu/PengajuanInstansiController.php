<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\ReservasiService;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Pengajuan reservasi instansi (B2B) oleh narahubung (PIC) tanpa wajib masuk akun.
 * Reservasi berstatus menunggu persetujuan tanpa baris kamar; Manager menetapkan kamar dan harga.
 */
class PengajuanInstansiController extends Controller
{
    public function create(): View
    {
        return view('tamu.instansi');
    }

    public function store(Request $request, ReservasiService $reservasi, NotifikasiReservasi $notifikasi): RedirectResponse
    {
        $request->merge([
            'name' => PersonName::normalize((string) $request->input('name')),
            'phone' => Phone::normalizeInput((string) $request->input('phone')),
            'email' => filled($request->input('email')) ? Str::lower(trim((string) $request->input('email'))) : null,
            'instansi' => trim((string) $request->input('instansi')),
        ]);
        $data = $request->validate([
            'instansi' => ['required', 'string', 'min:3', 'max:150'],
            'name' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'phone' => ['required', new NomorHp],
            'email' => ['nullable', 'max:150', new EmailWajar],
            'masuk' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'keluar' => ['required', 'date_format:Y-m-d', 'after:masuk'],
            'jumlah' => ['required', 'integer', 'min:1', 'max:32'],
            'kegiatan' => ['nullable', 'string', 'max:150'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ], [
            'instansi.required' => 'Isi nama instansi.',
            'instansi.min' => 'Minimal 3 huruf.',
            'name.required' => 'Isi nama narahubung.',
            'name.min' => 'Minimal 3 huruf.',
            'name.regex' => PersonName::MESSAGE,
            'masuk.required' => 'Pilih tanggal check-in.',
            'masuk.after_or_equal' => 'Tanggal check-in tidak boleh sebelum hari ini.',
            'keluar.required' => 'Pilih tanggal check-out.',
            'keluar.after' => 'Tanggal check-out harus setelah check-in.',
            'jumlah.required' => 'Isi jumlah kamar.',
            'jumlah.min' => 'Minimal 1 kamar.',
            'jumlah.max' => 'Wisma Trans memiliki 32 kamar.',
        ], ['catatan' => 'catatan', 'kegiatan' => 'nama kegiatan']);

        // Staf yang mengisikan pengajuan untuk instansi tidak menautkan narahubung ke akunnya sendiri
        $staf = $request->user()?->isStaff() ? $request->user() : null;
        $pic = Pelanggan::dariIsian($data, $staf ? null : $request->user());
        // Instansi yang sudah terdaftar dikenali dari namanya; yang belum, dicatat untuk ditetapkan Manager saat menyetujui
        $instansi = Institution::whereRaw('LOWER(name) = ?', [Str::lower($data['instansi'])])->first();
        $catatan = collect([
            $instansi || $pic->institution_id ? null : 'Instansi: '.$data['instansi'],
            $data['catatan'] ?? null,
        ])->filter()->join("\n");

        $r = $reservasi->ajukanInstansi($pic, $instansi, CarbonImmutable::parse($data['masuk']), CarbonImmutable::parse($data['keluar']),
            (int) $data['jumlah'], $data['kegiatan'] ?? null, $catatan ?: null, $staf);
        $notifikasi->dibuat($r);

        return redirect($r->urlStatus())->with('status', 'Pengajuan terkirim. Simpan kode '.$r->reservation_code.' dan token '.$r->token().' untuk mengecek statusnya.');
    }
}
