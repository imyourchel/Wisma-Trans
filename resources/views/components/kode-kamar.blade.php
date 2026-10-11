@props([
    'reservasi',                  // App\Models\Reservation
    'label' => 'Kode kamar',
    'besar' => false,             // kode ditampilkan besar (halaman reservasi)
])
{{-- Kode kamar dengan tombol Salin dan Bagikan. Kodenya ditulis sekali; teks bagikan dibentuk dari kode yang tampil. --}}
<div {{ $attributes->merge(['class' => 'min-w-0']) }}
     x-data="{
        kode() { return this.$refs.kode.textContent.trim(); },
        teks() {
            return 'Kode kamar Wisma Trans: ' + this.kode() + ' (' + @js($reservasi->reservation_code) + '). Pakai untuk pesan makanan dan laundry selama menginap. '
                + 'Makanan: ' + @js($reservasi->urlPesanMakanan()) + ' · Laundry: ' + @js($reservasi->urlPesanLaundry());
        },
        salin() {
            navigator.clipboard?.writeText(this.kode())
                .then(() => $store.toasts.tampil('Kode kamar disalin'))
                .catch(() => $store.toasts.tampil('Kode kamar tidak dapat disalin', '#E8AC45'));
        },
        bagikan() {
            const teks = this.teks();
            if (navigator.share) {
                navigator.share({ title: 'Kode kamar Wisma Trans', text: teks }).catch(() => {});
            } else {
                window.open('https://wa.me/?text=' + encodeURIComponent(teks), '_blank', 'noopener');
            }
        },
     }">
    <div class="wt-eyebrow">{{ $label }}</div>
    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-2">
        <span x-ref="kode" @class(['font-display font-extrabold tabular-nums', 'text-[30px] tracking-[.16em]' => $besar, 'text-xl tracking-[.12em]' => ! $besar])>{{ $reservasi->kodeKamar() }}</span>
        <span class="flex gap-1.5">
            <button type="button" @click="salin()" class="inline-flex h-8 items-center gap-1.5 rounded-full border border-cream-300 bg-cream-50 px-3 text-[12px] font-semibold text-brown-800 hover:bg-cream-200">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Salin
            </button>
            <button type="button" @click="bagikan()" class="inline-flex h-8 items-center gap-1.5 rounded-full border border-cream-300 bg-cream-50 px-3 text-[12px] font-semibold text-brown-800 hover:bg-cream-200">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg>
                Bagikan
            </button>
        </span>
    </div>
    {{ $slot }}
    <p class="mb-0 mt-2 text-xs leading-relaxed text-ink-500">Bagikan hanya kepada orang yang menginap bersama Anda. Kode ini bisa dipakai untuk menagihkan pesanan ke kamar.</p>
</div>
