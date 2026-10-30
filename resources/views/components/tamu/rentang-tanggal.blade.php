@props(['panel' => 'left-0', 'ringkas' => false, 'pilihanCepat' => [[1, '1 malam'], [2, '2 malam'], [3, '3 malam'], [7, '1 minggu']]])
{{-- ringkas: tanpa tahun dan nama hari pendek, untuk kotak sempit. pilihanCepat: [[malam, label], ...] lama menginap sekali klik.
     Batas malam diatur lewat wtRentangInap({ maksMalam, pesanMaks }) milik induknya. --}}
@once
    @push('scripts')
        @include('partials.tamu-rentang-skrip')
    @endpush
@endonce
{{-- Rentang tanggal: satu kotak check-in → check-out, kalender dua bulan (satu bulan di ponsel).
     Memakai state Alpine dari wtRentangInap() milik induknya (lihat partials/tamu-rentang-skrip). --}}
<div {{ $attributes->merge(['class' => 'relative min-w-0 p-1.5']) }} @click.outside="tutupKalender()" @keydown.escape="tutupKalender()">
    <div class="grid h-full grid-cols-[1fr_auto_1fr] items-stretch gap-1">
        @foreach ([['masuk', 'Check-in'], ['keluar', 'Check-out']] as [$ujung, $label])
            @if ($ujung === 'keluar')
                <div class="flex flex-col items-center justify-center gap-1.5 px-0.5 sm:px-1" aria-hidden="true">
                    <span class="h-px w-6 bg-cream-400"></span>
                    <span class="whitespace-nowrap rounded-full bg-cream-200 px-2 py-1 text-[10px] font-bold tabular-nums text-brown-800 sm:px-2.5 sm:text-[11px]" x-text="keluar ? malam + ' malam' : '—'"></span>
                    <span class="h-px w-6 bg-cream-400"></span>
                </div>
            @endif
            <button type="button" @click="bukaKalender('{{ $ujung }}')" :aria-expanded="kalender"
                    class="flex min-w-0 flex-col items-start justify-center rounded-md border border-transparent px-2.5 py-2.5 text-left transition hover:bg-cream-200 sm:px-4"
                    :class="kalender && tahap === '{{ $ujung }}' && '!border-clay-500 bg-cream-200'">
                <span class="wt-micro-label">{{ $label }}</span>
                {{-- Mode ringkas: nama hari di bawah tanggal agar tanggal tidak terpotong di kotak sempit --}}
                <span @class(['mt-1 flex max-w-full overflow-hidden', 'flex-col items-start gap-0.5' => $ringkas, 'items-baseline gap-1.5' => ! $ringkas])>
                    <span class="{{ $ringkas ? 'whitespace-nowrap' : 'truncate' }} font-display text-[15px] font-bold tabular-nums text-ink-900 sm:text-[17px]" x-text="{{ $ujung }} ? teksTgl({{ $ujung }}, @js($ringkas)) : 'Pilih tanggal'"></span>
                    <span x-show="{{ $ujung }}" class="flex-none whitespace-nowrap text-[13px] text-ink-500" x-text="{{ $ujung }} ? (lebar < 640 || @js($ringkas) ? hariPendek({{ $ujung }}) : namaHari({{ $ujung }})) : ''"></span>
                </span>
            </button>
        @endforeach
    </div>
    <input type="hidden" name="masuk" :value="masuk">
    <input type="hidden" name="keluar" :value="keluar">

    <div x-show="kalender" x-cloak
         x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute {{ $panel }} top-[calc(100%+8px)] z-[60] w-[660px] max-w-[calc(100vw-48px)] rounded-xl border border-cream-300 bg-cream-50 p-4 shadow-wt-lg sm:p-5"
         role="dialog" aria-label="Pilih tanggal menginap">
        <div class="mb-4 flex items-center justify-between gap-3">
            <button type="button" @click="geser(-1)" :disabled="!bisaMundur" aria-label="Bulan sebelumnya"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-cream-300 bg-cream-50 text-brown-800 hover:bg-cream-200 disabled:cursor-not-allowed disabled:opacity-30">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <div class="text-center text-[13px] text-ink-500" x-text="petunjuk"></div>
            <button type="button" @click="geser(1)" :disabled="!bisaMaju" aria-label="Bulan berikutnya"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-cream-300 bg-cream-50 text-brown-800 hover:bg-cream-200 disabled:cursor-not-allowed disabled:opacity-30">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>

        <div class="grid gap-8" :class="bulanTampil.length > 1 && 'grid-cols-2'" @mouseleave="arah = null">
            <template x-for="b in bulanTampil" :key="b.kunci">
                <div>
                    <div class="mb-3 text-center font-display text-[13px] font-bold uppercase tracking-[.1em] text-ink-900" x-text="b.judul"></div>
                    <div class="grid grid-cols-7 pb-1 text-center text-[11px] font-semibold uppercase tracking-[.04em] text-ink-500">
                        <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                    </div>
                    <div class="grid grid-cols-7 gap-y-1">
                        <template x-for="h in b.hari" :key="h.kunci">
                            <div class="relative flex h-10 items-center justify-center">
                                <span x-show="h.pita" class="absolute inset-y-0.5 bg-cream-200" :class="h.pita"></span>
                                <template x-if="h.iso">
                                    <button type="button" :disabled="h.lalu" @click="pilih(h.iso)" @mouseenter="arah = h.iso"
                                            :aria-label="labelHari(h.iso)" :aria-pressed="h.ujung"
                                            class="relative z-[1] flex h-10 w-10 items-center justify-center rounded-full border-0 text-sm tabular-nums"
                                            :class="h.lalu ? 'cursor-not-allowed bg-transparent text-ink-500/35 line-through'
                                                : h.ujung ? 'bg-brown-800 font-semibold text-cream-50 shadow-wt-xs'
                                                : h.dalam ? 'bg-transparent text-brown-800 hover:bg-cream-300'
                                                : 'bg-transparent text-ink-900 hover:bg-cream-200'">
                                        <span x-text="h.tgl"></span>
                                        <span x-show="h.hariIni && !h.ujung" class="absolute bottom-1 h-1 w-1 rounded-full bg-clay-500"></span>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-cream-300 pt-4">
            <span class="mr-1 text-xs text-ink-500">Lama menginap</span>
            <template x-for="[n, label] in @js($pilihanCepat)" :key="n">
                <button type="button" @click="setDurasi(n)"
                        class="h-8 rounded-full border px-3 text-xs transition"
                        :class="keluar && malam === n ? 'border-brown-800 bg-brown-800 text-cream-50' : 'border-cream-300 bg-cream-50 text-brown-800 hover:bg-cream-200'"
                        x-text="label"></button>
            </template>
            <button type="button" @click="tutupKalender()" :disabled="!keluar"
                    class="ml-auto h-9 rounded bg-clay-500 px-5 font-display text-xs font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-40">Selesai</button>
        </div>
    </div>
</div>
