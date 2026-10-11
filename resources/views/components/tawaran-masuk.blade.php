@props([
    'kembali' => null,   // halaman yang dituju setelah masuk; kosong = halaman ini (termasuk pilihan di alamatnya)
    'manfaat' => 'data pemesan terisi otomatis dan pesanan tersimpan di Pesanan saya',
])
{{-- Tawaran masuk sebelum tamu mengisi data pemesan sendiri. Modal masuk/daftar mengembalikan tamu ke halaman ini. --}}
@guest
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-4 gap-y-3 rounded-[10px] border border-cream-300 bg-cream-100 px-4 py-3.5']) }}>
        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-cream-50 text-clay-600" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        </span>
        <p class="m-0 min-w-[200px] flex-1 text-[13px] leading-snug text-ink-500">
            <span class="font-semibold text-ink-900">Sudah punya akun?</span> Masuk dulu supaya {{ $manfaat }}.
        </p>
        <div class="flex flex-none gap-2">
            <button type="button" @click="$dispatch('buka-akun', { mode: 'masuk', kembali: @js($kembali) })"
                    class="h-9 rounded-md border-0 bg-brown-800 px-4 text-[13px] font-semibold text-cream-50 hover:bg-brown-900">Masuk</button>
            <button type="button" @click="$dispatch('buka-akun', { mode: 'daftar', kembali: @js($kembali) })"
                    class="h-9 rounded-md border border-cream-300 bg-cream-50 px-4 text-[13px] font-semibold text-brown-800 hover:bg-cream-200">Daftar</button>
        </div>
    </div>
@endguest
