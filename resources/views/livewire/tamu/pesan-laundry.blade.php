@php
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $tanyaWa = 'https://wa.me/'.($wisma['whatsapp'] ?? '').'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang layanan laundry.');
    $menginap = $jenis === 'menginap';
@endphp

<div class="animate-wt-masuk">
    <x-tamu.kepala-halaman judul="Laundry" gambar="ds/img/laundry.jpg" teks="Cuci dan setrika kiloan, selesai dalam 1×24 jam. Tamu menginap cukup pesan dari kamar; tamu luar bisa mengantar cucian ke resepsionis.">
        <a href="#pesan-laundry" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center rounded-sm bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline sm:w-auto">Pesan laundry</a>
        <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm border border-[rgba(242,235,220,.38)] px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline sm:w-auto">
            <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Tanya dulu
        </a>
    </x-tamu.kepala-halaman>

    <div class="wt-container max-w-[1120px] py-10 sm:py-14">
        {{-- Harga dan alur --}}
        <div class="grid gap-4 md:grid-cols-[320px_minmax(0,1fr)]">
            <div class="wt-card flex flex-col justify-center p-6">
                <div class="wt-eyebrow">{{ $layanan?->name ?? 'Laundry kiloan' }}</div>
                <div class="mt-2 font-display text-[34px] font-extrabold leading-none tabular-nums">{{ $rp($layanan?->price) }}<span class="text-base font-semibold text-ink-500">/kg</span></div>
                <ul class="m-0 mt-4 flex list-none flex-col gap-1.5 p-0 text-sm text-ink-900">
                    <li>Cuci, kering, dan setrika rapi</li>
                    <li>Minimal {{ $minKg }} kg per pesanan</li>
                    <li>Selesai 1×24 jam sejak ditimbang</li>
                </ul>
            </div>
            <ol class="m-0 grid list-none gap-3 p-0 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['Pesan', 'Isi formulir di bawah. Tamu menginap memilih kamar; tamu luar mengisi nama dan WhatsApp.'],
                    ['Serahkan cucian', 'Kami jemput ke kamar Anda, atau antar sendiri ke resepsionis.'],
                    ['Ditimbang', 'Tagihan dihitung dari berat dan dikirim ke WhatsApp.'],
                    ['Selesai', 'Diantar ke kamar, atau diambil di resepsionis.'],
                ] as $i => [$judul, $isi])
                    <li class="wt-card p-4">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-cream-200 font-display text-xs font-bold text-brown-800">{{ $i + 1 }}</span>
                        <div class="mt-2.5 font-display text-[13px] font-bold uppercase tracking-[.06em]">{{ $judul }}</div>
                        <p class="mb-0 mt-1 text-[13px] leading-relaxed text-ink-500">{{ $isi }}</p>
                    </li>
                @endforeach
            </ol>
        </div>

        {{-- Formulir --}}
        <form id="pesan-laundry" wire:submit="kirim" class="wt-card mt-8 scroll-mt-24 overflow-hidden" novalidate>
            <div class="border-b border-cream-300 bg-cream-100 px-5 py-4 sm:px-6">
                <h2 class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.06em]">Pesan laundry</h2>
                {{-- Saran masuk paling atas, sebelum isian pertama --}}
                <x-tawaran-masuk class="mt-3" :manfaat="$menginap
                    ? 'kamar Anda langsung tampil tanpa kode kamar dan pesanan tersimpan di Pesanan saya'
                    : 'data pemesan terisi otomatis dan status laundry tampil di Pesanan saya'" />
                <div class="mt-3 inline-flex rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Jenis tamu">
                    @foreach (['menginap' => 'Saya sedang menginap', 'luar' => 'Saya tamu luar'] as $v => $l)
                        <button type="button" wire:click="$set('jenis', '{{ $v }}')" role="tab" aria-selected="{{ $jenis === $v ? 'true' : 'false' }}"
                                @class(['h-9 rounded-md border-0 px-4 text-[13px] font-semibold transition-colors',
                                    'bg-brown-800 text-cream-50' => $jenis === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $jenis !== $v])>{{ $l }}</button>
                    @endforeach
                </div>
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
                            ['hp', 'Nomor WhatsApp', 'tel', 'tel', 'Contoh: 081234567890'],
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
</div>
