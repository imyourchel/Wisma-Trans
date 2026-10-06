@php
    $isian = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
    $pengguna = auth()->user();
    $pelanggan = $pengguna?->customer;
@endphp
<x-layouts.guest title="Pengajuan reservasi instansi">
<div class="wt-container animate-wt-masuk max-w-[880px] py-12 sm:py-16">
    <div class="wt-eyebrow">Instansi dan rombongan</div>
    <h1 class="mb-0 mt-1.5 font-display text-[28px] font-extrabold leading-tight tracking-[.02em]">Ajukan reservasi instansi</h1>
    <p class="mb-0 mt-2 max-w-[640px] text-sm leading-relaxed text-ink-500">
        Untuk kegiatan kantor, dinas, atau rombongan. Tidak perlu uang muka online: Manager kami memeriksa ketersediaan,
        menetapkan kamar dan harga yang disepakati, lalu menghubungi narahubung. Tagihan dikirim ke instansi sesuai skema penagihannya.
    </p>

    <form method="POST" action="{{ route('instansi.store') }}" class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]" novalidate>
        @csrf
        <div class="wt-card flex flex-col gap-5 p-5 sm:p-6">
            <fieldset class="m-0 grid min-w-0 gap-4 border-0 p-0 sm:grid-cols-2">
                <legend class="mb-3 p-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">Instansi dan narahubung</legend>
                @foreach ([
                    ['instansi', 'Nama instansi', 'text', 'organization', 'Contoh: Dinas Kesehatan Luwu Timur', 'sm:col-span-2', null],
                    ['name', 'Nama narahubung', 'text', 'name', 'Nama lengkap', '', $pelanggan?->full_name ?? $pengguna?->name],
                    ['phone', 'Nomor WhatsApp', 'tel', 'tel', 'Contoh: 081234567890', '', $pelanggan?->phone ?? $pengguna?->phone],
                    ['email', 'Email (opsional)', 'email', 'email', 'Untuk konfirmasi dan tagihan', 'sm:col-span-2', $pelanggan?->email ?? $pengguna?->email],
                ] as [$f, $label, $type, $auto, $ph, $kolom, $bawaan])
                    <label class="block {{ $kolom }}">
                        <span class="wt-field-label mb-1.5 block">{{ $label }}</span>
                        <input type="{{ $type }}" name="{{ $f }}" value="{{ old($f, $bawaan) }}" autocomplete="{{ $auto }}" placeholder="{{ $ph }}" class="wt-input h-11 {{ $isian($f) }}">
                        @error($f)<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </fieldset>

            <fieldset class="m-0 grid min-w-0 gap-4 border-0 border-t border-cream-300 p-0 pt-5 sm:grid-cols-3">
                <legend class="float-left mb-3 w-full p-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">Kebutuhan kamar</legend>
                @foreach ([
                    ['masuk', 'Check-in', 'date', today()->toDateString()],
                    ['keluar', 'Check-out', 'date', today()->addDay()->toDateString()],
                ] as [$f, $label, $type, $min])
                    <label class="block">
                        <span class="wt-field-label mb-1.5 block">{{ $label }}</span>
                        <input type="{{ $type }}" name="{{ $f }}" value="{{ old($f, request($f)) }}" min="{{ $min }}" class="wt-input h-11 {{ $isian($f) }}">
                        @error($f)<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                    </label>
                @endforeach
                <label class="block">
                    <span class="wt-field-label mb-1.5 block">Jumlah kamar</span>
                    <input type="number" name="jumlah" value="{{ old('jumlah', request('kamar')) }}" min="1" max="32" inputmode="numeric" placeholder="Perkiraan" class="wt-input h-11 {{ $isian('jumlah') }}">
                    @error('jumlah')<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="block sm:col-span-3">
                    <span class="wt-field-label mb-1.5 block">Nama kegiatan (opsional)</span>
                    <input type="text" name="kegiatan" value="{{ old('kegiatan') }}" maxlength="150" placeholder="Contoh: Monitoring puskesmas" class="wt-input h-11 {{ $isian('kegiatan') }}">
                    @error('kegiatan')<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="block sm:col-span-3">
                    <span class="wt-field-label mb-1.5 block">Catatan (opsional)</span>
                    <textarea name="catatan" rows="3" maxlength="500" placeholder="Contoh: butuh 2 kamar keluarga, sewa aula hari kedua" class="wt-input {{ $isian('catatan') }}">{{ old('catatan') }}</textarea>
                    @error('catatan')<span class="mt-1.5 flex text-xs font-medium leading-snug text-status-dirty" role="alert">{{ $message }}</span>@enderror
                </label>
            </fieldset>
        </div>

        <aside class="flex flex-col gap-4">
            <div class="rounded-lg bg-cream-200 p-5 text-sm leading-relaxed">
                <div class="font-display text-[13px] font-bold uppercase tracking-[.08em]">Alurnya</div>
                <ol class="m-0 mt-3 flex list-none flex-col gap-3 p-0">
                    @foreach (['Kirim pengajuan ini.', 'Manager memeriksa ketersediaan, lalu menetapkan kamar dan harga.', 'Anda menerima kabar lewat WhatsApp; statusnya bisa dicek kapan saja.'] as $i => $t)
                        <li class="flex gap-3"><span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-cream-50 font-display text-xs font-bold text-brown-800">{{ $i + 1 }}</span><span class="pt-0.5">{{ $t }}</span></li>
                    @endforeach
                </ol>
            </div>
            <button class="h-12 rounded border-0 bg-clay-500 font-display text-[13px] font-bold uppercase tracking-[.08em] text-cream-50 hover:bg-clay-600">Kirim pengajuan</button>
            <p class="m-0 text-xs text-ink-500">Butuh cepat? Hubungi kami di <a href="https://wa.me/{{ $wisma['whatsapp'] ?? '' }}" target="_blank" rel="noopener">WhatsApp</a>.</p>
        </aside>
    </form>
</div>
</x-layouts.guest>
