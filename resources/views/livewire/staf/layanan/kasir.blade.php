@php
    use App\Services\Layanan\PesananMakananService as PM;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $galatKeranjang = collect($errors->getMessages())->filter(fn ($p, $k) => str_starts_with($k, 'keranjang'))->flatten()->unique();
    // Bahasa kasir sehari-hari; nilai yang dikirim tetap kunci ServiceOrder::SERVING
    $pilihanSaji = collect(['dine_in' => 'Makan di sini', 'take_away' => 'Dibungkus', 'room_service' => 'Antar ke kamar'])
        ->only($jenis === 'kamar' ? ['dine_in', 'take_away', 'room_service'] : ['dine_in', 'take_away']);
    $bayarSekarang = $jenis === 'luar' || $tagih === 'sekarang';
@endphp

{{-- --kepala: tinggi header panel staf (diukur, ikut berubah saat ukuran layar berubah); dipakai pencarian dan panel Pesanan yang menempel --}}
<div x-data="{ kepala: 80 }" :style="{ '--kepala': kepala + 'px' }" class="max-lg:pb-40"
     x-init="const h = document.querySelector('.wt-konten > header'); const ukur = () => kepala = h?.offsetHeight || 80; ukur(); h && new ResizeObserver(ukur).observe(h)">
    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_400px]" x-data="{ lembar: false }">
        {{-- Menu --}}
        <div class="min-w-0">
            {{-- Menempel di bawah header saat menu digulir; latar sama dengan halaman supaya kartu tidak terlihat di belakangnya --}}
            <div class="sticky top-[var(--kepala,80px)] z-20 -mt-3 mb-1 bg-cream-100 pb-3 pt-3" data-cari-kasir>
                <label class="relative block w-full">
                    <span class="sr-only">Cari menu</span>
                    <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
                    <input type="search" wire:model.live.debounce.300ms="cari" placeholder="Cari menu" class="wt-input w-full pl-9">
                </label>
            </div>

            @forelse ($grup as $nama => $isi)
                <section wire:key="grup-{{ \Illuminate\Support\Str::slug($nama) }}" class="mb-5">
                    <h2 class="mb-2.5 mt-0 font-display text-[12px] font-bold uppercase tracking-[.08em] text-ink-500">{{ $nama }}</h2>
                    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-[repeat(auto-fill,minmax(180px,1fr))]">
                        @foreach ($isi as $m)
                            @php $jumlah = (int) ($keranjang[$m->id]['qty'] ?? 0); @endphp
                            <button type="button" wire:key="menu-{{ $m->id }}" wire:click="tambah({{ $m->id }})" @click="$dispatch('kasir-tambah', { id: {{ $m->id }} })" @disabled($m->habis())
                                    @class(['relative flex min-h-[86px] flex-col rounded-md border bg-cream-50 p-3 text-left shadow-wt-xs transition',
                                        'cursor-not-allowed border-cream-300 opacity-50' => $m->habis(),
                                        'border-clay-500 ring-1 ring-clay-500' => $jumlah > 0,
                                        'border-cream-300 hover:-translate-y-px hover:border-cream-400 hover:shadow-wt-md' => ! $m->habis() && ! $jumlah])>
                                <span class="text-[13px] font-semibold leading-snug">{{ $m->name }}</span>
                                <span class="mt-auto flex items-end justify-between gap-2 pt-2">
                                    @if ($m->habis())
                                        <span class="font-display text-[11px] font-bold uppercase tracking-[.1em] text-status-dirty">Habis</span>
                                    @elseif ($m->price === null)
                                        <span class="text-[12px] text-ink-500">Harga sesuai ukuran</span>
                                    @else
                                        <span class="text-[13px] font-semibold tabular-nums text-clay-600">{{ $rp($m->price) }}</span>
                                    @endif
                                    @if ($jumlah)
                                        <span class="flex h-6 min-w-6 items-center justify-center rounded-full bg-clay-500 px-1.5 text-[11px] font-bold text-cream-50">{{ $jumlah }}</span>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">Menu tidak ditemukan.</div>
            @endforelse
        </div>

        {{-- Keranjang dan pemesan, seperti panel pesanan Resto tamu. Layar lebar: menempel di bawah header dengan tinggi tetap,
             isi bergulir sendiri, Total dan tombol simpan selalu terlihat. Di bawah lg: lembar bawah (bottom sheet); saat tertutup
             hanya ringkasan (jumlah menu + total) dan tombol simpan yang menempel di bawah layar. --}}
        <div x-show="lembar" x-cloak @click="lembar = false" x-transition.opacity class="fixed inset-0 z-[45] bg-[rgba(62,42,28,.40)] lg:hidden"></div>
        <form wire:submit="simpan" novalidate data-panel-kasir x-data="{ incar: null }"
              @kasir-tambah.window="incar = $event.detail.id" @keydown.escape.window="lembar = false"
              :class="lembar && 'max-lg:max-h-[88dvh]'"
              class="wt-card flex flex-col shadow-wt-xs max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-50 max-lg:rounded-b-none max-lg:border-x-0 max-lg:border-b-0 max-lg:pb-[env(safe-area-inset-bottom)] max-lg:shadow-[0_-12px_32px_-12px_rgba(62,42,28,.35)] lg:sticky lg:top-[calc(var(--kepala,80px)_+_1.5rem)] lg:h-[calc(100dvh_-_var(--kepala,80px)_-_3rem)] lg:overflow-hidden">
            <div class="flex flex-none items-center gap-2 border-b border-cream-300 px-5 py-3.5" :class="!lembar && 'max-lg:hidden'">
                <h2 class="m-0 flex-1 font-display text-[13px] font-bold uppercase tracking-[.06em]">Pesanan</h2>
                <span class="text-[12px] text-ink-500">{{ count($keranjang) }} menu</span>
                <button type="button" @click="lembar = false" class="-mr-2 flex h-11 w-11 flex-none items-center justify-center rounded border-0 bg-transparent text-brown-800 lg:hidden" aria-label="Tutup pesanan"><x-staf.ikon nama="tutup" ukuran="18" /></button>
            </div>

            <div class="flex flex-col gap-4 px-5 py-4 max-lg:min-h-0 max-lg:flex-1 max-lg:overflow-y-auto max-lg:overscroll-contain lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain" :class="!lembar && 'max-lg:hidden'" data-isi-panel>
                {{-- Galat validasi baru: gulir ke isian pertama yang bermasalah --}}
                @if ($errors->hasAny(['kamar', 'nama', 'hp', 'penyajian', 'tagih', 'metode', 'catatan']) || $galatKeranjang->isNotEmpty())
                    <span hidden wire:key="galat-{{ md5(json_encode($errors->getMessages())) }}"
                          x-init="lembar = true; $nextTick(() => $el.parentElement.querySelector('.is-invalid, [data-galat], [role=alert]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }))"></span>
                @endif
                {{-- Isi keranjang --}}
                @if (! $keranjang)
                    <p class="m-0 rounded-md border border-dashed border-cream-400 px-4 py-6 text-center text-[13px] text-ink-500">Klik menu di kiri untuk menambahkan.</p>
                @else
                    <ul class="m-0 flex list-none flex-col gap-3 p-0">
                        @foreach ($keranjang as $id => $b)
                            @php $m = $dipilih[$id] ?? null; @endphp
                            @continue(! $m)
                            <li wire:key="krj-{{ $id }}" class="scroll-my-3 rounded-md border border-cream-300 bg-cream-100/60 p-3">
                                <span hidden wire:key="krj-{{ $id }}-{{ (int) $b['qty'] }}"
                                      x-init="if (incar === {{ $id }}) { incar = null; const isi = $el.closest('[data-isi-panel]'); if (getComputedStyle(isi).overflowY === 'auto') $el.parentElement.scrollIntoView({ block: 'nearest', behavior: 'smooth' }) }"></span>
                                <div class="flex items-start gap-2">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[13px] font-semibold">{{ $m->name }}</div>
                                        <div class="text-[12px] tabular-nums text-ink-500">{{ $m->price !== null ? $rp($m->price).' / porsi' : 'Harga sesuai ukuran' }}</div>
                                    </div>
                                    <input type="number" min="1" max="20" wire:model.live.debounce.400ms="keranjang.{{ $id }}.qty" aria-label="Jumlah {{ $m->name }}"
                                           @class(['wt-input h-9 w-16 text-center tabular-nums', 'is-invalid' => $errors->has("keranjang.$id.qty")])>
                                    <button type="button" wire:click="hapus({{ $id }})" class="flex h-9 w-9 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-ink-500 hover:text-status-dirty" aria-label="Hapus {{ $m->name }}"><x-staf.ikon nama="tutup" ukuran="14" /></button>
                                </div>
                                @if ($m->price === null)
                                    <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="keranjang.{{ $id }}.harga" placeholder="Harga per porsi, misalnya 85000" aria-label="Harga {{ $m->name }}"
                                           @class(['wt-input mt-2 h-9 tabular-nums', 'is-invalid' => $errors->has("keranjang.$id.harga")])>
                                @endif
                                <input type="text" wire:model="keranjang.{{ $id }}.catatan" maxlength="150" placeholder="Catatan, misalnya tidak pedas" aria-label="Catatan {{ $m->name }}"
                                       @class(['wt-input mt-2 h-9 text-[13px]', 'is-invalid' => $errors->has("keranjang.$id.catatan")])>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @foreach ($galatKeranjang as $pesan)
                    <p class="m-0 text-[13px] font-medium text-status-dirty" role="alert">{{ $pesan }}</p>
                @endforeach

                {{-- Pemesan --}}
                <div class="flex flex-col gap-3 border-t border-cream-300 pt-4">
                    <div class="wt-geser inline-flex max-w-full self-start rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Pemesan">
                        @foreach (['kamar' => 'Tamu menginap', 'luar' => 'Tamu luar'] as $v => $l)
                            <button type="button" wire:click="$set('jenis', '{{ $v }}')" role="tab" aria-selected="{{ $jenis === $v ? 'true' : 'false' }}"
                                    @class(['h-8 rounded-md border-0 px-3.5 text-[13px] font-semibold', 'bg-brown-800 text-cream-50' => $jenis === $v, 'bg-transparent text-ink-500' => $jenis !== $v])>{{ $l }}</button>
                        @endforeach
                    </div>
                    @if ($jenis === 'kamar')
                        <x-staf.isian nama="kamar" label="Kamar" tag="div">
                            <x-staf.pilih model="$wire.kamar" label="Kamar" :galat="$errors->has('kamar') ? 'true' : null" :pilihan="['' => $kamarTerisi->isEmpty() ? 'Tidak ada kamar terisi' : 'Pilih kamar terisi'] + $kamarTerisi->all()" />
                        </x-staf.isian>
                    @else
                        <x-staf.isian nama="nama" label="Nama tamu"><input type="text" wire:model="nama" @class(['wt-input', 'is-invalid' => $errors->has('nama')])></x-staf.isian>
                        <x-staf.isian nama="hp" label="Nomor WhatsApp"><input type="tel" wire:model="hp" placeholder="08xxxxxxxxxx" @class(['wt-input', 'is-invalid' => $errors->has('hp')])></x-staf.isian>
                    @endif

                    <x-staf.isian nama="penyajian" label="Penyajian" tag="div">
                        <div @class(['grid gap-1.5', 'grid-cols-3' => count($pilihanSaji) === 3, 'grid-cols-2' => count($pilihanSaji) === 2])>
                            @foreach ($pilihanSaji as $v => $l)
                                <label @class(['flex h-9 cursor-pointer items-center justify-center whitespace-nowrap rounded-full border px-2 text-[13px] font-semibold',
                                    'border-brown-800 bg-brown-800 text-cream-50' => $penyajian === $v, 'border-cream-300 bg-cream-50 text-ink-500 hover:text-ink-900' => $penyajian !== $v])>
                                    <input type="radio" wire:model.live="penyajian" value="{{ $v }}" class="sr-only">{{ $l }}
                                </label>
                            @endforeach
                        </div>
                    </x-staf.isian>

                    @if ($jenis === 'kamar')
                        <x-staf.isian nama="tagih" label="Pembayaran" tag="div">
                            <div class="flex flex-wrap gap-4 text-[13px]">
                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="tagih" value="kamar" class="accent-brown-800"> Masuk tagihan kamar</label>
                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="tagih" value="sekarang" class="accent-brown-800"> Bayar sekarang</label>
                            </div>
                        </x-staf.isian>
                    @endif
                    @if ($bayarSekarang)
                        <x-staf.isian nama="metode" label="Metode pembayaran" tag="div">
                            <div class="grid grid-cols-2 gap-1.5">
                                @foreach ($metodeKasir as $v => $l)
                                    <label @class(['flex h-9 cursor-pointer items-center justify-center rounded border text-[13px] font-semibold',
                                        'border-clay-500 bg-[rgba(181,113,74,.1)] text-clay-600' => $metode === $v, 'border-cream-300 bg-cream-50 text-ink-500 hover:text-ink-900' => $metode !== $v])>
                                        <input type="radio" wire:model.live="metode" value="{{ $v }}" class="sr-only">{{ $l }}
                                    </label>
                                @endforeach
                            </div>
                        </x-staf.isian>
                    @endif
                    <x-staf.isian nama="catatan" label="Catatan pesanan (opsional)"><input type="text" wire:model="catatan" maxlength="255" placeholder="Contoh: meja 4" class="wt-input"></x-staf.isian>
                </div>
            </div>

            {{-- Kaki seperti drawer Resto: Total dan tombol penuh --}}
            <div class="sticky bottom-0 z-10 flex-none rounded-b-[inherit] border-t border-cream-300 bg-cream-50 px-5 py-4 shadow-[0_-8px_18px_-12px_rgba(62,42,28,.28)] max-lg:border-t-0 max-lg:py-3 max-lg:shadow-none lg:static" :class="lembar && 'max-lg:!border-t'">
                <div class="mb-3 flex items-baseline justify-between gap-3">
                    {{-- Di bawah lg: ketuk untuk membuka atau menutup lembar pesanan --}}
                    <button type="button" @click="lembar = !lembar" :aria-expanded="lembar" class="-ml-1 flex min-h-11 items-center gap-1.5 rounded border-0 bg-transparent px-1 text-left text-sm font-semibold text-olive-600 lg:hidden" data-buka-pesanan>
                        <span x-text="lembar ? 'Sembunyikan' : 'Lihat pesanan'">Lihat pesanan</span> · {{ count($keranjang) }} menu
                        <x-staf.ikon nama="panah" ukuran="14" class="transition-transform" ::class="!lembar && 'rotate-180'" />
                    </button>
                    <span class="text-sm text-ink-500 max-lg:hidden">Total</span>
                    <span class="font-display text-xl font-extrabold tabular-nums">{{ $rp($subtotal) }}</span>
                </div>
                <button type="submit" wire:loading.attr="disabled" wire:target="simpan" @disabled(! $keranjang)
                        class="h-12 w-full rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 disabled:opacity-50">
                    Simpan pesanan{{ $bayarSekarang ? ' · '.$metodeKasir[$metode] : '' }}
                </button>
            </div>
        </form>
    </div>

    @if ($terakhir)
        <div class="mt-5 flex flex-wrap items-center gap-3 rounded-md border border-[rgba(92,158,103,.4)] bg-[rgba(92,158,103,.08)] px-4 py-3 text-[13px]" role="status">
            <span class="font-semibold">{{ $terakhir['kode'] }} masuk dapur.</span>
            <span class="text-ink-500">Kirim status pesanan ke tamu:</span>
            <a href="{{ $terakhir['url'] }}" target="_blank" rel="noopener">Halaman status</a>
            @if ($terakhir['wa'])<a href="{{ $terakhir['wa'] }}" target="_blank" rel="noopener" class="wt-btn-sm h-8 hover:no-underline">Kirim lewat WhatsApp</a>@endif
            <button type="button" wire:click="tutupTerakhir" class="ml-auto border-0 bg-transparent p-0 text-ink-500" aria-label="Tutup">✕</button>
        </div>
    @endif

    {{-- Belum dibayar: tamu yang memesan online atau di kasir lalu membayar di tempat --}}
    <section class="wt-card mt-5 p-4" aria-labelledby="judul-belum-bayar">
        <h2 id="judul-belum-bayar" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Belum dibayar ({{ $belumDibayar->count() }})</h2>
        <p class="mb-0 mt-1 text-xs text-ink-500">Pesanan bayar sekarang yang belum lunas dalam 24 jam terakhir. Setelah dibayar, pesanan masuk dapur.</p>
        @if ($belumDibayar->isEmpty())
            <p class="mb-0 mt-3 text-[13px] text-ink-500">Tidak ada.</p>
        @else
            <ul class="m-0 mt-3 flex list-none flex-col p-0 text-[13px]">
                @foreach ($belumDibayar as $x)
                    <li wire:key="belum-{{ $x->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-cream-300 py-2.5 first:border-t-0">
                        <span class="font-semibold tabular-nums">{{ $x->order_code }}</span>
                        <span class="text-ink-500">{{ $x->customer?->full_name }} · {{ PM::ringkasMenu($x) }} · {{ $x->order_date->format('H.i') }}</span>
                        <span class="ml-auto font-semibold tabular-nums">{{ $rp($x->invoice?->total_amount) }}</span>
                        @if ($bayarId === $x->id)
                            <form wire:submit="terimaBayar" class="flex w-full flex-wrap items-center gap-2" novalidate>
                                @foreach ($metodeKasir as $v => $l)
                                    <label @class(['flex h-9 cursor-pointer items-center rounded border px-3 text-[13px] font-semibold',
                                        'border-clay-500 bg-[rgba(181,113,74,.1)] text-clay-600' => $metodeBayar === $v, 'border-cream-300 bg-cream-50 text-ink-500' => $metodeBayar !== $v])>
                                        <input type="radio" wire:model.live="metodeBayar" value="{{ $v }}" class="sr-only">{{ $l }}
                                    </label>
                                @endforeach
                                <button type="submit" class="h-9 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Terima {{ $rp($x->invoice?->total_amount) }}</button>
                                <button type="button" wire:click="batalBayar" class="wt-btn-sm h-9">Batal</button>
                                @error('metodeBayar')<span class="text-[12px] text-status-dirty">{{ $message }}</span>@enderror
                                @error('status')<span class="text-[12px] text-status-dirty">{{ $message }}</span>@enderror
                            </form>
                        @else
                            <button type="button" wire:click="mulaiBayar({{ $x->id }})" class="wt-btn-sm h-8">Terima pembayaran</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
