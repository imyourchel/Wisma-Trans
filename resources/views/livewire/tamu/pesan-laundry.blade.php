@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $tanyaWa = 'https://wa.me/'.($wisma['whatsapp'] ?? '').'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang layanan laundry.');
    $menginap = $jenis === 'menginap';
@endphp

{{-- Halaman formulir pemesanan laundry; harga dan alur ada di halaman /laundry (tamu.laundry) --}}
<div class="animate-wt-masuk">
    <x-tamu.kepala-halaman judul="Pesan Laundry" gambar="ds/img/laundry.jpg"
                           teks="Tamu menginap memilih kamar dan cucian kami jemput; tamu luar mengisi nama dan WhatsApp lalu mengantar cucian ke resepsionis.">
        <a href="{{ route('laundry') }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-sm border border-[rgba(242,235,220,.38)] px-6 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline">
            <x-tamu.ikon nama="kiri" ukuran="16" />Info laundry
        </a>
        <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center justify-center gap-2.5 rounded-sm border border-[rgba(242,235,220,.38)] px-6 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline">
            <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Tanya dulu
        </a>
    </x-tamu.kepala-halaman>

    <div class="wt-container max-w-[1120px] py-10 sm:py-14">
        {{-- Ringkasan tarif --}}
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 rounded-lg border border-cream-300 bg-cream-50 px-5 py-3.5 text-sm" data-ringkasan-laundry>
            <span><strong class="font-semibold">{{ $layanan?->name ?? 'Laundry kiloan' }}</strong>
                <span class="text-ink-500">· {{ $rp($layanan?->price) }}/kg · minimal {{ $minKg }} kg · selesai 1×24 jam sejak ditimbang</span></span>
            <a href="{{ route('laundry') }}" class="text-[13px] font-semibold text-olive-600">Lihat alur layanan</a>
        </div>

        {{-- Formulir --}}
        {{-- Tamu belum masuk ditawari masuk/daftar (pop-up x-tawaran-akun) saat mulai mengisi, sebelum ada isian yang bisa hilang --}}
        <form id="pesan-laundry" wire:submit="kirim" class="wt-card mt-6 scroll-mt-24 overflow-hidden" novalidate
              @guest x-data @focusin.once="$dispatch('tawar-akun', { varian: $wire.jenis === 'menginap' ? 'menginap' : 'luar' })" @endguest>
            <div class="border-b border-cream-300 bg-cream-100 px-5 py-4 sm:px-6">
                <h2 class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.06em]">Pesan laundry</h2>
                <div class="mt-3 wt-geser inline-flex max-w-full rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Jenis tamu">
                    @foreach (['menginap' => 'Sedang menginap', 'luar' => 'Tidak menginap'] as $v => $l)
                        <button type="button" wire:click="$set('jenis', '{{ $v }}')" role="tab" aria-selected="{{ $jenis === $v ? 'true' : 'false' }}"
                                @class(['h-9 rounded-md border-0 px-4 text-[13px] font-semibold transition-colors',
                                    'bg-brown-800 text-cream-50' => $jenis === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $jenis !== $v])>{{ $l }}</button>
                    @endforeach
                </div>
                <p class="mb-0 mt-2 text-xs leading-relaxed text-ink-500" data-keterangan-jenis>{{ $jenis === 'menginap' ? 'Masukkan kode kamar, tagihan masuk ke nota kamar Anda.' : 'Bayar langsung saat memesan.' }}</p>
            </div>

            <div class="grid gap-6 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="flex min-w-0 flex-col gap-4">
                    @if ($menginap)
                        @if ($kamarTersedia->isEmpty())
                            <p class="m-0 text-sm text-ink-500">Masukkan kode kamar dari pesan WhatsApp saat check-in untuk memilih kamar Anda.</p>
                            <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                <label class="block"><span class="wt-field-label mb-1.5 block">Kode kamar</span>
                                    <input type="text" wire:model="kodeKamar" wire:keydown.enter.prevent="cariKamar" placeholder="Contoh: LK7Q-2M9X" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false"
                                           class="wt-input h-11 uppercase tracking-[.08em] placeholder:normal-case placeholder:tracking-normal {{ $galat('kodeKamar') }}"></label>
                                <button type="button" wire:click="cariKamar" class="h-11 rounded border border-brown-800 bg-transparent px-4 text-[13px] font-semibold text-brown-800 hover:bg-cream-200">Cari kamar</button>
                            </div>
                            @error('kodeKamar')<span class="-mt-1 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                        @else
                            <fieldset class="m-0 min-w-0 border-0 p-0">
                                <legend class="mb-2 flex w-full items-baseline justify-between gap-3 p-0">
                                    <span class="wt-field-label">Kamar</span>
                                    @if ($dariKode)<button type="button" wire:click="lepas" class="border-0 bg-transparent p-0 text-xs font-semibold text-clay-600 hover:underline">Ganti</button>@endif
                                </legend>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($kamarTersedia as $k)
                                        <label wire:key="km-{{ $k['id'] }}" class="flex cursor-pointer flex-col rounded-lg border border-cream-300 bg-cream-50 px-4 py-2.5 hover:border-brown-800 has-[:checked]:border-brown-800 has-[:checked]:bg-cream-200">
                                            <input type="radio" wire:model="kamar" value="{{ $k['id'] }}" class="sr-only">
                                            <span class="font-display text-lg font-extrabold tabular-nums">Kamar {{ $k['nomor'] }}</span>
                                            <span class="text-[11px] text-ink-500">menginap sampai {{ $k['keluar'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('kamar')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                            </fieldset>
                            <fieldset class="m-0 min-w-0 border-0 p-0">
                                <legend class="wt-field-label mb-2 p-0">Pembayaran</legend>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach (['kamar' => ['Tagihkan ke kamar', 'Dibayar saat check-out'], 'sekarang' => ['Bayar setelah ditimbang', 'QRIS, virtual account, atau tunai']] as $v => [$l, $k])
                                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-cream-300 bg-cream-50 px-3.5 py-3 hover:border-brown-800 has-[:checked]:border-brown-800 has-[:checked]:bg-cream-200">
                                            <input type="radio" wire:model="tagih" value="{{ $v }}" class="mt-1 accent-brown-800">
                                            <span><span class="block text-sm font-semibold">{{ $l }}</span><span class="block text-xs text-ink-500">{{ $k }}</span></span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <p class="m-0 rounded-md bg-cream-200 px-3.5 py-2.5 text-[13px]">Petugas kami menjemput cucian ke kamar setelah pesanan masuk.</p>
                        @endif
                    @else
                        @foreach ([
                            ['nama', 'Nama', 'text', 'name', 'Nama Anda'],
                            ['hp', 'Nomor WhatsApp', 'tel', 'tel', '08xxxxxxxxxx'],
                            ['email', 'Email (opsional)', 'email', 'email', 'Untuk bukti pembayaran'],
                        ] as [$f, $label, $type, $auto, $ph])
                            <label class="block">
                                <span class="wt-field-label mb-1.5 block">{{ $label }}</span>
                                <input type="{{ $type }}" wire:model="{{ $f }}" autocomplete="{{ $auto }}" placeholder="{{ $ph }}" class="wt-input h-11 {{ $galat($f) }}">
                                @error($f)<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                            </label>
                        @endforeach
                        <p class="m-0 rounded-md bg-cream-200 px-3.5 py-2.5 text-[13px]">Antar cucian ke resepsionis Wisma Trans. Kami menimbangnya, lalu tagihan dan tautan bayar dikirim ke WhatsApp Anda.</p>
                    @endif
                </div>

                <div class="flex min-w-0 flex-col gap-4">
                    <label class="block">
                        <span class="wt-field-label mb-1.5 block">Perkiraan berat (opsional)</span>
                        <div class="relative">
                            <input type="text" inputmode="decimal" wire:model="perkiraan" placeholder="Contoh: 3" class="wt-input h-11 pr-12 {{ $galat('perkiraan') }}">
                            <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-ink-500">kg</span>
                        </div>
                        <span class="mt-1.5 block text-xs text-ink-500">Hanya perkiraan; tagihan mengikuti hasil timbang.</span>
                        @error('perkiraan')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">
                        <span class="wt-field-label mb-1.5 block">Catatan (opsional)</span>
                        <textarea wire:model="catatan" rows="3" maxlength="200" placeholder="Contoh: pisahkan pakaian putih, jangan disetrika kemeja batik" class="wt-input {{ $galat('catatan') }}"></textarea>
                        @error('catatan')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                    </label>
                    @error('layanan')<p class="m-0 text-sm font-medium text-status-dirty" role="alert">{{ $message }}</p>@enderror
                    <button type="submit" wire:loading.attr="disabled" @disabled($menginap && $kamarTersedia->isEmpty())
                            class="mt-auto h-12 rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 disabled:cursor-not-allowed disabled:opacity-60">
                        <span wire:loading.remove wire:target="kirim">{{ $menginap ? 'Minta jemput cucian' : 'Kirim pesanan' }}</span>
                        <span wire:loading wire:target="kirim">Mengirim…</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
    <x-tawaran-akun kunci="wt.laundry.tanpa-akun" :varian="[
        'menginap' => ['manfaat' => [
                ['Kamar langsung dikenali', 'Kamar Anda tampil otomatis, tanpa perlu memasukkan kode kamar.'],
                ['Status laundry terpantau', 'Penjemputan, penimbangan, hingga selesai tampil di menu Pesanan saya.'],
            ], 'lanjut' => 'Lanjutkan sebagai tamu', 'ingat' => true, 'catatan' => 'Sebaiknya masuk sebelum mengisi formulir agar isian tidak perlu diulang.'],
        'luar' => ['manfaat' => [
                ['Data pemesan terisi otomatis', 'Nama, nomor WhatsApp, dan email tidak perlu diketik ulang.'],
                ['Status laundry terpantau', 'Penimbangan, tagihan, hingga selesai tampil di menu Pesanan saya.'],
            ], 'lanjut' => 'Lanjutkan sebagai tamu', 'ingat' => true, 'catatan' => 'Sebaiknya masuk sebelum mengisi formulir agar isian tidak perlu diulang.'],
    ]" />
</div>
