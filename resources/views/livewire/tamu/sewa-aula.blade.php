@php
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $tanyaWa = 'https://wa.me/'.($wisma['whatsapp'] ?? '').'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang sewa aula.');
    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
@endphp

{{-- Halaman formulir pengajuan; informasi, foto, dan fasilitas aula ada di halaman /aula (tamu.aula) --}}
<div class="animate-wt-masuk">
    <x-tamu.kepala-halaman judul="Ajukan Sewa Aula" gambar="ds/img/aula.jpg"
                           :teks="$dipilih?->description">
        <a href="{{ route('aula') }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-sm border border-[rgba(242,235,220,.38)] px-6 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline">
            <x-tamu.ikon nama="kiri" ukuran="16" />Info aula
        </a>
        <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="inline-flex h-11 items-center justify-center gap-2.5 rounded-sm border border-[rgba(242,235,220,.38)] px-6 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline">
            <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Tanya dulu
        </a>
    </x-tamu.kepala-halaman>

    <div class="wt-container max-w-[880px] py-10 sm:py-14">
        {{-- Ringkasan aula yang diajukan --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 rounded-lg border border-cream-300 bg-cream-50 px-5 py-3.5 text-sm" data-ringkasan-aula>
            <span><strong class="font-semibold">{{ $dipilih?->name ?? 'Aula' }}</strong>
                <span class="text-ink-500">· {{ \App\Models\Hall::KAPASITAS_PROMOSI }} orang · mulai {{ $rp($tarif['min']) }} / hari</span></span>
            <a href="{{ route('aula') }}" class="text-[13px] font-semibold text-olive-600">Lihat foto dan fasilitas</a>
        </div>

        <div>
            {{-- Formulir pengajuan --}}
            <section id="ajukan-aula" class="wt-card scroll-mt-24 overflow-hidden">
                <div class="border-b border-cream-300 bg-cream-100 px-5 py-4 sm:px-6">
                    <h2 class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.06em]">Ajukan sewa aula</h2>
                    <p class="mb-0 mt-0.5 text-xs text-ink-500">Isi data acara Anda. Tidak ada pembayaran sekarang. Staf kami akan menghubungi Anda lewat WhatsApp untuk membahas kebutuhan dan harga.</p>
                </div>

                @if ($terkirim)
                    <div class="flex flex-col items-start gap-3 p-6" role="status">
                        <div class="flex h-11 w-11 items-center justify-center rounded-full bg-[rgba(92,158,103,.16)] text-status-available">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        </div>
                        <div class="font-display text-lg font-extrabold">Pengajuan {{ $terkirim }} terkirim</div>
                        <p class="m-0 text-sm text-ink-500">Konfirmasi dikirim ke WhatsApp {{ $hp }}. Staf kami menghubungi Anda pada jam kerja untuk membicarakan harga sewa.@auth Statusnya juga tampil di <a href="{{ route('pesanan') }}" class="font-semibold">Pesanan saya</a>.@endauth</p>
                        <x-ajakan-akun :hp="$hp" class="w-full" />
                        <button type="button" wire:click="$set('terkirim', null)" class="wt-btn-outline mt-1 h-10 px-5 text-xs !normal-case">Ajukan jadwal lain</button>
                    </div>
                @else
                    {{-- Tamu belum masuk ditawari masuk/daftar (pop-up x-tawaran-akun) saat mulai mengisi: isian belum tersimpan bila halaman dimuat ulang --}}
                    <form wire:submit="kirim" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6" novalidate @guest x-data @focusin.once="$dispatch('tawar-akun', { varian: 'aula' })" @endguest>
                        @if ($daftarAula->count() > 1)
                            <label class="block sm:col-span-2"><span class="wt-field-label mb-1.5 block">Aula</span>
                                <select wire:model.live="aula" class="wt-input h-11">
                                    @foreach ($daftarAula as $a)<option value="{{ $a->id }}">{{ $a->name }} · {{ $a->capacity }} orang</option>@endforeach
                                </select></label>
                        @endif
                        <label class="block"><span class="wt-field-label mb-1.5 block">Tanggal mulai</span>
                            <input type="date" wire:model.live="tanggal" min="{{ today()->toDateString() }}" class="wt-input h-11 {{ $galat('tanggal') }}">
                            @error('tanggal')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block"><span class="wt-field-label mb-1.5 block">Jam mulai</span>
                                <input type="time" wire:model.live="jamMulai" step="900" class="wt-input h-11 {{ $galat('jamMulai') }}"></label>
                            <label class="block"><span class="wt-field-label mb-1.5 block">Jam selesai</span>
                                <input type="time" wire:model.live="jamSelesai" step="900" class="wt-input h-11 {{ $galat('jamSelesai') }}"></label>
                        </div>
                        <div class="sm:col-span-2" x-data="{ lintas: @js($tanggalSelesai !== '') }">
                            <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" x-model="lintas" @change="if (!lintas) $wire.set('tanggalSelesai', '')" class="h-4 w-4 accent-brown-800"> Acara lebih dari satu hari</label>
                            <label x-show="lintas" x-cloak class="mt-2 block max-w-[260px]"><span class="wt-field-label mb-1.5 block">Tanggal berakhir</span>
                                <input type="date" wire:model.live="tanggalSelesai" min="{{ $tanggal ?: today()->toDateString() }}" class="wt-input h-11 {{ $galat('tanggalSelesai') }}"></label>
                            @foreach (['jamMulai', 'jamSelesai', 'tanggalSelesai', 'mulai', 'selesai'] as $f)
                                @error($f)<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                            @endforeach
                            @if ($bentrok)
                                <p class="mb-0 mt-2 rounded-md bg-[rgba(212,81,74,.08)] px-3 py-2 text-xs text-status-dirty" role="alert">Waktu ini bentrok dengan jadwal lain ({{ $bentrok->jadwal() }} WITA). Pilih jam atau tanggal lain.</p>
                            @endif
                        </div>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Perkiraan jumlah peserta</span>
                            <input type="number" wire:model="peserta" min="1" max="{{ $dipilih?->capacity }}" inputmode="numeric" placeholder="Contoh: 50" class="wt-input h-11 {{ $galat('peserta') }}">
                            @error('peserta')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Nama atau jenis acara</span>
                            <input type="text" wire:model="keperluan" maxlength="150" placeholder="Contoh: Rapat kerja, syukuran, pelatihan" class="wt-input h-11 {{ $galat('keperluan') }}">
                            @error('keperluan')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Nama kontak</span>
                            <input type="text" wire:model="nama" autocomplete="name" placeholder="Nama yang akan kami hubungi" class="wt-input h-11 {{ $galat('nama') }}">
                            @error('nama')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Nomor WhatsApp</span>
                            <input type="tel" wire:model="hp" autocomplete="tel" placeholder="08xxxxxxxxxx" class="wt-input h-11 {{ $galat('hp') }}">
                            @error('hp')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Email (opsional)</span>
                            <input type="email" wire:model="email" autocomplete="email" placeholder="Untuk salinan konfirmasi" class="wt-input h-11 {{ $galat('email') }}">
                            @error('email')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Instansi/perusahaan (opsional)</span>
                            <input type="text" wire:model="instansi" maxlength="150" placeholder="Isi bila acara atas nama instansi" class="wt-input h-11"></label>
                        <label class="block sm:col-span-2"><span class="wt-field-label mb-1.5 block">Catatan tambahan (opsional)</span>
                            <textarea wire:model="catatan" rows="2" maxlength="200" placeholder="Contoh: Susunan kursi, konsumsi, jam coffee break, perlengkapan lain" class="wt-input {{ $galat('catatan') }}"></textarea>
                            @error('catatan')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <button type="submit" wire:loading.attr="disabled" class="h-12 rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 disabled:opacity-60 sm:col-span-2">
                            <span wire:loading.remove wire:target="kirim">Kirim pengajuan</span><span wire:loading wire:target="kirim">Mengirim…</span>
                        </button>
                    </form>
                @endif
            </section>
        </div>
    </div>
    <x-tawaran-akun kunci="wt.aula.tanpa-akun" :varian="[
        'aula' => ['manfaat' => [
                ['Pengajuan mudah dipantau', 'Status pengajuan dan jadwal acara tampil di menu Pesanan saya.'],
                ['Semua pengajuan di satu tempat', 'Tidak perlu menyimpan tautan untuk setiap pengajuan.'],
            ], 'lanjut' => 'Lanjutkan sebagai tamu', 'ingat' => true, 'catatan' => 'Sebaiknya masuk sebelum mengisi formulir agar isian tidak perlu diulang.'],
    ]" />
</div>
