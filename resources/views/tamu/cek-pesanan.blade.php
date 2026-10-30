@php
    // Tab awal: hasil pencarian yang gagal tetap di tab Cari; selain itu daftar perangkat bila ada isinya
    $tabAwal = $errors->any() || $perangkat->isEmpty() ? 'cari' : 'perangkat';
@endphp
<x-layouts.guest title="Cek pesanan">
<div class="wt-container animate-wt-masuk max-w-[760px] py-12 sm:py-16" x-data="{ tab: @js($tabAwal) }">
    <div class="wt-eyebrow">Cek pesanan</div>
    <h1 class="mb-0 mt-1.5 font-display text-[28px] font-extrabold leading-tight tracking-[.02em]">Pesanan Anda</h1>
    <p class="mb-0 mt-2 max-w-[560px] text-sm leading-relaxed text-ink-500">
        Lihat pesanan yang baru Anda buat atau buka, atau cari pesanan dengan kode dari pesan konfirmasi WhatsApp atau email.
    </p>

    {{-- Tab --}}
    <div class="mt-6 flex gap-6 border-b border-cream-300" role="tablist" aria-label="Cara melihat pesanan">
        @foreach (['perangkat' => 'Pesanan terakhir', 'cari' => 'Cari dengan kode'] as $kunci => $nama)
            <button type="button" role="tab" @click="tab = @js($kunci)" :aria-selected="tab === @js($kunci)" aria-controls="panel-{{ $kunci }}" data-tab-cek="{{ $kunci }}"
                    {{-- Kelas keadaan hanya lewat Alpine (:class), supaya garis aktif tidak tampil di dua tab sekaligus --}}
                    :class="tab === @js($kunci) ? 'text-ink-900 after:scale-x-100' : 'text-ink-500 hover:text-ink-900 after:scale-x-0'"
                    class="relative -mb-px flex h-11 items-center gap-2 border-0 bg-transparent p-0 font-display text-[12px] font-bold uppercase tracking-[.12em] after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:origin-left after:bg-clay-500 after:transition-transform">
                {{ $nama }}
                @if ($kunci === 'perangkat' && $perangkat->isNotEmpty())
                    <span class="rounded-full bg-cream-200 px-2 py-0.5 font-sans text-[11px] font-semibold tracking-normal tabular-nums text-brown-800">{{ $perangkat->count() }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Pesanan terakhir: tersimpan di peramban ini (cookie terenkripsi, App\Support\PesananPerangkat) --}}
    <section id="panel-perangkat" role="tabpanel" x-show="tab === 'perangkat'" @style(['display: none' => $tabAwal !== 'perangkat']) data-panel-perangkat class="mt-6">
        @if ($perangkat->isNotEmpty())
            <div class="flex flex-col gap-3">
                @foreach ($perangkat as $p)
                    <x-tamu.kartu-pesanan :p="$p" />
                @endforeach
            </div>
        @else
            <div class="flex flex-col items-center rounded-xl border border-dashed border-cream-400 bg-cream-50 px-6 py-12 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-cream-200 text-clay-600" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M11 18h2"/></svg>
                </span>
                <h2 class="mb-0 mt-4 font-display text-base font-extrabold">Belum ada pesanan terakhir</h2>
                <p class="mb-0 mt-1.5 max-w-[380px] text-[13px] leading-relaxed text-ink-500">Pesanan yang Anda buat tanpa akun akan muncul di sini secara otomatis, lengkap dengan statusnya.</p>
                <button type="button" @click="tab = 'cari'" class="wt-btn-outline mt-5 h-10 px-5 text-[11px] tracking-[.08em]">Cari dengan kode</button>
            </div>
        @endif
    </section>

    {{-- Cari satu pesanan: kode pesanan dan nomor WhatsApp pemesan (reservasi juga bisa dengan kode kamar) --}}
    <section id="panel-cari" role="tabpanel" x-show="tab === 'cari'" @style(['display: none' => $tabAwal !== 'cari']) class="mt-6">
        <p class="mb-0 text-sm leading-relaxed text-ink-500">
            Berlaku untuk reservasi kamar, pesanan makanan, laundry, dan sewa aula. Masukkan kode pesanan dan nomor WhatsApp
            yang Anda pakai saat memesan.
        </p>
        <form method="POST" action="{{ route('cek.cari') }}" class="wt-card mt-4 flex flex-col gap-4 p-5 sm:p-6" novalidate>
            @csrf
            <label class="block">
                <span class="wt-field-label mb-1.5 block">Kode pesanan</span>
                <input type="text" name="kode" value="{{ old('kode') }}" placeholder="Contoh: RSV-0016 atau ORD/2026/10/0084" autocomplete="off" maxlength="30"
                       autocapitalize="characters" spellcheck="false" @error('kode') aria-invalid="true" @enderror
                       class="wt-input h-11 text-[15px] uppercase tracking-[.06em] placeholder:normal-case placeholder:tracking-normal @error('kode') is-invalid @enderror">
                @error('kode')
                    <span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>
                @enderror
            </label>
            <label class="block">
                <span class="wt-field-label mb-1.5 block">Nomor WhatsApp pemesan</span>
                <input type="text" name="kunci" value="{{ old('kunci') }}" placeholder="08xxxxxxxxxx" autocomplete="tel" inputmode="text" maxlength="30"
                       spellcheck="false" @error('kunci') aria-invalid="true" @enderror
                       class="wt-input h-11 text-[15px] @error('kunci') is-invalid @enderror">
                @error('kunci')
                    <span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>
                @enderror
            </label>
            <button class="h-12 rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600">Cari pesanan</button>
        </form>
    </section>

    <p class="mt-8 text-sm text-ink-500">
        @auth
            Semua pesanan akun Anda ada di <a href="{{ route('pesanan') }}" class="font-semibold">Pesanan saya</a>.
        @else
            Punya akun? <a href="{{ route('login') }}" @click.prevent="$dispatch('buka-akun', { mode: 'masuk', kembali: @js(route('pesanan')) })" class="font-semibold">Masuk</a> untuk melihat semua pesanan Anda dari perangkat mana pun.
        @endauth
        Kehilangan kode? Hubungi resepsionis {{ $wisma['telepon'] ?? '' }}.
    </p>
</div>
</x-layouts.guest>
