@props(['jenis', 'kode', 'hp' => null, 'telepon' => '0822-9222-4111'])
{{-- Halaman pesanan yang dibuka dari Cek Pesanan (lihat saja, tanpa token). Tombol kirim ulang mengirim tautan bertoken
     ke nomor WhatsApp pemesan yang tersimpan, bukan nomor yang diketik (CekPesananLihatController::kirimUlang). --}}
<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-4 rounded-lg border border-[rgba(108,149,196,.35)] bg-[rgba(108,149,196,.08)] px-5 py-4 sm:flex-row sm:items-center']) }} role="note" data-mode-lihat>
    <div class="flex min-w-0 flex-1 items-start gap-3">
        <svg class="mt-0.5 flex-none text-[#3F6B9C]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
        <p class="m-0 text-[13px] leading-relaxed text-ink-900">
            <span class="font-semibold">Mode lihat saja.</span>
            Untuk membatalkan atau mengubah tanggal, buka link di pesan WhatsApp/email, masuk ke akun, atau hubungi resepsionis {{ $telepon }}.
        </p>
    </div>
    <form method="POST" action="{{ route('cek.lihat.kirim', ['jenis' => $jenis, 'kode' => $kode]) }}" class="m-0 flex-none">
        @csrf
        <button type="submit" class="h-10 w-full rounded border border-[rgba(63,107,156,.4)] bg-cream-50 px-4 text-[13px] font-semibold text-[#3F6B9C] hover:bg-[rgba(108,149,196,.12)] sm:w-auto">
            Kirim ulang link ke WhatsApp
        </button>
        <span class="mt-1 block text-center text-[11px] text-ink-500 sm:text-right">ke {{ \App\Support\IzinCekPesanan::samarkan($hp) }}</span>
    </form>
</div>
