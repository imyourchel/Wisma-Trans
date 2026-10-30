{{-- Bar ringkasan pilihan kamar (menempel di bawah layar) dan rincian pembagian tamu di atasnya. Dipakai /kamar dan detail tipe.
     $r = PilihKamarTamu::ringkasanPilihan(); $urlKamar = tautan "Lihat kamar lain" (halaman detail), null di /kamar. --}}
@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $st = $r['status'];
@endphp
@if ($r['jumlah'] > 0)
    <div x-data="{ rincian: false, destroy() { document.documentElement.classList.remove('overflow-hidden') } }"
         x-effect="document.documentElement.classList.toggle('overflow-hidden', rincian && window.innerWidth < 768)"
         @keydown.escape.window="rincian = false" data-bar-pilihan>
        {{-- Latar halaman diredupkan saat rincian terbuka --}}
        <div x-show="rincian" x-cloak x-transition.opacity.duration.200ms @click="rincian = false" class="fixed inset-0 z-[70] bg-[rgba(40,27,18,.45)]" aria-hidden="true"></div>

        <div class="fixed inset-x-0 bottom-0 z-[71] flex flex-col" :class="rincian && 'max-md:top-0'">
            {{-- Rincian pilihan (bottom sheet; layar penuh di HP) --}}
            <section x-show="rincian" x-cloak id="rincian-pilihan" role="dialog" aria-modal="true" aria-labelledby="judul-rincian"
                     x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                     x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="translate-y-6 opacity-0"
                     class="min-h-0 overflow-y-auto overscroll-contain bg-cream-50 shadow-[0_-24px_48px_-24px_rgba(40,27,18,.5)] max-md:flex-1 md:max-h-[min(70vh,620px)] md:rounded-t-2xl" data-rincian-pilihan>
                <div class="wt-container max-w-[1188px] py-5 md:py-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="font-display text-[11px] font-semibold uppercase tracking-[.22em] text-clay-600">Pilihan Anda</div>
                            <h2 id="judul-rincian" class="mb-0 mt-1 font-display text-lg font-extrabold uppercase tracking-[.03em] sm:text-[22px]">{{ $r['jumlah'] }} kamar · {{ max(1, $r['malam']) }} malam</h2>
                        </div>
                        <span class="hidden pt-5 text-[13px] text-ink-500 md:block">Tamu dibagi otomatis sesuai kapasitas kamar</span>
                        <button type="button" @click="rincian = false" class="flex h-10 w-10 flex-none items-center justify-center rounded-full border border-cream-300 bg-transparent text-brown-800 md:hidden" aria-label="Tutup rincian">
                            <x-tamu.ikon nama="tutup" ukuran="16" />
                        </button>
                    </div>
                    <p class="mb-0 mt-1 text-[13px] text-ink-500 md:hidden">Tamu dibagi otomatis sesuai kapasitas kamar.</p>

                    <ul class="m-0 mt-4 list-none divide-y divide-cream-300 border-y border-cream-300 p-0">
                        @foreach ($r['kamar'] as $i => $k)
                            <li wire:key="rincian-{{ $i }}-{{ $k['tipe']->id }}-{{ $k['kasur'] }}" data-kamar-rincian="{{ $i }}"
                                class="grid grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-3 py-3.5 md:grid-cols-[72px_minmax(0,1fr)_220px_120px_32px]">
                                @if ($k['foto'])
                                    <img src="{{ $k['foto'] }}" alt="" loading="lazy" class="h-[44px] w-[56px] rounded object-cover md:h-[54px] md:w-[72px]">
                                @else
                                    <span class="flex h-[44px] w-[56px] items-center justify-center rounded bg-cream-200 text-clay-600 md:h-[54px] md:w-[72px]" aria-hidden="true"><x-tamu.ikon nama="kasur" ukuran="20" /></span>
                                @endif
                                <div class="min-w-0">
                                    <div class="text-[15px] font-semibold leading-snug text-ink-900 md:truncate">{{ $k['judul'] }}</div>
                                    <div class="text-[13px] leading-snug text-ink-500 md:truncate">{{ $k['maks'] }}</div>
                                </div>
                                <button type="button" wire:click="kurangiKamar({{ $i }})" class="flex h-8 w-8 items-center justify-center justify-self-end rounded-full border-0 bg-transparent text-ink-500 hover:bg-cream-200 hover:text-status-dirty md:order-5"
                                        aria-label="Hapus satu {{ $k['judul'] }}">
                                    <x-tamu.ikon nama="tutup" ukuran="15" />
                                </button>
                                {{-- Pembagian tamu otomatis, hanya info --}}
                                <div @class(['col-span-2 flex items-center gap-2 text-sm md:order-3 md:col-span-1',
                                    'font-semibold text-status-dirty' => $k['bagi']['dewasa'] === 0, 'text-ink-900' => $k['bagi']['dewasa'] > 0]) data-bagi="{{ $k['nilai'] }}">
                                    <x-tamu.ikon nama="orang" ukuran="16" class="flex-none text-clay-600" />{{ $k['teksBagi'] }}
                                </div>
                                <div class="whitespace-nowrap text-right font-display text-[17px] font-extrabold tabular-nums text-clay-600 md:order-4">{{ $rp($k['harga']) }}</div>
                            </li>
                        @endforeach
                    </ul>

                    <div @class(['mt-4 flex items-start gap-2.5 rounded-md px-4 py-3 text-[13px]',
                        'bg-cream-200 text-ink-900' => $st['lengkap'], 'border border-[rgba(232,172,69,.5)] bg-[rgba(232,172,69,.12)] text-[#7A4F08]' => ! $st['lengkap']])
                         role="status" data-status-bagi="{{ $st['lengkap'] ? 'lengkap' : 'kurang' }}">
                        @if ($st['lengkap'])
                            <x-tamu.ikon nama="cek" ukuran="15" class="mt-px flex-none text-status-available" />
                        @else
                            <x-tamu.ikon nama="info" ukuran="15" class="mt-px flex-none" />
                        @endif
                        <span>{{ $st['pesan'] }}</span>
                    </div>
                    @if (collect($r['kamar'])->contains(fn ($k) => $k['extra'] > 0))
                        <p class="mb-0 mt-2 text-xs text-ink-500">Extra bed untuk kamar yang membutuhkannya ditambahkan di langkah berikutnya.</p>
                    @endif
                </div>
            </section>

            {{-- Bar ringkasan --}}
            <div class="bg-brown-900 pb-[max(12px,env(safe-area-inset-bottom))] text-cream-50 shadow-[0_-12px_32px_-12px_rgba(40,27,18,.55)]">
                <div class="wt-container flex max-w-[1188px] flex-wrap items-center gap-x-5 gap-y-3 pt-3 md:flex-nowrap md:py-3.5">
                    <div class="min-w-0 flex-1 md:flex-none">
                        <div class="text-xs text-[rgba(242,235,220,.75)]" data-ringkas-bar>{{ $r['jumlah'] }} kamar{{ $r['malam'] ? ' · '.$r['malam'].' malam · '.$r['rentang'] : '' }}</div>
                        <div class="font-display text-[22px] font-extrabold leading-tight tabular-nums" data-total-bar>{{ $rp($r['total']) }}</div>
                    </div>
                    <ul class="m-0 hidden min-w-0 flex-1 list-none flex-wrap gap-2 p-0 md:flex">
                        @foreach ($r['chip'] as $c)
                            <li class="whitespace-nowrap rounded-full border border-[rgba(242,235,220,.35)] px-3 py-1 text-xs text-cream-50" data-chip>{{ $c }}</li>
                        @endforeach
                    </ul>
                    <div class="flex flex-none flex-col items-end gap-1">
                        <button type="button" @click="rincian = !rincian" :aria-expanded="rincian" aria-controls="rincian-pilihan"
                                class="border-0 bg-transparent p-0 text-[13px] text-cream-50 underline underline-offset-4 hover:text-sand-300">
                            <span x-text="rincian ? 'Tutup rincian ▾' : 'Lihat rincian ▴'">Lihat rincian ▴</span>
                        </button>
                        @if ($urlKamar)
                            <a href="{{ $urlKamar }}" class="text-xs text-sand-300 hover:text-cream-50">Lihat kamar lain</a>
                        @endif
                    </div>
                    <div class="flex w-full flex-col gap-1 md:w-auto md:flex-none">
                        @if ($r['urlLanjut'])
                            <a href="{{ $r['urlLanjut'] }}" class="wt-btn-primary h-12 w-full px-6 text-xs tracking-[.12em] md:w-auto" data-lanjutkan>Lanjutkan pemesanan →</a>
                        @else
                            <button type="button" disabled class="wt-btn-primary h-12 w-full cursor-not-allowed px-6 text-xs tracking-[.12em] opacity-50 md:w-auto" data-lanjutkan aria-describedby="alasan-lanjut">Lanjutkan pemesanan →</button>
                            <button type="button" id="alasan-lanjut" @click="{{ $r['aktif'] ? 'rincian = true' : 'rincian = false; ubahTanggal()' }}"
                                    class="border-0 bg-transparent p-0 text-left text-[12px] text-sand-300 underline-offset-2 hover:underline md:text-right">
                                {{ ! $r['aktif'] ? 'Pilih tanggal menginap dulu' : Str::before($st['pesan'], '. ').'.' }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
