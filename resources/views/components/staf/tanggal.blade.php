@props([
    'name' => null,      // nama isian untuk formulir GET/POST biasa
    'nilai' => null,     // nilai awal Y-m-d
    'min' => null,       // tanggal paling awal yang boleh dipilih (Y-m-d)
    'max' => null,       // tanggal paling akhir yang boleh dipilih (Y-m-d)
    'kosong' => false,   // boleh dikosongkan (penyaring): tampil "Semua tanggal" dan tombol Hapus
    'placeholder' => 'Pilih tanggal',
    'label' => null,     // label aksesibilitas
    'kirim' => false,    // kirim formulir induk setiap tanggal berubah (penyaring GET)
])
{{--
    Pemilih tanggal panel staf (pengganti input type=date yang tampil mm/dd/yyyy berbahasa Inggris di Chrome).
    Bahasa Indonesia, minggu mulai Senin, tanggal di luar min/max tidak bisa dipilih. Untuk Livewire teruskan
    wire:model / wire:model.live ke komponen ini; nilainya dikirim lewat isian tersembunyi.
--}}
@php $wire = $attributes->whereStartsWith('wire:model'); @endphp
<div x-data="isianTanggal(@js(['nilai' => (string) $nilai, 'min' => $min, 'max' => $max, 'hariIni' => today()->toDateString(), 'kirim' => $kirim]))"
     wire:key="isian-tanggal-{{ $name ?? $wire->first() }}-{{ $nilai }}"
     @click.outside="buka = false" @keydown.escape="buka && (buka = false, $refs.tombol.focus(), $event.stopPropagation())"
     {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'relative']) }}>
    <input type="hidden" x-ref="isian" @if ($name) name="{{ $name }}" @endif value="{{ $nilai }}" {{ $wire }}>
    <button type="button" x-ref="tombol" @click="buka ? (buka = false) : bukaPanel()" @keydown.arrow-down.prevent="bukaPanel()"
            aria-haspopup="dialog" :aria-expanded="buka" @if ($label) aria-label="{{ $label }}" @endif
            class="wt-input flex w-full items-center gap-2.5 pr-3 text-left"
            :class="buka && '!border-clay-500 shadow-[0_0_0_3px_rgba(181,113,74,.16)]'">
        <x-staf.ikon nama="kalender" ukuran="16" class="flex-none text-clay-600" />
        <span class="min-w-0 flex-1 truncate" :class="!nilai && 'text-ink-500'" x-text="tampil || @js($kosong ? 'Semua tanggal' : $placeholder)"></span>
        <svg class="flex-none text-ink-500 transition-transform duration-200" :class="buka && 'rotate-180'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div x-show="buka" x-cloak role="dialog" aria-label="Pilih tanggal"
         x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute left-0 top-[calc(100%+6px)] z-[360] w-[296px] rounded-lg border border-cream-300 bg-cream-50 p-3.5 shadow-wt-lg">
        <div class="mb-2.5 flex items-center gap-2">
            <button type="button" @click="geser(-1)" :disabled="!bolehGeser(-1)" aria-label="Bulan sebelumnya"
                    class="flex h-8 w-8 items-center justify-center rounded-md border-0 bg-transparent text-brown-800 hover:bg-cream-200 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-transparent">
                <x-staf.ikon nama="panah" ukuran="16" class="rotate-90" />
            </button>
            <div class="flex-1 text-center font-display text-sm font-bold uppercase tracking-[.08em] text-ink-900" x-text="judul" aria-live="polite"></div>
            <button type="button" @click="geser(1)" :disabled="!bolehGeser(1)" aria-label="Bulan berikutnya"
                    class="flex h-8 w-8 items-center justify-center rounded-md border-0 bg-transparent text-brown-800 hover:bg-cream-200 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-transparent">
                <x-staf.ikon nama="panah" ukuran="16" class="-rotate-90" />
            </button>
        </div>

        <div class="grid grid-cols-7 pb-1">
            @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $h)
                <div @class(['py-1 text-center font-display text-[10px] font-semibold uppercase tracking-[.1em]', 'text-clay-600' => $h === 'Min', 'text-ink-500' => $h !== 'Min'])>{{ $h }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-7 gap-y-1">
            <template x-for="c in sel" :key="c.t">
                <button type="button" @click="pilih(c.t)" :disabled="c.tutup" :aria-label="c.label" :aria-pressed="c.t === nilai"
                        class="relative h-9 rounded-md border-0 p-0 text-[13px] tabular-nums transition-colors disabled:cursor-default"
                        :class="c.t === nilai ? 'bg-brown-800 font-bold text-cream-50'
                            : (c.tutup ? 'bg-transparent text-ink-500/35' : (c.luar ? 'bg-transparent text-ink-500/60 hover:bg-cream-200' : 'bg-transparent text-ink-900 hover:bg-cream-200'))">
                    <span x-text="c.n"></span>
                    <span x-show="c.hariIni" class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full" :class="c.t === nilai ? 'bg-sand-300' : 'bg-clay-500'"></span>
                </button>
            </template>
        </div>

        <div class="mt-3 flex items-center justify-between gap-2 border-t border-cream-300 pt-3">
            @if ($kosong)
                <button type="button" @click="pilih('')" x-show="nilai" class="h-8 rounded-md border-0 bg-transparent px-2 text-xs font-semibold text-ink-500 hover:bg-cream-200">Hapus</button>
            @endif
            <span class="text-[11px] text-ink-500" x-show="batas" x-text="batas"></span>
            <button type="button" @click="pilih(hariIni)" :disabled="tertutup(hariIni)"
                    class="ml-auto h-8 flex-none rounded-md border border-cream-300 bg-transparent px-3 text-xs font-semibold text-brown-800 hover:bg-cream-200 disabled:opacity-40">Hari ini</button>
        </div>
    </div>
</div>
