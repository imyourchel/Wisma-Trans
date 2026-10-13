@props([
    'name' => null,     // nama isian untuk formulir (input tersembunyi, nilai HH:MM)
    'nilai' => null,    // nilai awal, misalnya 14:00 atau 14:00:00
    'model' => null,    // ekspresi Alpine untuk x-model, misalnya isi.check_in_time
    'langkah' => 5,     // selang menit yang ditawarkan
    'label' => null,    // label aksesibilitas
    'galat' => null,    // ekspresi Alpine: benar bila isian bergalat
])
{{-- Pemilih jam 24 jam panel staf (pengganti input type=time yang tampil 12 jam AM/PM di sebagian browser). Ditulis 14.00, dikirim 14:00. --}}
<div x-data="pilihJam(@js(['nilai' => (string) $nilai, 'langkah' => (int) $langkah]))"
     @if ($model) x-modelable="nilai" x-model="{{ $model }}" @endif
     @click.outside="buka = false" @keydown.escape="buka && (buka = false, $refs.tombol.focus(), $event.stopPropagation())"
     {{ $attributes->merge(['class' => 'relative']) }}>
    @if ($name)
        <input type="hidden" name="{{ $name }}" :value="nilai">
    @endif
    <button type="button" x-ref="tombol" @click="buka ? (buka = false) : bukaPanel()" @keydown.arrow-down.prevent="bukaPanel()"
            aria-haspopup="dialog" :aria-expanded="buka" @if ($label) aria-label="{{ $label }}" @endif
            class="wt-input flex w-full items-center gap-2.5 pr-3 text-left tabular-nums"
            :class="[buka && '!border-clay-500 shadow-[0_0_0_3px_rgba(181,113,74,.16)]', {{ $galat ?: 'false' }} && 'is-invalid']">
        <svg class="flex-none text-ink-500" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <span class="flex-1 font-semibold" :class="!nilai && 'font-normal text-ink-500'" x-text="tampil || '--.--'"></span>
    </button>

    <div x-show="buka" x-cloak role="dialog" aria-label="Pilih jam"
         x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition duration-100 ease-wt" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute left-0 top-[calc(100%+6px)] z-[360] w-[216px] overflow-hidden rounded-lg border border-cream-300 bg-cream-50 shadow-wt-lg">
        <div class="grid grid-cols-2 border-b border-cream-300 bg-cream-100 text-center text-[11px] font-semibold uppercase tracking-widest text-ink-500">
            <span class="py-2">Jam</span><span class="border-l border-cream-300 py-2">Menit</span>
        </div>
        <div class="grid grid-cols-2">
            <div x-ref="jam" class="max-h-56 overflow-y-auto p-1">
                <template x-for="j in daftarJam" :key="j">
                    <button type="button" @click="pilihJamKe(j)" :data-pilih="j === jam"
                            class="block w-full rounded-md border-0 py-1.5 text-center text-sm tabular-nums"
                            :class="j === jam ? 'bg-brown-800 font-semibold text-cream-50' : 'bg-transparent text-ink-900 hover:bg-cream-200'" x-text="j"></button>
                </template>
            </div>
            <div x-ref="menit" class="max-h-56 overflow-y-auto border-l border-cream-300 p-1">
                <template x-for="m in daftarMenit" :key="m">
                    <button type="button" @click="pilihMenit(m)" :data-pilih="m === menit"
                            class="block w-full rounded-md border-0 py-1.5 text-center text-sm tabular-nums"
                            :class="m === menit ? 'bg-brown-800 font-semibold text-cream-50' : 'bg-transparent text-ink-900 hover:bg-cream-200'" x-text="m"></button>
                </template>
            </div>
        </div>
        <div class="border-t border-cream-300 px-3 py-2 text-center text-[11px] text-ink-500">Format 24 jam, WITA</div>
    </div>
</div>
