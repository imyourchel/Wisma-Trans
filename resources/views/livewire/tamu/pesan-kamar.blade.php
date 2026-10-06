@php
    use App\Services\PembayaranMidtrans;
    use App\Services\ReservasiService;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $kolomGalat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
@endphp

<div class="wt-container animate-wt-masuk max-w-[1120px] py-8 sm:py-12">
    <a href="{{ $urlKembali }}" class="mb-5 inline-flex items-center gap-1.5 text-[13px] text-ink-500 hover:text-clay-600">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Ubah pilihan kamar
    </a>

    {{-- Tahapan --}}
    <ol class="m-0 mb-7 flex list-none flex-wrap items-center gap-x-3 gap-y-2 p-0 text-[13px]" aria-label="Tahapan pemesanan">
        @foreach (['Pilih kamar', 'Data pemesan', 'Bayar uang muka'] as $i => $langkah)
            <li class="flex items-center gap-2">
                <span @class(['flex h-6 w-6 items-center justify-center rounded-full font-display text-[11px] font-bold',
                    'bg-status-available text-cream-50' => $i === 0, 'bg-clay-500 text-cream-50' => $i === 1, 'bg-cream-300 text-ink-500' => $i === 2])>
                    @if ($i === 0)
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    @else
                        {{ $i + 1 }}
                    @endif
                </span>
                <span @class(['font-semibold text-ink-900' => $i === 1, 'text-ink-500' => $i !== 1]) @if ($i === 1) aria-current="step" @endif>{{ $langkah }}</span>
                @unless ($loop->last)<span class="h-px w-6 bg-cream-400" aria-hidden="true"></span>@endunless
            </li>
        @endforeach
    </ol>

    <h1 class="m-0 font-display text-[26px] font-extrabold leading-tight tracking-[.02em] sm:text-[30px]">Data pemesan</h1>
    <p class="mb-0 mt-1.5 text-sm text-ink-500">Konfirmasi dan tautan cek pesanan dikirim ke WhatsApp Anda. Tidak perlu membuat akun.</p>

    @if ($galat)
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] px-5 py-4" role="alert">
            <span class="text-sm text-ink-900">{{ $galat }}</span>
            <a href="{{ $urlKembali }}" class="wt-btn-outline h-9 px-4 text-xs !normal-case">Pilih ulang kamar</a>
        </div>
    @endif

    <form wire:submit="pesan" class="mt-7 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]" novalidate>
        <div class="flex min-w-0 flex-col gap-6">
            <section class="wt-card p-5 sm:p-6" aria-labelledby="judul-pemesan">
                <h2 id="judul-pemesan" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">Pemesan</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['nama', 'Nama lengkap', 'text', 'name', 'Sesuai kartu identitas', 'sm:col-span-2'],
                        ['hp', 'Nomor WhatsApp', 'tel', 'tel', 'Contoh: 081234567890', ''],
                        ['email', 'Email (opsional)', 'email', 'email', 'Untuk bukti pembayaran', ''],
                    ] as [$f, $label, $type, $auto, $ph, $kolom])
                        <label class="block {{ $kolom }}">
                            <span class="wt-field-label mb-1.5 block">{{ $label }}</span>
                            <input type="{{ $type }}" wire:model="{{ $f }}" autocomplete="{{ $auto }}" placeholder="{{ $ph }}" class="wt-input h-11 text-[15px] {{ $kolomGalat($f) }}">
                            @error($f)<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                    <label class="block sm:col-span-2">
                        <span class="wt-field-label mb-1.5 block">Permintaan khusus (opsional)</span>
                        <textarea wire:model="permintaan" rows="2" maxlength="500" placeholder="Contoh: kamar di lantai 2, tiba sekitar pukul 20.00" class="wt-input {{ $kolomGalat('permintaan') }}"></textarea>
                        <span class="mt-1.5 block text-xs text-ink-500">Permintaan disesuaikan dengan ketersediaan saat check-in.</span>
                        @error('permintaan')<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                    </label>
                </div>
            </section>

            <section class="wt-card p-5 sm:p-6" aria-labelledby="judul-metode">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 id="judul-metode" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">Metode bayar uang muka</h2>
                    @error('metode')<span class="text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                </div>
                <div @class(['mt-4 flex flex-col divide-y divide-cream-300 overflow-hidden rounded-lg border', 'border-status-dirty' => $errors->has('metode'), 'border-cream-300' => ! $errors->has('metode')])>
                    @foreach (PembayaranMidtrans::METODE as $kunci => [$namaMetode, $ket])
                        <label class="relative flex cursor-pointer items-center gap-3 px-3.5 py-2.5 transition hover:bg-row-hover has-[:checked]:bg-[rgba(181,113,74,.07)]">
                            <input type="radio" wire:model="metode" value="{{ $kunci }}" class="peer sr-only">
                            <span class="flex h-9 w-16 flex-none items-center justify-center rounded border border-cream-300 bg-white px-1.5">
                                <img src="{{ asset('ds/bank/'.$kunci.'.svg') }}" alt="" class="max-h-5 max-w-full object-contain">
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold leading-snug">{{ $namaMetode }}</span>
                                <span class="block truncate text-xs text-ink-500">{{ $ket }}</span>
                            </span>
                            <span class="h-4 w-4 flex-none rounded-full border border-cream-400 peer-checked:border-[5px] peer-checked:border-clay-500"></span>
                        </label>
                    @endforeach
                </div>
                <p class="mb-0 mt-3 text-xs leading-relaxed text-ink-500">Nomor pembayaran tampil setelah reservasi dibuat. Bayar dalam {{ $jamBayar }} jam; lewat dari itu reservasi dibatalkan otomatis.</p>
            </section>
        </div>

        {{-- Ringkasan --}}
        <aside class="wt-card overflow-hidden lg:sticky lg:top-24" aria-labelledby="judul-ringkasan">
            <div class="border-b border-cream-300 bg-cream-100 px-5 py-4">
                <h2 id="judul-ringkasan" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">Ringkasan reservasi</h2>
            </div>
            @if ($cari->masuk)
                <div class="grid grid-cols-2 divide-x divide-cream-300 border-b border-cream-300">
                    <div class="px-5 py-3.5">
                        <div class="text-xs text-ink-500">Check-in</div>
                        <div class="font-display text-[15px] font-bold">{{ $cari->masuk->translatedFormat('D, j M Y') }}</div>
                        <div class="text-xs text-ink-500">mulai {{ ReservasiService::jamCheckIn() }} WITA</div>
                    </div>
                    <div class="px-5 py-3.5">
                        <div class="text-xs text-ink-500">Check-out</div>
                        <div class="font-display text-[15px] font-bold">{{ $cari->keluar->translatedFormat('D, j M Y') }}</div>
                        <div class="text-xs text-ink-500">{{ $malam }} malam</div>
                    </div>
                </div>
            @endif

            <ul class="m-0 flex list-none flex-col gap-2 border-b border-cream-300 px-5 py-4 text-sm">
                @foreach ($kamar as $k)
                    <li class="flex items-center justify-between gap-3">
                        <span class="min-w-0">
                            <span class="block font-semibold">{{ $k['tipe']->name }}</span>
                            <span class="block text-xs text-ink-500">Kamar {{ $k['no'] }} · {{ $k['dewasa'] }} dewasa{{ $k['anak'] ? ', '.$k['anak'].' anak' : '' }}</span>
                        </span>
                        <span class="flex-none text-right text-xs tabular-nums text-ink-500">{{ $rp($k['tipe']->base_price) }}<span class="block">/malam</span></span>
                    </li>
                @endforeach
            </ul>

            <dl class="m-0 flex flex-col gap-2 px-5 py-4 text-sm">
                @foreach ($kelompok as $g)
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">{{ $g['tipe']->name }} × {{ $g['jumlah'] }} · {{ $malam }} malam</dt>
                        <dd class="m-0 tabular-nums">{{ $rp((float) $g['tipe']->base_price * $g['jumlah'] * $malam) }}</dd>
                    </div>
                @endforeach
                <div class="mt-1 flex items-baseline justify-between gap-3 border-t border-cream-300 pt-3">
                    <dt class="font-semibold">Total kamar</dt>
                    <dd class="m-0 font-display text-lg font-extrabold tabular-nums">{{ $rp($total) }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 rounded-md bg-[rgba(181,113,74,.08)] px-3 py-2.5">
                    <dt class="font-semibold text-clay-600">Uang muka {{ $persen }}%</dt>
                    <dd class="m-0 font-display text-xl font-extrabold tabular-nums text-clay-600">{{ $rp($uangMuka) }}</dd>
                </div>
                <div class="flex justify-between gap-3 text-xs">
                    <dt class="text-ink-500">Sisa dilunasi di resepsionis</dt>
                    <dd class="m-0 tabular-nums">{{ $rp($total - $uangMuka) }}</dd>
                </div>
            </dl>

            <div class="border-t border-cream-300 px-5 py-4">
                @error('kamar')<p class="m-0 mb-3 text-xs font-medium text-status-dirty" role="alert">{{ $message }}</p>@enderror
                @error('tanggal')<p class="m-0 mb-3 text-xs font-medium text-status-dirty" role="alert">{{ $message }}</p>@enderror
                <button type="submit" @disabled($galat) wire:loading.attr="disabled"
                        class="h-12 w-full rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 disabled:cursor-not-allowed disabled:opacity-60">
                    <span wire:loading.remove wire:target="pesan">Pesan dan bayar {{ $rp($uangMuka) }}</span>
                    <span wire:loading wire:target="pesan">Membuat reservasi…</span>
                </button>
                <p class="mb-0 mt-3 text-xs leading-relaxed text-ink-500">
                    Pembatalan lebih dari {{ ReservasiService::hariMinimalBatal() }} hari sebelum kedatangan: uang muka kembali {{ ReservasiService::persenPengembalian() }}%.
                    Setelah itu, atau bila tidak datang, uang muka hangus.
                </p>
            </div>
        </aside>
    </form>
</div>
