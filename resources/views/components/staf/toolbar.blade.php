@props(['tambah' => null, 'cari' => 'Cari data'])
{{-- Pencarian, jumlah data, dan tombol tambah di atas tabel data master --}}
<div class="mb-4 flex flex-wrap items-center gap-3">
    <label class="relative block min-w-0 flex-[1_1_240px] sm:max-w-[360px]">
        <span class="sr-only">{{ $cari }}</span>
        <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
        <input type="search" x-model="cari" placeholder="{{ $cari }}" class="wt-input pl-9">
    </label>
    <span class="whitespace-nowrap text-[13px] text-ink-500" x-text="jumlah + ' data'"></span>
    {{ $slot }}
    @if ($tambah)
        <button type="button" @click="tambah()"
                class="ml-auto inline-flex h-10 items-center gap-2 whitespace-nowrap rounded border-0 bg-clay-500 px-4 font-display text-[13px] font-semibold text-cream-50 hover:bg-clay-600">
            <x-staf.ikon nama="tambah" ukuran="16" />{{ $tambah }}
        </button>
    @endif
</div>
