<?php

namespace App\Http\Controllers\Tamu;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Keuangan\NotaPdf;
use App\Services\Keuangan\PembayaranMidtrans;
use App\Services\Reservasi\BuktiInstansi;
use App\Services\Reservasi\FolioService;
use App\Services\Reservasi\ReservasiService;
use App\Support\IzinCekPesanan;
use App\Support\PesananPerangkat;
use App\Support\TokenAkses;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Halaman reservasi untuk tamu (tautan dari WhatsApp/email, halaman Cek pesanan, atau Pesanan saya):
 * status, pembayaran uang muka lewat Midtrans, pembatalan, dan ubah tanggal.
 * Dibuka dengan token akses di tautan, atau oleh akun pemilik reservasi. Dari Cek Pesanan halaman ini tampil
 * LIHAT SAJA (lihat(), izin di session lewat IzinCekPesanan): bayar dan cek status boleh, batal dan ubah tanggal tidak.
 * Tautan bagikan (bagikan(), URL bertanda tangan tanpa token) untuk orang lain: baca saja, tanpa bayar, kode, dan nota.
 */
class ReservasiTamuController extends Controller
{
    public function __construct(private PembayaranMidtrans $midtrans, private ReservasiService $reservasi) {}

    public function show(Request $request, string $kode): View
    {
        return $this->tampilkan($this->segarkan($this->dariTautan($request, $kode)), false);
    }

    /** Halaman hanya-lihat dari Cek Pesanan; pemanggil (CekPesananLihatController) sudah memeriksa izin session. */
    public function lihat(Reservation $r): View
    {
        return $this->tampilkan($this->segarkan($r), true);
    }

    /** Tautan bagikan dari pemesan (middleware signed sudah memeriksa tanda tangan URL). */
    public function bagikan(string $kode): View
    {
        $r = Reservation::where('reservation_code', strtoupper($kode))->firstOrFail();

        return $this->tampilkan($this->segarkan($r), true, dibagikan: true);
    }

    /**
     * $hanyaLihat: token tidak dikirim ke tampilan sama sekali dan aksi yang mengubah reservasi disembunyikan.
     * $dibagikan: dibuka orang lain lewat tautan bagikan; pembayaran dan nota juga disembunyikan.
     */
    private function tampilkan(Reservation $r, bool $hanyaLihat, bool $dibagikan = false): View
    {
        // Tamu tanpa akun: masuk ke tab "Pesanan terakhir" di Cek Pesanan (lihat saja tanpa token).
        // Penerima tautan bagikan bukan pemesan, jadi reservasinya tidak dicatat di perangkatnya.
        if (! $dibagikan) {
            PesananPerangkat::reservasi($r, $hanyaLihat);
        }

        return view('tamu.reservasi-status', [
            'r' => $r,
            'title' => 'Reservasi '.$r->reservation_code,
            'hanyaLihat' => $hanyaLihat,
            'dibagikan' => $dibagikan,
            // Tautan bagikan tidak memuat token akses. Hanya ditawarkan ke pemesan (token atau akun) selama reservasi masih
            // berjalan; tidak ke mode lihat dari Cek Pesanan (izin sementara di session) dan tidak ke penerima tautan.
            'urlBagikan' => ! $hanyaLihat && in_array($r->status, ['pending_payment', 'waiting_approval', 'confirmed', 'checked_in'], true)
                ? URL::signedRoute('reservasi.lihat', ['kode' => $r->reservation_code]) : null,
            'tagihan' => ! $dibagikan && $r->status === 'pending_payment' ? $this->midtrans->tagihanAktif($r) : null,
            // Selama menginap tamu melihat tagihan berjalan, termasuk layanan yang ditagihkan ke kamar
            'folio' => $r->status === 'checked_in' ? app(FolioService::class)->rincian($r) : null,
            'token' => $hanyaLihat ? null : $r->access_token,
            'bolehUbah' => ! $hanyaLihat && $r->status === 'confirmed' && $this->reservasi->masihBolehDiubah($r) && $r->kamarBerlaku()->every(fn ($k) => $k->status === 'booked'),
            'bolehBatal' => ! $hanyaLihat && $this->bolehBatal($r),
            'pengembalian' => $this->reservasi->pengembalian($r),
            // Nota bisa diunduh setelah ada pembayaran yang diterima
            'urlNota' => ! $dibagikan && $r->stayInvoice && $r->stayInvoice->status !== 'cancelled' && (float) $r->stayInvoice->paid_amount > 0
                ? app(NotaPdf::class)->tautan($r->stayInvoice) : null,
            // Reservasi instansi yang disetujui: kode bukti untuk disebutkan di resepsionis, dan PDF-nya.
            // Kode bukti = token akses, jadi tidak tampil di mode lihat saja.
            'bukti' => ! $hanyaLihat && BuktiInstansi::berlaku($r) ? ['url' => app(BuktiInstansi::class)->tautan($r)] : null,
        ]);
    }

