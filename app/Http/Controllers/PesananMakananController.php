<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\ReservationRoom;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
use App\Services\KodeKamar;
use App\Services\PembayaranMidtrans;
use App\Support\Nomor;
use App\Support\Pelanggan;
use App\Support\PersonName;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Pesan makanan online dari halaman Resto: ambil di tempat atau makan di tempat,
 * dibayar lewat Midtrans sebelum diproses dapur. Tidak wajib masuk akun.
 */
class PesananMakananController extends Controller
{
    public function __construct(private PembayaranMidtrans $midtrans) {}

    /**
     * Cocokkan kode kamar tamu menginap (throttle 10/menit per IP). Mengembalikan kamar yang sedang ditempati
     * reservasi itu, hanya nomor kamar dan tanggal keluar. Kode yang cocok disimpan di session.
     */
    public function cocokkanKamar(Request $request, KodeKamar $kodeKamar): JsonResponse
    {
        try {
            $r = $kodeKamar->cocokkan((string) $request->input('kode_kamar'), (string) $request->ip());
        } catch (ValidationException $e) {
            return response()->json(['pesan' => collect($e->errors())->flatten()->first()], $e->status);
        }

        return response()->json(['kamar' => $kodeKamar->kamarUntuk(null, $r->id)]);
    }

    /** Tombol Ganti: lupakan kode kamar di session; kamar milik akun yang masuk tetap tersedia. */
    public function lupakanKamar(Request $request, KodeKamar $kodeKamar): JsonResponse
    {
        $kodeKamar->lupakan();

        return response()->json(['kamar' => $kodeKamar->kamarUntuk($request->user(), null)]);
    }

    /**
     * Pesanan dari halaman Resto. Tamu luar: nama dan WhatsApp, selalu bayar di muka lewat Midtrans.
     * Tamu menginap: kamar yang sedang ditempati (dicocokkan ulang di server), lalu masuk tagihan kamar
     * (langsung masuk dapur, tanpa nota) atau bayar sekarang lewat Midtrans.
     */
    public function store(Request $request): JsonResponse
    {
        $menginap = $request->filled('reservation_room_id');
        $tagihKamar = $menginap && $request->input('tagih', 'kamar') === 'kamar';
        if (! $tagihKamar && ! PembayaranMidtrans::siap()) {
            return response()->json(['pesan' => 'Pembayaran online belum tersedia. Silakan pesan langsung di restoran.'], 503);
        }

        if (! $menginap) {
            $request->merge([
                'name' => PersonName::normalize((string) $request->input('name')),
                'phone' => Phone::normalizeInput((string) $request->input('phone')),
                'email' => filled($request->input('email')) ? Str::lower(trim((string) $request->input('email'))) : null,
            ]);
        }
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.note' => ['nullable', 'string', 'max:150'],
            'name' => [$menginap ? 'nullable' : 'required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'phone' => [$menginap ? 'nullable' : 'required', $menginap ? 'string' : new NomorHp],
            'email' => ['nullable', 'max:150', new EmailWajar],
            // Antar ke kamar hanya untuk tamu menginap
            'serving_type' => ['required', Rule::in($menginap ? ['room_service', 'dine_in', 'take_away'] : ['take_away', 'dine_in'])],
            'note' => ['nullable', 'string', 'max:255'],
            'metode' => [$tagihKamar ? 'nullable' : 'required', Rule::in(array_keys(PembayaranMidtrans::METODE))],
            'reservation_room_id' => ['nullable', 'integer'],
            'tagih' => ['nullable', Rule::in(['kamar', 'sekarang'])],
        ], [
            'items.required' => 'Keranjang masih kosong.',
            'items.min' => 'Keranjang masih kosong.',
            'items.*.qty.max' => 'Maksimal 20 porsi per menu.',
            'items.*.note.max' => 'Catatan per menu maksimal 150 karakter.',
            'name.min' => 'Minimal 3 huruf.',
            'name.regex' => PersonName::MESSAGE,
            'serving_type.required' => 'Pilih cara menikmati.',
            'serving_type.in' => 'Antar ke kamar hanya untuk tamu yang sedang menginap.',
            'metode.*' => 'Pilih metode pembayaran.',
        ]);

