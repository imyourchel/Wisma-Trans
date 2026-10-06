{{-- Panel susunan tamu per kamar (dewasa dan anak), dipakai di dalam x-data cariKamar. $panel mengatur posisi panel. --}}
@props(['panel' => 'inset-x-0 top-[92px] sm:left-3 sm:right-auto sm:w-[340px]'])

<div x-cloak x-show="buka" x-transition.opacity.duration.150ms @keydown.escape.window="buka = false"
     {{ $attributes->class(['absolute z-[60] flex max-h-[min(560px,75vh)] max-w-[calc(100vw-32px)] flex-col rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg', $panel]) }}>
    <div class="flex flex-col gap-3 overflow-y-auto p-5 pb-3">
        <div class="text-[13px] text-ink-500" x-text="'Maksimal ' + maksTamu + ' orang per kamar, dewasa dan anak dihitung.'"></div>
        <template x-for="(k, i) in kamar" :key="i">
            <div class="flex flex-col gap-3 rounded border border-cream-300 p-4">
                <div class="flex items-center justify-between gap-2">
                    <span class="whitespace-nowrap font-display text-sm font-bold uppercase tracking-[.04em]" x-text="'Kamar ' + (i + 1)"></span>
                    <button type="button" x-show="kamar.length > 1" @click="kamar.splice(i, 1)" title="Hapus kamar" :aria-label="'Hapus kamar ' + (i + 1)"
                            class="flex h-7 w-7 items-center justify-center rounded-md border border-cream-300 bg-cream-50 hover:bg-cream-200"><img src="{{ asset('ds/icons/x-olive.svg') }}" alt="" width="12" height="12"></button>
                </div>
                @foreach (['dewasa' => ['Dewasa', null], 'anak' => ['Anak', 'di bawah 8 tahun']] as $jenis => [$label, $ket])
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm">{{ $label }}@if ($ket)<span class="block text-xs text-ink-500">{{ $ket }}</span>@endif</span>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="ubahTamu(i, '{{ $jenis }}', -1)" :disabled="{{ $jenis === 'dewasa' ? 'k.dewasa <= 1' : 'k.anak <= 0' }}" aria-label="Kurangi {{ Str::lower($label) }}"
                                    class="h-9 w-9 rounded border border-cream-300 bg-cream-50 text-[17px] text-brown-800 hover:bg-cream-200 disabled:opacity-30">−</button>
                            <span class="min-w-6 text-center font-display font-bold tabular-nums" x-text="k.{{ $jenis }}" aria-live="polite"></span>
                            <button type="button" @click="ubahTamu(i, '{{ $jenis }}', 1)" :disabled="k.dewasa + k.anak >= maksTamu" aria-label="Tambah {{ Str::lower($label) }}"
                                    class="h-9 w-9 rounded border border-cream-300 bg-cream-50 text-[17px] text-brown-800 hover:bg-cream-200 disabled:opacity-30">+</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </template>
        <button type="button" @click="tambahKamar()" x-show="kamar.length < {{ \App\Support\PencarianInap::MAKS_KAMAR }}"
                class="h-11 flex-none rounded border border-dashed border-cream-300 bg-cream-50 font-display text-[13px] font-semibold text-brown-800 hover:bg-cream-200">Tambah kamar</button>
    </div>
    <div class="flex flex-none items-center justify-between gap-3 border-t border-cream-300 px-5 py-3.5">
        <span class="max-w-[160px] text-xs text-ink-500">Anak di bawah 8 tahun tidak dikenakan biaya.</span>
        <button type="button" @click="terapkanTamu()" class="h-10 rounded bg-clay-500 px-5 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600">Terapkan</button>
    </div>
</div>
