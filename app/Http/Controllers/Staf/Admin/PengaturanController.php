<?php

namespace App\Http\Controllers\Staf\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        'Restoran' => [
            'fnb_max_wait_minutes' => ['Batas tunggu pesanan makanan', 'menit', 1, 240],
        ],
    ];

    public function edit(): View
    {
        $settings = Setting::all()->keyBy('setting_key');
        $terakhir = $settings->sortByDesc('updated_at')->first();

        return view('staf.admin.pengaturan', [
            'kelompok' => self::KELOMPOK,
            'settings' => $settings,
            'terakhir' => $terakhir,
            'pengubah' => $terakhir?->updated_by ? User::find($terakhir->updated_by)?->name : null,
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $settings = Setting::all()->keyBy('setting_key');

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
            }
        }
        $data = $request->validate($aturan, ['date_format' => 'Format jam tidak valid.']);

        $diubah = 0;
        DB::transaction(function () use ($data, $settings, $request, &$diubah) {
            foreach ($data as $kunci => $nilai) {
                $nilai = trim((string) $nilai);
                if ($settings[$kunci]->value !== $nilai) {
                    $settings[$kunci]->update(['value' => $nilai, 'updated_by' => $request->user()->id]);
                    $diubah++;
                }
            }
        });
        Cache::forget('settings.all');

        return $this->selesai($request, $diubah ? "Pengaturan disimpan ({$diubah} diubah)." : 'Tidak ada pengaturan yang berubah.');
    }
}
