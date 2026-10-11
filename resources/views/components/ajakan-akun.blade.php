@props(['hp' => null])
{{-- Setelah memesan tanpa akun: ajakan daftar atau masuk dengan nomor WhatsApp pemesan. Pesanan dengan nomor yang sama
     otomatis tampil di Pesanan saya (Support\PesananAkun). Tidak tampil untuk pengguna yang sudah masuk. --}}
@guest
    <div x-data {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-[10px] border border-cream-300 bg-cream-100 px-4 py-3.5']) }}>
        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-cream-50 text-clay-600" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        </span>
        <p class="m-0 min-w-0 flex-1 text-[13px] leading-relaxed text-ink-500">
            <span class="font-semibold text-ink-900">Ingin memantau pesanan ini dari akun?</span>
            <button type="button" @click="$dispatch('buka-akun', { mode: 'daftar', hp: @js($hp) })" class="border-0 bg-transparent p-0 font-semibold text-clay-600 hover:underline">Daftar</button>
            atau
            <button type="button" @click="$dispatch('buka-akun', { mode: 'masuk', hp: @js($hp) })" class="border-0 bg-transparent p-0 font-semibold text-clay-600 hover:underline">Masuk</button>
            dengan nomor WhatsApp <span class="font-semibold tabular-nums text-ink-900">{{ $hp }}</span>. Pesanan ini otomatis tampil di Pesanan saya.
        </p>
    </div>
@endguest
