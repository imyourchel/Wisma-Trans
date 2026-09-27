<x-layouts.guest title="Halaman tidak ditemukan">
    <section class="relative isolate overflow-hidden bg-cream-100">
        {{-- Angka besar samar di belakang sebagai latar --}}
        <div class="pointer-events-none absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 select-none text-center font-display text-[42vw] font-extrabold leading-none tracking-[-.04em] text-[rgba(181,113,74,.06)] sm:text-[300px] lg:text-[380px]" aria-hidden="true">404</div>

        <div class="wt-container flex min-h-[calc(100vh-72px-220px)] flex-col items-center justify-center py-20 text-center sm:py-28">
            <div class="flex items-center gap-3 font-display text-[11px] font-semibold uppercase tracking-[.32em] text-clay-500">
                <span class="h-px w-8 bg-clay-500/50"></span>Error 404<span class="h-px w-8 bg-clay-500/50"></span>
            </div>
            <h1 class="mb-0 mt-5 max-w-[640px] font-display text-[32px] font-extrabold uppercase leading-[1.1] tracking-[.03em] sm:text-[44px]">
                Halaman tidak ditemukan
            </h1>
            <p class="mb-0 mt-4 max-w-[480px] text-[15px] leading-relaxed text-ink-500">
                Maaf, halaman yang Anda cari sudah dipindahkan, dihapus, atau alamatnya kurang tepat.
                Silakan kembali ke beranda atau hubungi kami bila butuh bantuan.
            </p>

            <div class="mt-8 flex w-full flex-wrap justify-center gap-3">
                <a href="{{ route('home') }}"
                   class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 transition-colors hover:bg-clay-600 hover:text-cream-50 hover:no-underline sm:w-auto">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                    Kembali ke beranda
                </a>
                <a href="https://wa.me/{{ $wisma['whatsapp'] }}?text={{ rawurlencode('Halo Wisma Trans, saya butuh bantuan.') }}" target="_blank" rel="noopener"
                   class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm border border-cream-400 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-brown-800 transition-colors hover:bg-cream-200 hover:text-brown-800 hover:no-underline sm:w-auto">
                    <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 opacity-75">
                    Hubungi kami
                </a>
            </div>

        </div>
    </section>
</x-layouts.guest>
