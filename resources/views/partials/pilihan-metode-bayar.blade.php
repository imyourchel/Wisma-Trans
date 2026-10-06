{{-- Satu baris pilihan metode bayar di halaman pesanan; dipakai di dalam x-for "m" pada komponen x-bayar-online --}}
<button type="button" @click="pilih(m.kunci)" :disabled="!!memuat" :aria-busy="memuat === m.kunci"
        class="group flex w-full items-center gap-3.5 rounded-lg border border-cream-300 bg-cream-50 px-4 py-3 text-left transition hover:border-clay-500 hover:bg-white disabled:cursor-wait"
        :class="memuat && memuat !== m.kunci ? 'opacity-50' : ''">
    <span class="flex h-10 w-[72px] flex-none items-center justify-center rounded-md border border-cream-300 bg-white px-2">
        <img :src="logo(m.kunci)" alt="" class="max-h-6 max-w-full object-contain">
    </span>
    <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-ink-900" x-text="m.nama"></span>
        <span class="block truncate text-xs text-ink-500" x-text="m.ket"></span>
    </span>
    <span x-show="memuat === m.kunci" class="h-4 w-4 flex-none animate-spin rounded-full border-2 border-cream-300 border-t-clay-500" aria-hidden="true"></span>
    <svg x-show="memuat !== m.kunci" class="flex-none text-ink-500 transition group-hover:translate-x-0.5 group-hover:text-clay-600" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
</button>
