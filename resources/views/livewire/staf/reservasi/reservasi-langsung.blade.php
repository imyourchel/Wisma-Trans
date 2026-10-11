@php
    use App\Enums\RoomStatus;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
@endphp

<form wire:submit="simpan" class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]" novalidate>
    <div class="flex min-w-0 flex-col gap-5">
        {{-- 1. Tanggal --}}
        <section class="wt-card p-5">
            <h2 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">1. Tanggal menginap</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <x-staf.isian nama="masuk" label="Check-in"><input type="date" wire:model.live="masuk" min="{{ today()->toDateString() }}" class="wt-input {{ $galat('masuk') }}"></x-staf.isian>
                <x-staf.isian nama="keluar" label="Check-out"><input type="date" wire:model.live="keluar" min="{{ today()->addDay()->toDateString() }}" class="wt-input {{ $galat('keluar') }}"></x-staf.isian>
                <div class="pb-2.5 text-[13px] text-ink-500">{{ $malam ? $malam.' malam' : '' }}</div>
            </div>
        </section>

        {{-- 2. Kamar --}}
        <section class="wt-card p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">2. Pilih kamar</h2>
                <span class="flex flex-wrap gap-3 text-[12px] text-ink-500">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ RoomStatus::Available->color() }}"></span>Siap</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ RoomStatus::VacantDirty->color() }}"></span><span class="h-2.5 w-2.5 rounded-full" style="background: {{ RoomStatus::Cleaning->color() }}"></span>Belum siap; check-in setelah dibersihkan</span>
                </span>
            </div>
            @error('kamar')<p class="m-0 mt-2 text-xs font-medium text-status-dirty" role="alert">{{ $message }}</p>@enderror
            @if ($peringatanKamar)
                <p class="m-0 mt-2 rounded bg-[rgba(232,172,69,.14)] px-3 py-2 text-[13px] text-[#7A520C]" role="status" data-peringatan-kamar>{{ $peringatanKamar }}</p>
            @endif
            @if (! $tanggalValid)
                <p class="m-0 mt-3 text-[13px] text-ink-500">Pilih tanggal check-in dan check-out yang benar untuk melihat kamar kosong.</p>
            @else
                <div class="mt-3 flex flex-col gap-4">
                    @foreach ($tipe as $t)
                        @php $kamarTipe = $perTipe->get($t->id, collect()); @endphp
                        <div wire:key="tipe-{{ $t->id }}">
                            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2 text-[13px]">
                                <span class="font-semibold">{{ $t->name }} <span class="font-normal text-ink-500">· {{ $rp($t->base_price) }}/malam · maks {{ $t->max_capacity }} orang</span></span>
                                <span class="text-[12px] text-ink-500">{{ $sisa[$t->id] ?? 0 }} kamar bisa dijual</span>
                            </div>
                            @if ($kamarTipe->isEmpty())
                                <p class="m-0 text-[12px] text-ink-500">Tidak ada kamar kosong.</p>
                            @else
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($kamarTipe as $k)
                                        @php $siap = $k->status === 'available'; @endphp
                                        <label wire:key="kmr-{{ $k->id }}" class="relative flex h-11 min-w-[74px] cursor-pointer items-center justify-center gap-1.5 rounded border border-cream-300 bg-cream-50 px-3 text-[13px] font-semibold tabular-nums transition hover:border-brown-800 has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-50"
                                               title="{{ RoomStatus::from($k->status)->label() }}">
                                            <input type="checkbox" wire:model.live="kamar" value="{{ $k->id }}" class="sr-only">
                                            <span class="h-2 w-2 rounded-full" style="background: {{ RoomStatus::from($k->status)->color() }}"></span>{{ $k->room_number }}
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- 3. Tamu --}}
        <section class="wt-card p-5">
            <h2 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">3. Data tamu</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <x-staf.isian nama="nama" label="Nama pemesan" class="sm:col-span-2"><input type="text" wire:model="nama" maxlength="100" autocomplete="off" class="wt-input {{ $galat('nama') }}"></x-staf.isian>
                <x-staf.isian nama="hp" label="Nomor HP / WhatsApp"><input type="tel" wire:model.blur="hp" autocomplete="off" placeholder="08xxxxxxxxxx" class="wt-input {{ $galat('hp') }}"></x-staf.isian>
                <x-staf.isian nama="email" label="Email (opsional)"><input type="email" wire:model="email" autocomplete="off" class="wt-input {{ $galat('email') }}"></x-staf.isian>
                @if ($profil)
                    <div class="flex flex-col gap-2 sm:col-span-2" data-pelanggan-lama>
                        <div class="text-[13px]">Pelanggan terdaftar: <strong>{{ $pelanggan->full_name }}</strong></div>
                        @include('livewire.staf.partials.kartu-profil-pelanggan', ['profil' => $profil, 'label' => \App\Services\Pelanggan\ProfilPelanggan::labelBerikutnya($profil)])
                        @if ($favoritBebas)
                            <button type="button" wire:click="pilihKamar({{ $favoritBebas['id'] }})" class="wt-btn-sm h-9 self-start" data-pilih-favorit>Pilih Kamar {{ $favoritBebas['nomor'] }}</button>
                        @endif
                    </div>
                @endif
                <x-staf.isian nama="permintaan" label="Catatan (opsional)" class="sm:col-span-2"><input type="text" wire:model="permintaan" maxlength="500" class="wt-input {{ $galat('permintaan') }}"></x-staf.isian>
            </div>
            <p class="mb-0 mt-3 text-xs text-ink-500">Tamu yang nomornya sudah terdaftar memakai data pelanggan yang sama, jadi riwayatnya menyatu.</p>
        </section>
    </div>

    {{-- Ringkasan --}}
    <aside class="wt-card overflow-hidden xl:sticky xl:top-20">
        <div class="border-b border-cream-300 bg-cream-100 px-5 py-3.5 font-display text-[13px] font-bold uppercase tracking-[.06em]">Ringkasan</div>
        <div class="px-5 py-4 text-[13px]">
            @if ($dipilih->isEmpty())
                <p class="m-0 text-ink-500">Belum ada kamar dipilih.</p>
            @else
                <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
                    @foreach ($dipilih as $k)
                        <li class="flex justify-between gap-3"><span>Kamar {{ $k->room_number }} · {{ $tipe[$k->room_type_id]->code }}</span><span class="tabular-nums">{{ $rp((float) $tipe[$k->room_type_id]->base_price * $malam) }}</span></li>
                    @endforeach
                </ul>
                <div class="mt-3 flex items-baseline justify-between border-t border-cream-300 pt-3">
                    <span class="font-semibold">Total {{ $malam }} malam</span>
                    <span class="font-display text-lg font-extrabold tabular-nums">{{ $rp($total) }}</span>
                </div>
            @endif
        </div>
        <div class="flex flex-col gap-3 border-t border-cream-300 px-5 py-4">
            @if ($hariIni)
                <label class="flex items-center gap-2.5 text-[13px]">
                    <input type="checkbox" wire:model.live="langsungCheckIn" class="h-4 w-4 accent-brown-800"> Langsung check-in
                </label>
                @if ($langsungCheckIn)
                    <label class="flex items-center gap-2.5 text-[13px]">
                        <input type="checkbox" wire:model.live="ktpDitahan" class="h-4 w-4 accent-brown-800"> KTP ditahan
                    </label>
                    @if ($ktpDitahan)
                        <x-staf.isian nama="nama_ktp" label="Nama sesuai KTP" bantuan="Kosongkan bila sama dengan nama pemesan.">
                            <input type="text" wire:model="namaKtp" maxlength="100" class="wt-input">
                        </x-staf.isian>
                    @endif
                @endif
            @else
                <p class="m-0 text-xs text-ink-500">Reservasi untuk tanggal lain langsung terkonfirmasi; check-in dilakukan saat tamu datang.</p>
            @endif
            <button type="submit" wire:loading.attr="disabled" wire:target="simpan"
                    class="h-11 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">
                <span wire:loading.remove wire:target="simpan">Simpan reservasi</span><span wire:loading wire:target="simpan">Menyimpan…</span>
            </button>
            <p class="m-0 text-xs text-ink-500">Pembayaran di muka atau saat check-out dicatat di menu Keuangan › Pembayaran.</p>
        </div>
    </aside>
</form>
