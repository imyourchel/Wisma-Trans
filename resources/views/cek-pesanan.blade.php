<x-layouts.guest title="Cek pesanan">
<div class="wt-container animate-wt-masuk max-w-[560px] py-12 sm:py-16">
    <div class="wt-eyebrow">Cek pesanan</div>
    <h1 class="mb-0 mt-1.5 font-display text-[28px] font-extrabold leading-tight tracking-[.02em]">Lihat reservasi Anda</h1>
    <p class="mb-0 mt-2 text-sm leading-relaxed text-ink-500">
        Masukkan kode reservasi dan kode kamar dari pesan konfirmasi WhatsApp atau email. Anda bisa membayar uang muka,
        mengubah tanggal, atau membatalkan reservasi dari halaman itu.
    </p>

    <form method="POST" action="{{ route('cek.cari') }}" class="wt-card mt-6 flex flex-col gap-4 p-5 sm:p-6" novalidate>
        @csrf
        @foreach ([
            ['kode', 'Kode reservasi', 'Contoh: RSV-0016', 'off', 20],
            ['token', 'Kode kamar', 'Contoh: LK7Q-2M9X', 'off', 12],
        ] as [$f, $label, $ph, $auto, $maks])
            <label class="block">
                <span class="wt-field-label mb-1.5 block">{{ $label }}</span>
                <input type="text" name="{{ $f }}" value="{{ old($f) }}" placeholder="{{ $ph }}" autocomplete="{{ $auto }}" maxlength="{{ $maks }}"
                       autocapitalize="characters" spellcheck="false" @error($f) aria-invalid="true" @enderror
                       class="wt-input h-11 text-[15px] uppercase tracking-[.06em] placeholder:normal-case placeholder:tracking-normal @error($f) is-invalid @enderror">
                @error($f)
                    <span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>
                @enderror
            </label>
        @endforeach
        <button class="h-12 rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600">Cek reservasi</button>
    </form>

    <p class="mt-6 text-sm text-ink-500">
        @auth
            Semua pesanan akun Anda ada di <a href="{{ route('pesanan') }}" class="font-semibold">Pesanan saya</a>.
        @else
            Punya akun? <a href="{{ route('login') }}" @click.prevent="$dispatch('buka-akun', { mode: 'masuk', kembali: @js(route('pesanan')) })" class="font-semibold">Masuk</a> untuk melihat semua pesanan Anda.
        @endauth
        Kehilangan token? Hubungi resepsionis {{ $wisma['telepon'] ?? '' }}.
    </p>
</div>
</x-layouts.guest>
