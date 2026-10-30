@php
    use App\Support\InfoLaundry;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $kg = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, ',', '.'), '0'), ',').' kg';
    $tanyaWa = 'https://wa.me/'.($wisma['whatsapp'] ?? '').'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang layanan laundry.');
    $harga = (float) ($layanan?->price ?? 0);
@endphp

{{-- Informasi laundry. Formulir pemesanan ada di halaman sendiri (laundry.pesan), dibuka lewat tombol.
     Teks langkah, jenis cucian, jam layanan, dan tanya jawab ada di App\Support\InfoLaundry. --}}
<x-layouts.guest title="Laundry">
<div class="animate-wt-masuk">
    <x-tamu.kepala-halaman judul="Laundry" gambar="ds/img/laundry.jpg" teks="Cuci dan setrika kiloan, selesai dalam 1×24 jam. Tamu menginap cukup pesan dari kamar; tamu luar bisa mengantar cucian ke resepsionis.">
        <a href="{{ route('laundry.pesan') }}" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center rounded-sm bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline sm:w-auto">Pesan laundry</a>
        <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm border border-[rgba(242,235,220,.38)] px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline sm:w-auto">
            <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Tanya dulu
        </a>
    </x-tamu.kepala-halaman>

    <div class="wt-container flex max-w-[1120px] flex-col gap-12 py-10 sm:gap-16 sm:py-14">
        {{-- Harga dan kalkulator perkiraan biaya --}}
        <div class="grid gap-4 md:grid-cols-2">
            <div class="wt-card flex flex-col justify-center p-6" data-harga-laundry>
                <div class="wt-eyebrow">{{ $layanan?->name ?? 'Laundry kiloan' }}</div>
                <div class="mt-2 font-display text-[34px] font-extrabold leading-none tabular-nums">{{ $rp($layanan?->price) }}<span class="text-base font-semibold text-ink-500">/kg</span></div>
                <ul class="m-0 mt-4 flex list-none flex-col gap-1.5 p-0 text-sm text-ink-900">
                    <li>Cuci, kering, dan setrika rapi</li>
                    <li>Minimal {{ $minKg }} kg per pesanan</li>
                    <li>Selesai 1×24 jam sejak ditimbang</li>
                </ul>
                <a href="{{ route('laundry.pesan') }}" class="wt-btn-primary mt-5 h-11 w-full text-xs tracking-[.1em]">Pesan laundry</a>
            </div>

            {{-- Hitungan di browser (tanpa permintaan ke server): berat di bawah minimal ditagih sebagai minimal --}}
            @if ($harga > 0)
                <section class="wt-card p-6" aria-labelledby="judul-kalkulator" data-kalkulator-laundry
                         x-data="{
                             harga: {{ $harga }}, min: {{ $minKg }}, berat: {{ $minKg }},
                             atur(v) { const n = parseFloat(String(v).replace(',', '.')); this.berat = Math.min(15, Math.max(1, Math.round((isNaN(n) ? this.min : n) * 2) / 2)); },
                             get ditagih() { return Math.max(this.min, this.berat); },
                             rp(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); },
                             kg(n) { return n.toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' kg'; },
                         }">
                    <h2 id="judul-kalkulator" class="m-0 font-display text-[15px] font-bold uppercase tracking-[.06em]">Hitung perkiraan biaya</h2>
                    <label for="berat-laundry" class="wt-field-label mt-4 block">Berat cucian (kg)</label>
                    <div class="mt-1.5 flex items-center gap-2">
                        <button type="button" @click="atur(berat - 0.5)" :disabled="berat <= 1" aria-label="Kurangi berat"
                                class="flex h-11 w-11 flex-none items-center justify-center rounded border border-cream-300 bg-cream-50 text-[18px] text-brown-800 hover:bg-cream-200 disabled:opacity-30">−</button>
                        <input id="berat-laundry" type="number" inputmode="decimal" min="1" max="15" step="0.5" :value="berat" @change="atur($event.target.value); $event.target.value = berat"
                               value="{{ $minKg }}" class="wt-input h-11 min-w-0 flex-1 text-center font-display text-lg font-bold tabular-nums">
                        <button type="button" @click="atur(berat + 0.5)" :disabled="berat >= 15" aria-label="Tambah berat"
                                class="flex h-11 w-11 flex-none items-center justify-center rounded border border-cream-300 bg-cream-50 text-[18px] text-brown-800 hover:bg-cream-200 disabled:opacity-30">+</button>
                    </div>
                    <input type="range" min="1" max="15" step="0.5" :value="berat" @input="atur($event.target.value)" value="{{ $minKg }}"
                           aria-label="Geser berat cucian" class="mt-4 h-11 w-full cursor-pointer accent-clay-500">
                    <div class="-mt-2 flex justify-between text-[11px] tabular-nums text-ink-500" aria-hidden="true"><span>1 kg</span><span>15 kg</span></div>

                    <div class="mt-4 rounded-md bg-[rgba(181,113,74,.08)] px-4 py-3.5" aria-live="polite">
                        <div class="font-display text-xl font-extrabold tabular-nums text-clay-600">
                            Perkiraan: <span x-text="rp(ditagih * harga)">{{ $rp($minKg * $harga) }}</span>
                            <span x-show="berat < min" class="text-[13px] font-semibold text-ink-500" x-text="'(dihitung ' + kg(min) + ')'"></span>
                        </div>
                        <p class="mb-0 mt-1 text-xs leading-relaxed text-ink-500">Minimal {{ $kg($minKg) }} per pesanan. Total akhir mengikuti hasil timbang di wisma.</p>
                    </div>
                    <a href="{{ route('laundry.pesan') }}" class="wt-btn-primary mt-4 h-11 w-full text-xs tracking-[.1em]">Pesan laundry</a>
                </section>
            @endif
        </div>

        {{-- Cara pesan: empat langkah. Garis penghubung vertikal di kiri ikon (di bawah lg), putus-putus dengan panah (lg ke atas). --}}
        <section aria-labelledby="judul-cara-pesan" data-cara-pesan>
            <div class="wt-eyebrow">Cara pesan laundry</div>
            <h2 id="judul-cara-pesan" class="mb-0 mt-1.5 font-display text-[22px] font-extrabold uppercase leading-tight tracking-[.03em] text-balance sm:text-[26px]">4 langkah mudah</h2>
            <ol class="m-0 mt-6 grid list-none p-0 lg:grid-cols-4 lg:gap-4">
                @foreach (InfoLaundry::LANGKAH as $i => [$ikon, $judul, $isi])
                    <li class="relative flex gap-4 pb-7 last:pb-0 lg:flex-col lg:gap-0 lg:pb-0" data-langkah="{{ $i + 1 }}">
                        @unless ($loop->last)
                            <span class="absolute bottom-0 left-6 top-12 border-l-2 border-dashed border-cream-400 lg:hidden" aria-hidden="true"></span>
                            <span class="absolute left-[calc(3rem+10px)] right-[-2px] top-6 hidden border-t-2 border-dashed border-cream-400 lg:block" aria-hidden="true"></span>
                            <x-tamu.ikon nama="kanan" ukuran="16" class="absolute -right-3.5 top-4 hidden text-cream-400 lg:block" />
                        @endunless
                        <span class="relative z-[1] flex h-12 w-12 flex-none items-center justify-center rounded-full bg-brown-800 text-cream-50">
                            <x-tamu.ikon :nama="$ikon" ukuran="20" />
                        </span>
                        <div class="min-w-0 pt-0.5 lg:mt-4 lg:pr-6 lg:pt-0">
                            <div class="font-display text-[11px] font-bold uppercase tracking-[.16em] text-clay-600">Langkah {{ $i + 1 }}</div>
                            <div class="mt-1 font-display text-[15px] font-bold">{{ $judul }}</div>
                            <p class="mb-0 mt-1 text-[13px] leading-relaxed text-ink-500">{{ $isi }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Jenis cucian dan jam layanan --}}
        <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <section class="wt-card p-6" aria-labelledby="judul-jenis-cucian" data-jenis-cucian>
                <h2 id="judul-jenis-cucian" class="m-0 font-display text-[15px] font-bold uppercase tracking-[.06em]">Yang kami terima</h2>
                <div class="mt-4 grid gap-6 sm:grid-cols-2">
                    <div>
                        <div class="wt-micro-label">Diterima</div>
                        <ul class="m-0 mt-2.5 flex list-none flex-col gap-2 p-0 text-sm" data-diterima>
                            @foreach (InfoLaundry::DITERIMA as $x)
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-5 w-5 flex-none items-center justify-center rounded-full bg-[rgba(92,158,103,.16)] text-status-available" aria-hidden="true">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    </span>{{ $x }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <div class="wt-micro-label">Tidak diterima</div>
                        <ul class="m-0 mt-2.5 flex list-none flex-col gap-2 p-0 text-sm text-ink-500" data-tidak-diterima>
                            @foreach (InfoLaundry::TIDAK_DITERIMA as $x)
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 flex h-5 w-5 flex-none items-center justify-center rounded-full bg-[rgba(212,81,74,.1)] text-status-dirty/70" aria-hidden="true">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                    </span>{{ $x }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <p class="mb-0 mt-5 border-t border-cream-300 pt-4 text-xs text-ink-500">{{ InfoLaundry::CATATAN_JENIS }}</p>
            </section>

            <section class="flex flex-col gap-3" aria-labelledby="judul-jam-laundry" data-jam-laundry>
                <h2 id="judul-jam-laundry" class="sr-only">Jam layanan laundry</h2>
                @foreach (InfoLaundry::JAM_LAYANAN as [$nama, $hari, $jam])
                    <div class="wt-card flex flex-1 items-center gap-4 p-5">
                        <span class="flex h-11 w-11 flex-none items-center justify-center rounded-full bg-cream-200 text-brown-800"><x-tamu.ikon nama="jam" ukuran="20" /></span>
                        <div class="min-w-0">
                            <div class="wt-micro-label">{{ $nama }}</div>
                            <div class="mt-1 text-sm text-ink-900">{{ $hari }}, <span class="font-semibold tabular-nums">{{ $jam }}</span></div>
                        </div>
                    </div>
                @endforeach
            </section>
        </div>

        {{-- Tanya jawab: satu jawaban terbuka sekaligus; tombol asli sehingga bisa dipakai dengan keyboard --}}
        <section x-data="{ buka: 0 }" aria-labelledby="judul-tanya-laundry" data-tanya-laundry>
            <div class="wt-eyebrow">Tanya jawab</div>
            <h2 id="judul-tanya-laundry" class="mb-0 mt-1.5 font-display text-[22px] font-extrabold uppercase leading-tight tracking-[.03em] sm:text-[26px]">Yang sering ditanyakan</h2>
            <ul class="m-0 mt-6 flex list-none flex-col gap-3 p-0">
                @foreach (InfoLaundry::TANYA_JAWAB as $i => [$q, $a])
                    <li :class="buka === {{ $i }} ? 'border-clay-500/50 bg-cream-50 shadow-wt-md' : 'border-cream-300 bg-[rgba(255,253,246,.65)] hover:border-cream-400 hover:bg-cream-50'"
                        class="group relative overflow-hidden rounded-xl border transition-[background-color,border-color,box-shadow] duration-300">
                        <span class="absolute inset-y-0 left-0 w-[3px] origin-top bg-clay-500 transition-transform duration-300" :class="buka === {{ $i }} ? 'scale-y-100' : 'scale-y-0'" aria-hidden="true"></span>
                        <h3 class="m-0">
                            <button type="button" id="tanya-laundry-{{ $i }}" @click="buka = buka === {{ $i }} ? null : {{ $i }}"
                                    :aria-expanded="buka === {{ $i }}" aria-expanded="{{ $i === 0 ? 'true' : 'false' }}" aria-controls="jawab-laundry-{{ $i }}"
                                    class="flex w-full items-center gap-4 border-0 bg-transparent px-5 py-4 text-left sm:px-6">
                                <span class="min-w-0 flex-1 text-[15px] font-semibold leading-snug text-ink-900">{{ $q }}</span>
                                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full border transition-all duration-300"
                                      :class="buka === {{ $i }} ? 'rotate-45 border-clay-500 bg-clay-500 text-cream-50' : 'border-cream-400 text-brown-800 group-hover:border-clay-500 group-hover:text-clay-600'" aria-hidden="true">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                </span>
                            </button>
                        </h3>
                        <div id="jawab-laundry-{{ $i }}" role="region" aria-labelledby="tanya-laundry-{{ $i }}" x-show="buka === {{ $i }}" x-collapse.duration.300ms @if ($i !== 0) x-cloak @endif>
                            <p class="m-0 border-t border-cream-300 px-5 pb-5 pt-4 text-sm leading-relaxed text-ink-500 sm:px-6">{{ $a }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    {{-- Ajakan penutup: pita gelap seperti kepala halaman --}}
    <section class="relative isolate overflow-hidden bg-brown-900" aria-labelledby="judul-ajakan-laundry" data-ajakan-laundry>
        <img src="{{ asset('ds/img/laundry.jpg') }}" alt="" loading="lazy" class="absolute inset-0 -z-10 h-full w-full object-cover opacity-25">
        <div class="wt-container flex max-w-[1120px] flex-col items-start gap-6 py-12 sm:py-14 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 id="judul-ajakan-laundry" class="m-0 font-display text-[24px] font-extrabold uppercase leading-tight tracking-[.03em] text-cream-50 sm:text-[30px]">Tinggal pesan, kami yang urus.</h2>
                <p class="mb-0 mt-2 text-[15px] text-[rgba(242,235,220,.8)]">Pakaian bersih dan rapi untuk perjalanan Anda.</p>
            </div>
            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row md:flex-none">
                <a href="{{ route('laundry.pesan') }}" class="inline-flex h-12 items-center justify-center whitespace-nowrap rounded-sm bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">Pesan laundry</a>
                <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="inline-flex h-12 items-center justify-center gap-2.5 whitespace-nowrap rounded-sm border border-[rgba(242,235,220,.38)] px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline">
                    <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Tanya via WhatsApp
                </a>
            </div>
        </div>
    </section>
</div>
</x-layouts.guest>