        // Tamu menginap: jangan percaya peramban, periksa ulang kamar dengan kode kamar di session atau akun pemilik
        $baris = null;
        if ($menginap) {
            $baris = ReservationRoom::with('reservation.customer')->find((int) $data['reservation_room_id']);
            if (! $baris || ! app(KodeKamar::class)->bolehMemakai($request->user(), $baris)) {
                return response()->json(['pesan' => KodeKamar::TIDAK_BOLEH, 'errors' => ['kamar' => [KodeKamar::TIDAK_BOLEH]]], 422);
            }
        }

        // Harga selalu diambil dari basis data, bukan dari peramban
        $menu = Service::whereIn('id', collect($data['items'])->pluck('id'))
            ->where('category', 'fnb')->where('is_active', true)->whereNotNull('price')->get()
            ->reject(fn (Service $s) => $s->habis())->keyBy('id');
        $hilang = collect($data['items'])->reject(fn ($i) => $menu->has($i['id']));
        if ($hilang->isNotEmpty()) {
            $nama = Service::whereIn('id', $hilang->pluck('id'))->pluck('name')->join(', ');

            return response()->json([
                'pesan' => ($nama !== '' ? $nama.' sudah tidak tersedia.' : 'Sebagian menu di keranjang sudah tidak tersedia.').' Muat ulang halaman lalu periksa keranjang Anda.',
                'hapus' => $hilang->pluck('id')->values(),
            ], 422);
        }

        try {
            $order = DB::transaction(function () use ($data, $menu, $request, $baris, $tagihKamar) {
                // Periksa ulang dengan kunci: check-out yang berjalan bersamaan tidak boleh melewatkan pesanan ini
                $baris = $baris ? ReservationRoom::kunciMenginap($baris->id)->load('reservation.customer') : null;
                $customer = $baris ? $baris->reservation->customer : Pelanggan::dariIsian($data, $request->user());
                $total = collect($data['items'])->sum(fn ($i) => $menu[$i['id']]->price * $i['qty']);

                // Masuk tagihan kamar: tanpa nota sendiri, ditagih bersama nota kamar saat check-out
                $invoice = $tagihKamar ? null : Invoice::create([
                    'invoice_number' => Nomor::nota(),
                    'invoice_type' => 'service',
                    'customer_id' => $customer->id,
                    'payment_term' => 'immediate',
                    'issue_date' => now()->toDateString(),
                    'subtotal' => $total,
                    'total_amount' => $total,
                    'paid_amount' => 0,
                    'status' => 'issued',
                    'note' => 'Pesanan makanan online',
                ]);

                $order = ServiceOrder::create([
                    'order_code' => Nomor::pesanan(),
                    'access_token' => ServiceOrder::tokenBaru(),
                    'order_type' => $baris ? 'in_house' : 'outside_guest',
                    'customer_id' => $customer->id,
                    'reservation_room_id' => $baris?->id,
                    'serving_type' => $data['serving_type'],
                    'invoice_id' => $invoice?->id,
                    'payment_mode' => $tagihKamar ? 'charge_to_room' : 'pay_now',
                    'order_date' => now(),
                    'status' => 'pending',
                    'note' => $data['note'] ?? null,
                ]);

                foreach ($data['items'] as $i) {
                    $m = $menu[$i['id']];
                    // Catatan per menu untuk dapur, misalnya "tidak pedas"
                    $catatan = trim((string) ($i['note'] ?? ''));
                    $order->items()->create([
                        'service_id' => $m->id, 'quantity' => $i['qty'], 'unit_price' => $m->price, 'subtotal' => $m->price * $i['qty'],
                        'note' => $catatan === '' ? null : $catatan,
                    ]);
                    $invoice?->items()->create([
                        'item_type' => 'fnb', 'description' => Str::limit($m->name, 150, ''), 'service_date' => now()->toDateString(),
                        'quantity' => $i['qty'], 'unit_price' => $m->price, 'subtotal' => $m->price * $i['qty'],
                    ]);
                }

                return $order;
            });
        } catch (ValidationException $e) {
            if (! $baris || ! isset($e->errors()['kamar'])) {
                throw $e;
            }

            // Tamu sudah check-out sejak kode kamar dicocokkan
            return response()->json(['pesan' => KodeKamar::TIDAK_BOLEH, 'errors' => ['kamar' => [KodeKamar::TIDAK_BOLEH]]], 422);
        }

        if ($tagihKamar) {
            return response()->json(['status_url' => $order->urlStatus()]);
        }

