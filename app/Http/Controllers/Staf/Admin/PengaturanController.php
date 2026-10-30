<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Parameter operasional wisma. Perubahan langsung dipakai seluruh halaman. */
class PengaturanController extends MasterController
{
    /** Label, satuan, dan batas tiap kunci, dikelompokkan untuk tampilan. */
    private const KELOMPOK = [
        'Reservasi dan uang muka' => [
            'dp_min_percent' => ['Uang muka minimum tamu perorangan', '%', 0, 100],
            'dp_due_hours' => ['Batas bayar uang muka', 'jam sejak memesan', 1, 168],
            'extra_bed_max_qty' => ['Maksimal extra bed per kamar', 'unit', 0, 5],
        ],
        'Check-in dan check-out' => [
            'check_in_time' => ['Jam check-in', 'WITA', null, null],
            'early_check_in_from' => ['Early check-in mulai pukul', 'WITA, bila kamar sudah Siap', null, null],
            'check_out_time' => ['Jam check-out', 'WITA', null, null],
            'late_checkout_fee' => ['Biaya terlambat check-out', 'Rp', 0, 10000000],
        ],
        'Pembatalan' => [
            'cancellation_min_days' => ['Pembatalan paling lambat', 'hari sebelum kedatangan', 0, 30],
            'cancellation_refund_percent' => ['Dana dikembalikan saat batal', '% dari uang muka', 0, 100],
        ],
        'Tagihan instansi' => [
            'reminder_days_before' => ['Pengingat tagihan mulai', 'hari sebelum jatuh tempo', 0, 60],
            'institution_letter_threshold' => ['Surat tagihan resmi untuk tagihan di atas', 'Rp', 0, 10000000000],
            // Rekening tujuan yang tercetak di PDF tagihan bulanan dan surat tagihan (baris dari database/sql/2026-10-12-rekening-tagihan.sql)
            'bank_name' => ['Bank rekening tujuan', 'teks', 0, 60],
            'bank_account_number' => ['Nomor rekening', 'teks', 0, 40],
            'bank_account_name' => ['Atas nama rekening', 'teks', 0, 100],
        ],
        // Batas beban otomatis Smart Cleaning (baris dari database/sql/2026-10-13-batas-beban-hk.sql)
        'Housekeeping' => [
            'hk_max_workload' => ['Batas beban staf HK murni', 'poin', 0.5, 20],
            'hk_max_workload_multirole' => ['Batas beban staf multi-role', 'poin', 0.5, 20],
        ],
        'Restoran' => [
            'fnb_max_wait_minutes' => ['Batas tunggu pesanan makanan', 'menit', 1, 240],
        ],
        // Kisaran harga yang tampil di beranda dan halaman /aula (baris dari database/sql/2026-10-22-harga-aula.sql)
        'Sewa aula' => [
            'hall_price_min' => ['Harga sewa per hari (full day), mulai dari', 'Rp', 0, 100000000],
            'hall_price_max' => ['Harga sewa per hari (full day), paling tinggi', 'Rp', 0, 100000000],
        ],
    ];

    /**
     * Pengaturan yang tetap tampil walau barisnya belum ada di tabel settings: nilai bawaan, tipe, dan keterangan.
     * Barisnya dibuat lewat Setting::set saat pertama disimpan (atau dari database/sql/2026-10-30-early-check-in.sql).
     */
    private const BARIS_BAWAAN = [
        'early_check_in_from' => ['06:00', 'time', 'Jam mulai early check-in, hanya untuk kamar berstatus Siap'],
    ];

    public const PERINGATAN_MULTIROLE = 'Perhatian: batas multi-role lebih besar dari batas HK murni, padahal staf multi-role juga bertugas di bagian lain.';

    private static function multiRoleMelebihiMurni(mixed $multirole, mixed $murni): bool
    {
        return is_numeric($multirole) && is_numeric($murni) && (float) $multirole > (float) $murni;
    }

    public function edit(): View
    {
        $settings = self::denganBawaan(Setting::all()->keyBy('setting_key'));
        $terakhir = $settings->filter->exists->sortByDesc('updated_at')->first();

        return view('staf.admin.pengaturan', [
            'kelompok' => self::KELOMPOK,
            'settings' => $settings,
            'terakhir' => $terakhir,
            'pengubah' => $terakhir?->updated_by ? User::find($terakhir->updated_by)?->name : null,
            'peringatanMultirole' => self::multiRoleMelebihiMurni($settings['hk_max_workload_multirole']->value ?? null, $settings['hk_max_workload']->value ?? null),
        ]);
    }

    /** Tambahkan baris bawaan yang belum ada di basis data (belum disimpan) supaya isiannya tetap tampil. */
    private static function denganBawaan($settings)
    {
        foreach (self::BARIS_BAWAAN as $kunci => [$nilai, $tipe, $ket]) {
            if (! isset($settings[$kunci])) {
                $settings[$kunci] = new Setting(['setting_key' => $kunci, 'value' => $nilai, 'type' => $tipe, 'description' => $ket]);
            }
        }

        return $settings;
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $settings = self::denganBawaan(Setting::all()->keyBy('setting_key'));

        $aturan = [];
        foreach (self::KELOMPOK as $isi) {
            foreach ($isi as $kunci => [, , $min, $max]) {
                // Baris yang belum ada di basis data (file SQL belum dijalankan) dilewati
                if (! isset($settings[$kunci])) {
                    continue;
                }
                $aturan[$kunci] = match ($settings[$kunci]->type) {
                    'time' => ['required', 'date_format:H:i'],
                    'string' => ['nullable', 'string', "max:$max"],
                    'integer' => ['required', 'integer', "min:$min", "max:$max"],
                    default => ['required', 'numeric', "min:$min", "max:$max"],
                };
                // Baris bawaan yang belum tersimpan hanya diperiksa (dan dibuat) bila isiannya dikirim
                if (! $settings[$kunci]->exists) {
                    array_unshift($aturan[$kunci], 'sometimes');
                }
            }
        }
        $data = $request->validate($aturan, ['date_format' => 'Format jam tidak valid.']);
        // Early check-in harus dimulai sebelum jam check-in normal
        if (isset($data['early_check_in_from'], $data['check_in_time']) && $data['early_check_in_from'] >= $data['check_in_time']) {
            throw ValidationException::withMessages(['early_check_in_from' => 'Harus lebih awal dari jam check-in.']);
        }

        $diubah = 0;
        DB::transaction(function () use ($data, $settings, $request, &$diubah) {
            foreach ($data as $kunci => $nilai) {
                $nilai = trim((string) $nilai);
                if (! $settings[$kunci]->exists || $settings[$kunci]->value !== $nilai) {
                    Setting::set($kunci, $nilai, $request->user()->id, $settings[$kunci]->type, $settings[$kunci]->description);
                    $diubah++;
                }
            }
        });
        Cache::forget('settings.all');

        $pesan = $diubah ? "Pengaturan disimpan ({$diubah} diubah)." : 'Tidak ada pengaturan yang berubah.';
        // Hanya peringatan, tidak memblokir: owner boleh sengaja memberi staf multi-role batas lebih besar
        if (self::multiRoleMelebihiMurni(Setting::get('hk_max_workload_multirole'), Setting::get('hk_max_workload'))) {
            $pesan .= ' '.self::PERINGATAN_MULTIROLE;
        }

        return $this->selesai($request, $pesan);
    }
}
