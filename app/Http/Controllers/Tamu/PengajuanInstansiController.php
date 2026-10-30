<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\Notifikasi\NotifikasiReservasi;
use App\Services\Reservasi\ReservasiService;
use App\Support\NamaInstansi;
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
            'keluar' => ['required', 'date_format:Y-m-d', 'after:masuk', function (string $atribut, mixed $nilai, \Closure $gagal) use ($request) {
                $masuk = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->input('masuk'));
                $keluar = CarbonImmutable::createFromFormat('!Y-m-d', (string) $nilai);
                if ($masuk && $keluar && $masuk->diffInDays($keluar) > ReservasiService::MAKS_MALAM_INSTANSI) {
                    $gagal('Maksimal '.ReservasiService::MAKS_MALAM_INSTANSI.' malam per pengajuan. Hubungi kami untuk masa menginap lebih lama.');
                }
            }],
            'jumlah' => ['required', 'integer', 'min:1', 'max:32'],
            'peserta' => ['required', 'integer', 'min:1', 'max:200', 'gte:jumlah'],
            'kegiatan' => ['nullable', 'string', 'max:150'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ], [
            'instansi.required' => 'Isi nama instansi atau perusahaan.',
            'instansi.min' => 'Minimal 3 huruf.',
            'name.required' => 'Isi nama penanggung jawab.',
            'name.min' => 'Minimal 3 huruf.',
            'name.regex' => PersonName::MESSAGE,
            'phone.required' => 'Isi nomor WhatsApp penanggung jawab.',
            'masuk.required' => 'Pilih tanggal check-in.',
            'masuk.after_or_equal' => 'Tanggal check-in tidak boleh sebelum hari ini.',
            'keluar.required' => 'Pilih tanggal check-out.',
            'keluar.after' => 'Tanggal check-out harus setelah check-in.',
            'jumlah.required' => 'Isi jumlah kamar.',
            'jumlah.min' => 'Minimal 1 kamar.',
            'jumlah.max' => 'Wisma Trans memiliki 32 kamar.',
            'peserta.required' => 'Isi jumlah tamu.',
            'peserta.integer' => 'Isi jumlah tamu dengan angka.',
            'peserta.min' => 'Minimal 1 tamu.',
            'peserta.max' => 'Maksimal 200 tamu per pengajuan. Hubungi kami untuk rombongan lebih besar.',
            'peserta.gte' => 'Jumlah tamu tidak boleh lebih sedikit dari jumlah kamar.',
        ], ['catatan' => 'catatan', 'kegiatan' => 'nama kegiatan']);

        // Staf yang mengisikan pengajuan untuk instansi tidak menautkan narahubung ke akunnya sendiri
        $staf = $request->user()?->isStaff() ? $request->user() : null;
        $pic = Pelanggan::dariIsian($data, $staf ? null : $request->user());
        // Hanya nama yang normalisasinya sama persis ("PT. Vale Indonesia" = "PT Vale Indonesia Tbk") yang ditautkan otomatis.
        // Selain itu nama tulisan pemohon dicatat dan Manager yang memutuskan; daftar instansi tidak pernah ditampilkan ke tamu.
        $instansi = NamaInstansi::sama($data['instansi']);
        // Tanpa kolom baru: jumlah peserta disimpan sebagai baris pertama catatan (Reservation::pesertaInstansi)
        $catatan = collect([
            'Jumlah peserta: '.(int) $data['peserta'].' orang',
            $instansi ? null : 'Instansi: '.$data['instansi'],
            $data['catatan'] ?? null,
        ])->filter()->join("\n");

        $r = $reservasi->ajukanInstansi($pic, $instansi, CarbonImmutable::parse($data['masuk']), CarbonImmutable::parse($data['keluar']),
            (int) $data['jumlah'], $data['kegiatan'] ?? null, $catatan ?: null, $staf, ikutInstansiNarahubung: false);
        $notifikasi->dibuat($r);

        return redirect($r->urlStatus())->with('status', 'Pengajuan terkirim. Simpan kode '.$r->reservation_code.' dan token '.$r->token().' untuk mengecek statusnya.');
    }
}
