@props([
    'pilihan' => [],      // [nilai => label] atau [nilai => ['label' => ..., 'warna' => '#hex']]
    'name' => null,       // nama isian untuk formulir (input tersembunyi)
    'nilai' => null,      // nilai awal
    'model' => null,      // ekspresi Alpine untuk x-model, misalnya isi.room_type_id
    'kirim' => false,     // kirim formulir induk setiap pilihan berubah (saringan)
    'label' => null,      // label aksesibilitas
    'galat' => null,      // ekspresi Alpine: benar bila isian bergalat
    'sumber' => null,     // ekspresi Alpine berisi [{v, l}] bila pilihan berubah-ubah (misalnya unit mengikuti kamar terpilih)
    'nonaktif' => null,   // ekspresi Alpine: benar bila dropdown tidak bisa dipakai
])
@php
    $daftar = collect($pilihan)->map(fn ($isi, $v) => [
        'v' => (string) $v,
        'l' => is_array($isi) ? $isi['label'] : $isi,
        'w' => is_array($isi) ? ($isi['warna'] ?? null) : null,
    ])->values();
@endphp
{{-- Dropdown panel staf: pengganti <select> dengan daftar pilihan yang bisa dihias dan dipakai dengan keyboard --}}
<div x-data="pilihan(@js(['pilihan' => $daftar, 'nilai' => (string) $nilai, 'kirim' => $kirim]))"
     @if ($model) x-modelable="nilai" x-model="{{ $model }}" @endif
     @if ($sumber) x-effect="pilihan = ({{ $sumber }})" @endif
     @click.outside="tutup()" @keydown.escape="buka && (tutup(), $event.stopPropagation())"
     {{ $attributes->merge(['class' => 'wt-isian-lebar relative']) }}>
    @if ($name)
        <input type="hidden" name="{{ $name }}" :value="nilai">
    @endif
    <button type="button" x-ref="tombol" @click="buka ? tutup() : bukaDaftar()" @if ($nonaktif) :disabled="{{ $nonaktif }}" @endif
            @keydown.arrow-down.prevent="gerak(1)" @keydown.arrow-up.prevent="gerak(-1)"
            @keydown.enter.prevent="buka && tampil[sorot] ? pilih(tampil[sorot].v) : bukaDaftar()"
            aria-haspopup="listbox" :aria-expanded="buka" @if ($label) aria-label="{{ $label }}" @endif
            class="wt-input flex w-full items-center gap-2.5 pr-3 text-left disabled:cursor-not-allowed disabled:bg-cream-200 disabled:text-ink-500"
            :class="[buka && '!border-clay-500 shadow-[0_0_0_3px_rgba(181,113,74,.16)]', {{ $galat ?: 'false' }} && 'is-invalid']">
        <span x-show="terpilih?.w" class="h-2.5 w-2.5 flex-none rounded-full" :style="{ background: terpilih?.w }"></span>
        <span class="min-w-0 flex-1 truncate" x-text="terpilih?.l ?? ''"></span>
        <svg class="flex-none text-ink-500 transition-transform duration-200" :class="buka && 'rotate-180'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div x-show="buka" x-cloak
         x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition duration-100 ease-wt" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute left-0 top-[calc(100%+6px)] z-[360] min-w-full overflow-hidden rounded-lg border border-cream-300 bg-cream-50 shadow-wt-lg">
        <div x-show="pilihan.length > 8" class="border-b border-cream-300 p-2">
            <input type="search" x-ref="cari" x-model="kata" @input.stop @change.stop @keydown.arrow-down.prevent="gerak(1)" @keydown.arrow-up.prevent="gerak(-1)"
                   @keydown.enter.prevent="tampil[sorot] && pilih(tampil[sorot].v)" placeholder="Cari…" aria-label="Cari pilihan"
                   class="h-9 w-full rounded-md border border-cream-300 bg-cream-100 px-3 text-sm focus:border-clay-500">
        </div>
        <ul x-ref="daftar" role="listbox" class="m-0 max-h-72 list-none overflow-y-auto p-1">
            <template x-for="(p, i) in tampil" :key="p.v">
                <li role="option" :aria-selected="p.v === String(nilai)" @click="pilih(p.v)" @mousemove="sorot = i"
                    class="flex cursor-pointer items-center gap-2.5 whitespace-nowrap rounded-md px-2 py-2 text-left text-sm"
                    :class="[sorot === i && 'bg-cream-200', p.v === String(nilai) ? 'font-semibold text-brown-800' : 'text-ink-900']">
                    <span x-show="p.w" class="h-2.5 w-2.5 flex-none rounded-full" :style="{ background: p.w }"></span>
                    <span class="flex-1" x-text="p.l"></span>
                    <svg x-show="p.v === String(nilai)" class="flex-none text-clay-500" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                </li>
            </template>
            <li x-show="!tampil.length" class="px-2 py-2 text-left text-sm text-ink-500">Tidak ada yang cocok.</li>
        </ul>
    </div>
</div>
