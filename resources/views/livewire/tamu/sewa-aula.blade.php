@php
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $tanyaWa = 'https://wa.me/'.($wisma['whatsapp'] ?? '').'?text='.rawurlencode('Halo Wisma Trans, saya ingin bertanya tentang sewa aula.');
@endphp

<div class="animate-wt-masuk">
    <x-tamu.kepala-halaman judul="Sewa Aula" gambar="ds/img/aula.jpg"
                           teks="Ruang serbaguna untuk rapat, pelatihan, syukuran, dan acara keluarga di pusat kota Malili. Ajukan jadwal Anda; harga sewa dibicarakan langsung dengan staf kami.">
        <a href="#ajukan-aula" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center rounded-sm bg-clay-500 px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline sm:w-auto">Ajukan sewa</a>
        <a href="{{ $tanyaWa }}" target="_blank" rel="noopener" class="inline-flex h-12 w-full max-w-[260px] items-center justify-center gap-2.5 rounded-sm border border-[rgba(242,235,220,.38)] px-7 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline sm:w-auto">
            <img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="" width="16" height="16" class="brightness-0 invert">Tanya dulu
        </a>
    </x-tamu.kepala-halaman>

    <div class="wt-container max-w-[1120px] py-10 sm:py-14">
        @foreach ($daftarAula as $a)
            <div wire:key="aula-{{ $a->id }}" class="wt-card grid overflow-hidden md:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                <img src="{{ $a->fotoUtama() }}" alt="{{ $a->name }}" class="h-[240px] w-full object-cover md:h-full">
                <div class="flex flex-col p-6">
                    <div class="wt-eyebrow">Aula</div>
                    <h2 class="mb-0 mt-1.5 font-display text-[26px] font-extrabold uppercase tracking-[.03em]">{{ $a->name }}</h2>
                    <p class="mb-0 mt-2 text-sm leading-relaxed text-ink-500">{{ $a->description }}</p>
                    <dl class="m-0 mt-5 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-md bg-cream-200 px-4 py-3"><dt class="text-xs text-ink-500">Kapasitas</dt><dd class="m-0 font-display text-xl font-extrabold">{{ $a->capacity }} orang</dd></div>
                        <div class="rounded-md bg-cream-200 px-4 py-3"><dt class="text-xs text-ink-500">Harga sewa</dt><dd class="m-0 font-display text-[15px] font-bold leading-snug">Sesuai kesepakatan</dd></div>
                    </dl>
                    <ul class="m-0 mt-5 grid list-none gap-1.5 p-0 text-sm sm:grid-cols-2">
                        @foreach ([...$fasilitas, 'Bisa dipadukan dengan menginap'] as $f)
                            <li class="flex items-center gap-2"><svg class="flex-none text-status-available" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>{{ $f }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endforeach

        <div class="mt-8">
            {{-- Formulir pengajuan --}}
            <section id="ajukan-aula" class="wt-card scroll-mt-24 overflow-hidden">
                <div class="border-b border-cream-300 bg-cream-100 px-5 py-4 sm:px-6">
                    <h2 class="m-0 font-display text-[15px] font-extrabold uppercase tracking-[.06em]">Ajukan sewa aula</h2>
                    <p class="mb-0 mt-0.5 text-xs text-ink-500">Tidak ada pembayaran sekarang. Staf kami menghubungi Anda untuk membicarakan kebutuhan acara dan harga.</p>
                </div>

                @if ($terkirim)
                    <div class="flex flex-col items-start gap-3 p-6" role="status">
                        <div class="flex h-11 w-11 items-center justify-center rounded-full bg-[rgba(92,158,103,.16)] text-status-available">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        </div>
                        <div class="font-display text-lg font-extrabold">Pengajuan {{ $terkirim }} terkirim</div>
                        <p class="m-0 text-sm text-ink-500">Konfirmasi dikirim ke WhatsApp {{ $hp }}. Staf kami menghubungi Anda pada jam kerja untuk membicarakan harga sewa.@auth Statusnya juga tampil di <a href="{{ route('pesanan') }}" class="font-semibold">Pesanan saya</a>.@endauth</p>
                        <button type="button" wire:click="$set('terkirim', null)" class="wt-btn-outline mt-1 h-10 px-5 text-xs !normal-case">Ajukan jadwal lain</button>
                    </div>
                @else
                    <form wire:submit="kirim" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6" novalidate>
                        @if ($daftarAula->count() > 1)
                            <label class="block sm:col-span-2"><span class="wt-field-label mb-1.5 block">Aula</span>
                                <select wire:model.live="aula" class="wt-input h-11">
                                    @foreach ($daftarAula as $a)<option value="{{ $a->id }}">{{ $a->name }} · {{ $a->capacity }} orang</option>@endforeach
                                </select></label>
                        @endif
                        <label class="block"><span class="wt-field-label mb-1.5 block">Tanggal acara</span>
                            <input type="date" wire:model.live="tanggal" min="{{ today()->toDateString() }}" class="wt-input h-11 {{ $galat('tanggal') }}">
                            @error('tanggal')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block"><span class="wt-field-label mb-1.5 block">Mulai</span>
                                <input type="time" wire:model.live="jamMulai" step="900" class="wt-input h-11 {{ $galat('jamMulai') }}"></label>
                            <label class="block"><span class="wt-field-label mb-1.5 block">Selesai</span>
                                <input type="time" wire:model.live="jamSelesai" step="900" class="wt-input h-11 {{ $galat('jamSelesai') }}"></label>
                        </div>
                        <div class="sm:col-span-2" x-data="{ lintas: @js($tanggalSelesai !== '') }">
                            <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" x-model="lintas" @change="if (!lintas) $wire.set('tanggalSelesai', '')" class="h-4 w-4 accent-brown-800"> Acara selesai di hari lain</label>
                            <label x-show="lintas" x-cloak class="mt-2 block max-w-[260px]"><span class="wt-field-label mb-1.5 block">Tanggal selesai</span>
                                <input type="date" wire:model.live="tanggalSelesai" min="{{ $tanggal ?: today()->toDateString() }}" class="wt-input h-11 {{ $galat('tanggalSelesai') }}"></label>
                            @foreach (['jamMulai', 'jamSelesai', 'tanggalSelesai', 'mulai', 'selesai'] as $f)
                                @error($f)<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                            @endforeach
                            @if ($bentrok)
                                <p class="mb-0 mt-2 rounded-md bg-[rgba(212,81,74,.08)] px-3 py-2 text-xs text-status-dirty" role="alert">Waktu ini bentrok dengan jadwal lain ({{ $bentrok->jadwal() }} WITA). Pilih jam atau tanggal lain.</p>
                            @endif
                        </div>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Jumlah peserta</span>
                            <input type="number" wire:model="peserta" min="1" max="{{ $dipilih?->capacity }}" inputmode="numeric" placeholder="Perkiraan" class="wt-input h-11 {{ $galat('peserta') }}">
                            @error('peserta')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Jenis acara</span>
                            <input type="text" wire:model="keperluan" maxlength="150" placeholder="Contoh: rapat, syukuran, pelatihan" class="wt-input h-11 {{ $galat('keperluan') }}">
                            @error('keperluan')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Nama penanggung jawab</span>
                            <input type="text" wire:model="nama" autocomplete="name" class="wt-input h-11 {{ $galat('nama') }}">
                            @error('nama')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Nomor WhatsApp</span>
                            <input type="tel" wire:model="hp" autocomplete="tel" placeholder="Contoh: 081234567890" class="wt-input h-11 {{ $galat('hp') }}">
                            @error('hp')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Email (opsional)</span>
                            <input type="email" wire:model="email" autocomplete="email" class="wt-input h-11 {{ $galat('email') }}">
                            @error('email')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <label class="block"><span class="wt-field-label mb-1.5 block">Instansi (opsional)</span>
                            <input type="text" wire:model="instansi" maxlength="150" placeholder="Bila atas nama instansi" class="wt-input h-11"></label>
                        <label class="block sm:col-span-2"><span class="wt-field-label mb-1.5 block">Kebutuhan lain (opsional)</span>
                            <textarea wire:model="catatan" rows="2" maxlength="200" placeholder="Contoh: proyektor, susunan kursi kelas, paket makan siang 80 orang" class="wt-input {{ $galat('catatan') }}"></textarea>
                            @error('catatan')<span class="mt-1.5 flex text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror</label>
                        <button type="submit" wire:loading.attr="disabled" class="h-12 rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600 disabled:opacity-60 sm:col-span-2">
                            <span wire:loading.remove wire:target="kirim">Kirim pengajuan</span><span wire:loading wire:target="kirim">Mengirim…</span>
                        </button>
                    </form>
                @endif
            </section>
        </div>
    </div>
</div>