    /** Buat tagihan uang muka (nomor virtual account atau QRIS) untuk metode yang dipilih tamu. */
    public function bayar(Request $request, string $kode): JsonResponse
    {
        $r = $this->dariTautan($request, $kode, izinLihat: true);
        $metode = $request->validate(['metode' => ['required', Rule::in(array_keys(PembayaranMidtrans::METODE))]], [
            'metode.*' => 'Pilih metode pembayaran.',
        ])['metode'];
        $r = $this->segarkan($r);

        if ($r->status !== 'pending_payment') {
            return response()->json(['pesan' => $r->status === 'cancelled'
                ? 'Batas waktu bayar uang muka sudah lewat dan reservasi dibatalkan.'
                : 'Uang muka reservasi ini sudah dibayar.'], 409);
        }
        if (! PembayaranMidtrans::siap()) {
            return response()->json(['pesan' => 'Pembayaran online belum tersedia. Hubungi resepsionis.'], 503);
        }

        try {
            return response()->json(['tagihan' => $this->midtrans->buatTagihan($r, $metode)]);
        } catch (Throwable $e) {
            Log::error('Tagihan uang muka Midtrans gagal dibuat', ['reservasi' => $r->reservation_code, 'metode' => $metode, 'pesan' => $e->getMessage()]);

            return response()->json(['pesan' => PembayaranMidtrans::METODE[$metode][0].' sedang tidak dapat dipakai. Coba lagi atau pilih metode lain.'], 502);
        }
    }

    /** Dipanggil berkala oleh halaman reservasi selama tamu menyelesaikan pembayaran. */
    public function cek(Request $request, string $kode): JsonResponse
    {
        $r = $this->segarkan($this->dariTautan($request, $kode, izinLihat: true));

        return response()->json(['dibayar' => $r->status !== 'pending_payment' && $r->status !== 'cancelled', 'batal' => $r->status === 'cancelled']);
    }

    public function batal(Request $request, string $kode): RedirectResponse
    {
        $r = $this->segarkan($this->dariTautan($request, $kode));
        $alasan = $request->validate(['alasan' => ['nullable', 'string', 'max:150']])['alasan'] ?? null;

        if (! $this->bolehBatal($r)) {
            return back()->with('error', 'Reservasi ini tidak bisa dibatalkan online. Hubungi resepsionis.');
        }

        $kembali = $this->reservasi->batalkan($r, 'Dibatalkan tamu'.($alasan ? ": {$alasan}" : ''), null);
        $this->midtrans->akhiriTagihan($r);

        return redirect($r->urlStatus())->with('status', $kembali > 0
            ? 'Reservasi dibatalkan. Uang muka Rp'.number_format($kembali, 0, ',', '.').' dikembalikan; resepsionis menghubungi Anda untuk rekening tujuan.'
            : 'Reservasi dibatalkan.');
    }

    public function ubahTanggal(Request $request, string $kode): RedirectResponse
    {
        $r = $this->dariTautan($request, $kode);
        $data = $request->validate([
            'masuk' => ['required', 'date_format:Y-m-d'],
            'keluar' => ['required', 'date_format:Y-m-d'],
        ], ['*.required' => 'Pilih tanggal check-in dan check-out.', '*.date_format' => 'Tanggal tidak valid.']);

        $this->reservasi->ubahTanggal($r, CarbonImmutable::parse($data['masuk']), CarbonImmutable::parse($data['keluar']), null);

        return redirect($r->urlStatus())->with('status', 'Tanggal menginap diubah. Harga per malam tetap sama.');
    }

    /** Tamu boleh membatalkan sendiri: sebelum uang muka dibayar, saat pengajuan instansi menunggu, atau lebih dari N hari sebelum kedatangan. */
    private function bolehBatal(Reservation $r): bool
    {
        return match ($r->status) {
            'pending_payment', 'waiting_approval' => true,
            'confirmed' => $this->reservasi->masihBolehDiubah($r) && $r->kamarBerlaku()->every(fn ($k) => $k->status === 'booked'),
            default => false,
        };
    }

    /** Status pembayaran terbaru dari Midtrans, lalu batalkan bila batas bayar sudah lewat. */
    private function segarkan(Reservation $r): Reservation
    {
        if ($r->status === 'pending_payment') {
            $this->midtrans->sinkronkan($r);
            $r->refresh();
            $this->reservasi->batalkanBilaKedaluwarsa($r);
        }

        return $r->fresh(['rooms.roomType', 'rooms.room', 'stayInvoice.items', 'stayInvoice.payments', 'customer', 'institution', 'request', 'logs']);
    }

    /**
     * Token di tautan atau akun pemilik. $izinLihat: izin lihat saja dari Cek Pesanan juga diterima; hanya untuk bayar
     * dan cek status, TIDAK untuk batal dan ubah tanggal.
     */
    private function dariTautan(Request $request, string $kode, bool $izinLihat = false): Reservation
    {
        $r = Reservation::where('reservation_code', strtoupper($kode))->first();
        $token = TokenAkses::rapikan((string) $request->input('t'));
        $pemilik = $r && $request->user() && $r->customer?->user_id === $request->user()->id;
        $lihat = $izinLihat && $r && IzinCekPesanan::berlaku('reservasi', $r->reservation_code);
        abort_unless($r && $r->access_token && ($pemilik || $lihat || ($token !== '' && hash_equals($r->access_token, $token))), 404);

        return $r;
    }
}
