<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Rules\EmailWajar;
use App\Rules\NomorHp;
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
use Illuminate\View\View;
use Throwable;

/**
 * Pesan makanan online dari halaman Resto: ambil di tempat atau makan di tempat,
 * dibayar lewat Midtrans sebelum diproses dapur. Tidak wajib masuk akun.
 */
class PesananMakananController extends Controller
{
    public function __construct(private PembayaranMidtrans $midtrans) {}

    public function store(Request $request): JsonResponse
    {
        if (! PembayaranMidtrans::siap()) {
            return response()->json(['pesan' => 'Pembayaran online belum tersedia. Silakan pesan langsung di restoran.'], 503);
        }

        $request->merge([
            'name' => PersonName::normalize((string) $request->input('name')),
            'phone' => Phone::normalizeInput((string) $request->input('phone')),
            'email' => filled($request->input('email')) ? Str::lower(trim((string) $request->input('email'))) : null,
        ]);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.note' => ['nullable', 'string', 'max:150'],
            'name' => ['required', 'string', 'min:3', 'max:100', PersonName::rule()],
            'phone' => ['required', new NomorHp],
            'email' => ['nullable', 'max:150', new EmailWajar],
            'serving_type' => ['required', Rule::in(['take_away', 'dine_in'])],
            'note' => ['nullable', 'string', 'max:255'],
            'metode' => ['required', Rule::in(array_keys(PembayaranMidtrans::METODE))],
        ], [
            'items.required' => 'Keranjang masih kosong.',
            'items.min' => 'Keranjang masih kosong.',
            'items.*.qty.max' => 'Maksimal 20 porsi per menu.',
            'items.*.note.max' => 'Catatan per menu maksimal 150 karakter.',
            'name.min' => 'Minimal 3 huruf.',
            'name.regex' => PersonName::MESSAGE,
            'serving_type.required' => 'Pilih ambil atau makan di tempat.',
            'metode.*' => 'Pilih metode pembayaran.',
        ]);

        // Harga selalu diambil dari basis data, bukan dari peramban
        $menu = Service::whereIn('id', collect($data['items'])->pluck('id'))
            ->where('category', 'fnb')->where('is_active', true)->whereNotNull('price')->get()
            ->reject(fn (Service $s) => $s->habis())->keyBy('id');
        $hilang = collect($data['items'])->reject(fn ($i) => $menu->has($i['id']));
        if ($hilang->isNotEmpty()) {
            return response()->json([
                'pesan' => 'Sebagian menu di keranjang sudah tidak tersedia. Muat ulang halaman lalu periksa keranjang Anda.',
                'hapus' => $hilang->pluck('id')->values(),
            ], 422);
        }

        $order = DB::transaction(function () use ($data, $menu, $request) {
            $customer = Pelanggan::dariIsian($data, $request->user());
            $total = collect($data['items'])->sum(fn ($i) => $menu[$i['id']]->price * $i['qty']);

            $invoice = Invoice::create([
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
                'order_type' => 'outside_guest',
                'customer_id' => $customer->id,
                'serving_type' => $data['serving_type'],
                'invoice_id' => $invoice->id,
                'payment_mode' => 'pay_now',
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
                $invoice->items()->create([
                    'item_type' => 'fnb', 'description' => Str::limit($m->name, 150, ''), 'service_date' => now()->toDateString(),
                    'quantity' => $i['qty'], 'unit_price' => $m->price, 'subtotal' => $m->price * $i['qty'],
                ]);
            }

            return $order;
        });

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
        $order = $order->fresh(['items.service', 'invoice.payments', 'customer'])->batalkanBilaKedaluwarsa();

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
