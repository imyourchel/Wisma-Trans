@php
    use App\Models\Room;
    use App\Services\Keuangan\PembayaranMidtrans;
    use App\Services\Reservasi\ReservasiService;
    use App\Support\PilihanKamar;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $kolomGalat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $rentang = $cari->masuk ? $cari->masuk->translatedFormat('j M').' – '.$cari->keluar->translatedFormat('j M Y').' · '.$malam.' malam' : '';
@endphp

<div class="wt-container animate-wt-masuk max-w-[1188px] py-8 sm:py-10">
    <a href="{{ $urlKembali }}" class="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-500 hover:text-clay-600">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Ubah pilihan kamar
    </a>

    <div class="font-display text-[11px] font-semibold uppercase tracking-[.22em] text-clay-600">Langkah 2 dari 3 · Data pemesan dan pembayaran</div>
    <h1 class="mb-0 mt-2 font-display text-[26px] font-extrabold uppercase leading-tight tracking-[.03em] sm:text-[32px]">Selesaikan pemesanan</h1>
    <p class="mb-0 mt-1.5 text-sm text-ink-500">Konfirmasi dan tautan cek pesanan dikirim ke WhatsApp Anda. Tidak perlu membuat akun.</p>

    @if ($galat)
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[rgba(212,81,74,.35)] bg-[rgba(212,81,74,.06)] px-5 py-4" role="alert">
            <span class="text-sm text-ink-900">{{ $galat }}</span>
            <a href="{{ $urlKembali }}" class="wt-btn-outline h-9 px-4 text-xs !normal-case">Pilih ulang kamar</a>
        </div>
    @endif

    {{-- Di bawah lg: tombol bayar menempel di bawah layar --}}
    <form wire:submit="pesan" class="mt-7 grid items-start gap-6 max-lg:pb-24 lg:grid-cols-[minmax(0,1fr)_380px]" novalidate>
        <div class="wt-card min-w-0 divide-y divide-cream-300 p-0">
            {{-- Data pemesan --}}
            <section class="p-5 sm:p-6" aria-labelledby="judul-pemesan">
                <h2 id="judul-pemesan" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.1em]">Data pemesan</h2>
                {{-- Tanggal, tamu, dan kamar terpilih ada di alamat halaman, jadi tetap ada setelah masuk --}}
                <x-tawaran-masuk class="mt-4" manfaat="data pemesan terisi otomatis dan reservasi tersimpan di Pesanan saya" />
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['nama', 'Nama lengkap', 'text', 'name', 'Sesuai kartu identitas', 'sm:col-span-2'],
                        ['hp', 'Nomor WhatsApp', 'tel', 'tel', '08xxxxxxxxxx', ''],
                        ['email', 'Email (opsional)', 'email', 'email', 'Untuk bukti pembayaran', ''],
                    ] as [$f, $label, $type, $auto, $ph, $kolom])
                        <label class="block {{ $kolom }}">
                            <span class="wt-field-label mb-1.5 block">{{ $label }}</span>
                            <input type="{{ $type }}" wire:model="{{ $f }}" autocomplete="{{ $auto }}" placeholder="{{ $ph }}" class="wt-input h-11 text-[15px] {{ $kolomGalat($f) }}">
                            @error($f)<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                </div>
            </section>

            {{-- Tambahan per kamar (Extra bed, Sarapan tambahan); harga dan keterangan dari tabel services --}}
            @if ($layananTambahan['extra'] && $kamar)
                <section class="p-5 sm:p-6" aria-labelledby="judul-tambahan" data-bagian-tambahan>
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 id="judul-tambahan" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.1em]">Tambahan per kamar <span class="font-normal normal-case tracking-normal text-ink-500">(opsional)</span></h2>
                        @error('tambahan')<span class="text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <p class="mb-0 mt-1.5 text-xs leading-relaxed text-ink-500">Biaya tambahan ikut dihitung dalam total dan uang muka.</p>
                    <div class="mt-4 flex flex-col gap-3">
                        @foreach ($kamar as $k)
                            <x-tambahan-kamar :kunci="$k['no']" :judul="$k['tipe']->name.($k['kasur'] ? ' · '.Room::labelKasur($k['kasur']) : '')" :tipe="$k['tipe']" :pilih="$pilihanTambahan[$k['no']]"
                                              :kasur="$k['kasur']" :wajib="$k['wajib']" :layanan="$layananTambahan" :subjudul="'Kamar '.$k['no'].' · '.PilihanKamar::teksTamu($k['dewasa'], $k['anak'])" wire:key="tambahan-{{ $k['no'] }}" />
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="p-5 sm:p-6">
                <label class="block">
                    <span class="font-display text-[13px] font-bold uppercase tracking-[.1em]">Permintaan khusus <span class="font-normal normal-case tracking-normal text-ink-500">(opsional)</span></span>
                    <textarea wire:model="permintaan" rows="2" maxlength="500" placeholder="Contoh: kamar berdekatan, tiba sekitar pukul 20.00" class="wt-input mt-3 {{ $kolomGalat('permintaan') }}"></textarea>
                    <span class="mt-1.5 block text-xs text-ink-500">Permintaan disesuaikan dengan ketersediaan saat check-in.</span>
                    @error('permintaan')<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                </label>
            </section>

            <section class="p-5 sm:p-6" aria-labelledby="judul-metode">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 id="judul-metode" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.1em]">Metode pembayaran uang muka</h2>
                    @error('metode')<span class="text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                </div>
                <div class="mt-4 grid grid-cols-1 gap-2.5 sm:grid-cols-2" role="radiogroup" aria-labelledby="judul-metode">
                    @foreach (PembayaranMidtrans::METODE as $kunci => [$namaMetode, $ket])
                        <label @class(['relative flex min-w-0 cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-3 transition hover:bg-row-hover has-[:checked]:border-clay-500 has-[:checked]:bg-cream-100 has-[:checked]:ring-1 has-[:checked]:ring-clay-500',
                            'border-status-dirty' => $errors->has('metode'), 'border-cream-300' => ! $errors->has('metode')])>
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
        <aside class="wt-card overflow-hidden p-0 lg:sticky lg:top-24" aria-labelledby="judul-ringkasan" data-ringkasan-pesan>
            <div class="px-5 pb-4 pt-5 sm:px-6">
                <h2 id="judul-ringkasan" class="m-0 font-display text-[11px] font-semibold uppercase tracking-[.22em] text-clay-600">Ringkasan</h2>
                @if ($cari->masuk)
                    <div class="mt-2 font-display text-[17px] font-bold">{{ $rentang }}</div>
                    <div class="mt-0.5 text-[13px] text-ink-500">{{ PilihanKamar::teksTamu($jumlahTamu['dewasa'], $jumlahTamu['anak']) }} · check-in {{ ReservasiService::jamCheckIn() }} WITA</div>
                @endif
            </div>

            <dl class="m-0 flex flex-col gap-2 border-t border-cream-300 px-5 py-4 text-sm sm:px-6">
                @foreach ($kelompok as $g)
                    <div class="flex justify-between gap-3" data-kelompok-kamar>
                        <dt class="text-ink-900">{{ $g['tipe']->name }}{{ $g['kasur'] ? ' · '.Room::labelKasur($g['kasur']) : '' }} × {{ $g['jumlah'] }}</dt>
                        <dd class="m-0 flex-none tabular-nums">{{ $rp((float) $g['tipe']->base_price * $g['jumlah'] * $malam) }}</dd>
                    </div>
                @endforeach
                @foreach ($rincianTambahan as $t)
                    <div class="flex justify-between gap-3" data-ringkasan-tambahan>
                        <dt class="text-ink-500">{{ $t['teks'] }}</dt>
                        <dd class="m-0 flex-none tabular-nums">{{ $rp($t['subtotal']) }}</dd>
                    </div>
                @endforeach
                <div class="mt-1 flex items-baseline justify-between gap-3 border-t border-cream-300 pt-3">
                    <dt class="font-semibold">Total menginap</dt>
                    <dd class="m-0 font-semibold tabular-nums">{{ $rp($total) }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="font-semibold text-clay-600">Bayar sekarang <span class="font-normal">(uang muka {{ $persen }}%)</span></dt>
                    <dd class="m-0 font-display text-[22px] font-extrabold tabular-nums text-clay-600">{{ $rp($uangMuka) }}</dd>
                </div>
                <div class="flex justify-between gap-3 text-[13px]">
                    <dt class="text-ink-500">Sisa dibayar saat check-out</dt>
                    <dd class="m-0 tabular-nums text-ink-500">{{ $rp($total - $uangMuka) }}</dd>
                </div>
            </dl>

            <div class="border-t border-cream-300 px-5 pb-5 pt-4 sm:px-6">
                @error('kamar')<p class="m-0 mb-3 text-xs font-medium text-status-dirty" role="alert">{{ $message }}</p>@enderror
                @error('tanggal')<p class="m-0 mb-3 text-xs font-medium text-status-dirty" role="alert">{{ $message }}</p>@enderror
                @error('tambahan')<p class="m-0 mb-3 text-xs font-medium text-status-dirty" role="alert">{{ $message }}</p>@enderror
                <button type="submit" @disabled($galat) wire:loading.attr="disabled"
                        class="h-12 w-full rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.1em] text-cream-50 hover:bg-clay-600 disabled:cursor-not-allowed disabled:opacity-60 max-lg:hidden">
                    <span wire:loading.remove wire:target="pesan">Bayar {{ $rp($uangMuka) }}</span>
                    <span wire:loading wire:target="pesan">Membuat reservasi…</span>
                </button>
                <p class="mb-0 mt-3 text-xs leading-relaxed text-ink-500">
                    Satu kode pesanan untuk semua kamar. Pembatalan lebih dari {{ ReservasiService::hariMinimalBatal() }} hari sebelum kedatangan: uang muka kembali {{ ReservasiService::persenPengembalian() }}%.
                    Setelah itu, atau bila tidak datang, uang muka hangus.
                </p>
            </div>
        </aside>

        {{-- Bar bawah (di bawah lg): uang muka dan tombol bayar selalu terlihat --}}
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-cream-300 bg-cream-50 px-4 pb-[max(12px,env(safe-area-inset-bottom))] pt-3 shadow-[0_-10px_28px_-14px_rgba(62,42,28,.35)] lg:hidden" data-bar-bayar>
            <div class="mx-auto flex max-w-[720px] items-center gap-3">
                <button type="button" @click="document.getElementById('judul-ringkasan').scrollIntoView({ behavior: 'smooth', block: 'start' })"
                        class="min-w-0 flex-1 border-0 bg-transparent p-0 text-left">
                    <span class="block text-[11px] text-ink-500">Uang muka {{ $persen }}% · <span class="underline underline-offset-2">rincian</span></span>
                    <span class="block truncate font-display text-lg font-extrabold tabular-nums text-clay-600">{{ $rp($uangMuka) }}</span>
                </button>
                <button type="submit" @disabled($galat) wire:loading.attr="disabled"
                        class="h-12 flex-none rounded border-0 bg-clay-500 px-5 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 disabled:cursor-not-allowed disabled:opacity-60">
                    <span wire:loading.remove wire:target="pesan">Bayar {{ $rp($uangMuka) }}</span>
                    <span wire:loading wire:target="pesan">Memproses…</span>
                </button>
            </div>
        </div>
    </form>
</div>