        // Nomor VA atau QRIS dibuat sekarang, jadi halaman pesanan langsung menampilkannya
        try {
            $this->midtrans->buatTagihan($order, $data['metode']);
        } catch (Throwable $e) {
            Log::error('Tagihan Midtrans gagal dibuat', ['pesanan' => $order->order_code, 'metode' => $data['metode'], 'pesan' => $e->getMessage()]);

            // Pesanan tetap tersimpan; tamu memilih metode lagi di halaman pesanan
            return response()->json([
                'pesan' => 'Pesanan tersimpan, tetapi '.PembayaranMidtrans::METODE[$data['metode']][0].' sedang tidak dapat dipakai. Pilih metode lain di halaman pesanan.',
                'status_url' => $order->urlStatus(),
            ], 502);
        }

        return response()->json(['status_url' => $order->urlStatus()]);
    }

    public function show(Request $request, string $kode): View|RedirectResponse
    {
        $order = $this->pesananDariTautan($request, $kode);
        // Laundry punya halaman status sendiri
        if ($order->jenisLayanan() === 'laundry') {
            return redirect($order->urlStatus());
        }
        $this->midtrans->sinkronkan($order);
        $order = $order->fresh(['items.service', 'invoice.payments', 'customer', 'reservationRoom.room'])->batalkanBilaKedaluwarsa();

        return view('pesanan-status', [
            'order' => $order,
            'title' => 'Pesanan '.$order->order_code,
            'tagihan' => $order->sudahDibayar() || $order->status === 'cancelled' ? null : $this->midtrans->tagihanAktif($order),
        ]);
    }

    /** Buat tagihan (nomor virtual account atau QRIS) untuk metode yang dipilih tamu. */
    public function bayar(Request $request, string $kode): JsonResponse
    {
        $order = $this->pesananDariTautan($request, $kode);
        $metode = $request->validate(['metode' => ['required', Rule::in(array_keys(PembayaranMidtrans::METODE))]], [
            'metode.*' => 'Pilih metode pembayaran.',
        ])['metode'];
        $this->midtrans->sinkronkan($order);
        $order = $order->fresh('invoice')->batalkanBilaKedaluwarsa();

        if ($order->sudahDibayar()) {
            return response()->json(['pesan' => 'Pesanan ini sudah dibayar.'], 409);
        }
        if (! $order->invoice) {
            return response()->json(['pesan' => 'Tagihan belum ada. Tunggu cucian ditimbang.'], 409);
        }
        if ($order->status === 'cancelled') {
            return response()->json(['pesan' => 'Waktu pembayaran sudah habis. Silakan pesan ulang dari halaman Resto.'], 409);
        }

        try {
            return response()->json(['tagihan' => $this->midtrans->buatTagihan($order, $metode)]);
        } catch (Throwable $e) {
            Log::error('Tagihan Midtrans gagal dibuat', ['pesanan' => $order->order_code, 'metode' => $metode, 'pesan' => $e->getMessage()]);

            return response()->json(['pesan' => PembayaranMidtrans::METODE[$metode][0].' sedang tidak dapat dipakai. Coba lagi atau pilih metode lain.'], 502);
        }
    }

    /** Dipanggil berkala oleh halaman pesanan selama tamu menyelesaikan pembayaran. */
    public function cek(Request $request, string $kode): JsonResponse
    {
        $order = $this->pesananDariTautan($request, $kode);
        $this->midtrans->sinkronkan($order);
        $order = $order->fresh('invoice')->batalkanBilaKedaluwarsa();

        return response()->json(['dibayar' => $order->sudahDibayar(), 'batal' => $order->status === 'cancelled']);
    }

    /** Notifikasi dari server Midtrans. Keaslian diperiksa lewat signature_key. */
    public function notifikasi(Request $request): JsonResponse
    {
        $st = $request->json()->all();
        $tanda = hash('sha512', ($st['order_id'] ?? '').($st['status_code'] ?? '').($st['gross_amount'] ?? '').config('services.midtrans.server_key'));
        if (! hash_equals($tanda, (string) ($st['signature_key'] ?? ''))) {
            return response()->json(['pesan' => 'Tanda tangan tidak valid.'], 403);
        }

        $this->midtrans->terapkan($st);

        return response()->json(['pesan' => 'ok']);
    }

    private function pesananDariTautan(Request $request, string $kode): ServiceOrder
    {
        $order = ServiceOrder::dariKodeUrl($kode);
        abort_unless($order && $order->access_token && hash_equals($order->access_token, (string) $request->query('t')), 404);

        return $order;
    }
}
