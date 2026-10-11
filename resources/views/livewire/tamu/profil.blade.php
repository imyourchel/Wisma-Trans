@php
    $inisial = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($k) => mb_strtoupper(mb_substr($k, 0, 1)))->implode('');
    $peran = $user->roles->where('code', '!=', 'customer')->pluck('name');
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
@endphp

<div @class(['animate-wt-masuk', 'wt-container max-w-[1040px] py-10 sm:py-14' => ! $staf])>
    @unless ($staf)
        <header>
            <div class="font-display text-[11px] font-semibold uppercase tracking-[.26em] text-clay-500">Akun saya</div>
            <h1 class="mb-0 mt-2 font-display text-[28px] font-extrabold uppercase tracking-[.04em] sm:text-[34px]">Profil</h1>
            <p class="mb-0 mt-1 text-sm text-ink-500">Perbarui data diri dan keamanan akun Anda.</p>
        </header>
    @endunless

    <div @class(['grid items-start gap-6 lg:grid-cols-[300px_minmax(0,1fr)]', 'mt-7' => ! $staf])>
        {{-- Ringkasan akun --}}
        <aside class="overflow-hidden rounded-xl border border-cream-300 bg-cream-50 shadow-wt-xs lg:sticky lg:top-24">
            <div class="relative isolate bg-brown-900 px-6 pb-14 pt-6 text-cream-50">
                <div class="pointer-events-none absolute -right-10 -top-12 -z-10 h-40 w-40 rounded-full bg-[radial-gradient(circle,rgba(228,185,143,.3),transparent_70%)]" aria-hidden="true"></div>
                <div class="font-display text-[10px] font-semibold uppercase tracking-[.26em] text-sand-300">{{ $staf ? 'Staf Wisma Trans' : 'Tamu Wisma Trans' }}</div>
            </div>
            <div class="relative -mt-10 px-6 pb-6">
                <span class="flex h-20 w-20 items-center justify-center rounded-full border-4 border-cream-50 bg-clay-500 font-display text-2xl font-extrabold tracking-[.04em] text-cream-50 shadow-wt-md">{{ $inisial }}</span>
                <div class="mt-3 font-display text-lg font-extrabold leading-tight">{{ $user->name }}</div>
                @if ($peran->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($peran as $p)
                            <span class="rounded-full bg-cream-200 px-2.5 py-0.5 text-[11px] font-semibold text-brown-800">{{ $p }}</span>
                        @endforeach
                    </div>
                @endif
                <dl class="m-0 mt-4 flex flex-col gap-2.5 border-t border-cream-300 pt-4 text-[13px]">
                    <div class="flex items-center gap-2.5 text-ink-900"><img src="{{ asset('ds/icons/mail-muted.svg') }}" alt="" width="15" height="15"><dt class="sr-only">Email</dt><dd class="m-0 min-w-0 truncate">{{ $user->email }}</dd></div>
                    @if ($user->phone)
                        <div class="flex items-center gap-2.5 text-ink-900"><img src="{{ asset('ds/icons/phone-muted.svg') }}" alt="" width="15" height="15"><dt class="sr-only">Nomor HP</dt><dd class="m-0 tabular-nums">{{ $user->phone }}</dd></div>
                    @endif
                    @if ($user->created_at)
                        <div class="flex items-center gap-2.5 text-ink-500"><img src="{{ asset('ds/icons/clock.svg') }}" alt="" width="15" height="15" class="opacity-60"><dt class="sr-only">Bergabung</dt><dd class="m-0">Bergabung {{ $user->created_at->translatedFormat('F Y') }}</dd></div>
                    @endif
                </dl>

                @if ($jumlah)
                    <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                        @foreach ([['Kamar', $jumlah['reservasi']], ['Layanan', $jumlah['layanan']], ['Aula', $jumlah['aula']]] as [$l, $n])
                            <div class="rounded-lg bg-cream-150 px-2 py-3">
                                <div class="font-display text-lg font-extrabold tabular-nums">{{ $n }}</div>
                                <div class="text-[11px] uppercase tracking-[.08em] text-ink-500">{{ $l }}</div>
                            </div>
                        @endforeach
                    </div>
                    <a href="{{ route('pesanan') }}" class="wt-btn-outline mt-3 h-10 w-full text-xs tracking-[.08em]">Lihat pesanan saya</a>
                @endif
            </div>
        </aside>

        <div class="flex min-w-0 flex-col gap-6">
            {{-- Data diri --}}
            <section class="wt-card overflow-hidden" aria-labelledby="judul-data">
                <div class="border-b border-cream-300 bg-cream-100 px-5 py-4 sm:px-6">
                    <h2 id="judul-data" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">Data diri</h2>
                    <p class="mb-0 mt-0.5 text-xs text-ink-500">{{ $staf ? 'Nama dan kontak yang tampil di panel staf.' : 'Dipakai untuk nota dan pesan WhatsApp pesanan Anda.' }}</p>
                </div>
                <form wire:submit="simpanData" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6" novalidate>
                    <label class="block sm:col-span-2">
                        <span class="wt-field-label">Nama lengkap</span>
                        <input type="text" wire:model="name" class="wt-input {{ $galat('name') }}" autocomplete="name" maxlength="100">
                        @error('name')<span class="mt-1 block text-xs text-status-dirty">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">
                        <span class="wt-field-label">Nomor WhatsApp</span>
                        <input type="tel" wire:model="phone" class="wt-input {{ $galat('phone') }}" autocomplete="tel" inputmode="tel" placeholder="0812xxxxxxxx">
                        @error('phone')<span class="mt-1 block text-xs text-status-dirty">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">
                        <span class="wt-field-label">Email</span>
                        <input type="email" wire:model="email" class="wt-input {{ $galat('email') }}" autocomplete="email">
                        @error('email')<span class="mt-1 block text-xs text-status-dirty">{{ $message }}</span>@enderror
                    </label>

                    @if ($user->customer)
                        <div class="block">
                            <span class="wt-field-label">Jenis kelamin <span class="font-normal normal-case tracking-normal text-ink-500">(opsional)</span></span>
                            <div class="grid grid-cols-3 gap-2" role="radiogroup">
                                @foreach (['' => 'Tidak diisi', 'L' => 'Laki-laki', 'P' => 'Perempuan'] as $nilai => $label)
                                    <label class="relative flex h-10 cursor-pointer items-center justify-center rounded-md border px-2 text-[13px] font-semibold transition has-[:checked]:border-clay-500 has-[:checked]:bg-[rgba(181,113,74,.08)] has-[:checked]:text-clay-600 border-cream-300 text-ink-500 hover:border-cream-400">
                                        <input type="radio" wire:model="gender" value="{{ $nilai }}" class="sr-only">{{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <label class="block">
                            <span class="wt-field-label">Tanggal lahir <span class="font-normal normal-case tracking-normal text-ink-500">(opsional)</span></span>
                            <input type="date" wire:model="date_of_birth" class="wt-input {{ $galat('date_of_birth') }}" max="{{ today()->subDay()->toDateString() }}">
                            @error('date_of_birth')<span class="mt-1 block text-xs text-status-dirty">{{ $message }}</span>@enderror
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="wt-field-label">Alamat <span class="font-normal normal-case tracking-normal text-ink-500">(opsional)</span></span>
                            <textarea wire:model="address" rows="2" class="wt-input {{ $galat('address') }}" maxlength="255" placeholder="Alamat untuk nota"></textarea>
                            @error('address')<span class="mt-1 block text-xs text-status-dirty">{{ $message }}</span>@enderror
                        </label>
                    @endif

                    <div class="flex justify-end sm:col-span-2">
                        <button type="submit" class="wt-btn-primary h-11 px-7 text-xs tracking-[.12em]">Simpan data diri</button>
                    </div>
                </form>
            </section>

            {{-- Kata sandi --}}
            <section class="wt-card overflow-hidden" aria-labelledby="judul-sandi">
                <div class="border-b border-cream-300 bg-cream-100 px-5 py-4 sm:px-6">
                    <h2 id="judul-sandi" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">{{ $adaSandi ? 'Ganti kata sandi' : 'Buat kata sandi' }}</h2>
                    <p class="mb-0 mt-0.5 text-xs text-ink-500">
                        {{ $adaSandi ? 'Minimal 8 karakter. Gunakan kata sandi yang tidak dipakai di situs lain.' : 'Akun Anda masuk lewat Google. Buat kata sandi agar bisa masuk juga dengan email atau nomor HP.' }}
                    </p>
                </div>
                <form wire:submit="simpanSandi" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6" novalidate x-data="{ lihat: false }">
                    @if ($adaSandi)
                        <label class="block sm:col-span-2 sm:max-w-[calc(50%-.5rem)]">
                            <span class="wt-field-label">Kata sandi saat ini</span>
                            <input :type="lihat ? 'text' : 'password'" type="password" wire:model="sandiLama" class="wt-input {{ $galat('sandiLama') }}" autocomplete="current-password">
                            @error('sandiLama')<span class="mt-1 block text-xs text-status-dirty">{{ $message }}</span>@enderror
                        </label>
                    @endif
                    <label class="block">
                        <span class="wt-field-label">Kata sandi baru</span>
                        <input :type="lihat ? 'text' : 'password'" type="password" wire:model="sandiBaru" class="wt-input {{ $galat('sandiBaru') }}" autocomplete="new-password" maxlength="72">
                        @error('sandiBaru')<span class="mt-1 block text-xs text-status-dirty">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">
                        <span class="wt-field-label">Ulangi kata sandi baru</span>
                        <input :type="lihat ? 'text' : 'password'" type="password" wire:model="sandiBaru_confirmation" class="wt-input" autocomplete="new-password" maxlength="72">
                    </label>
                    <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-2">
                        <label class="inline-flex cursor-pointer items-center gap-2 text-[13px] text-ink-500">
                            <input type="checkbox" x-model="lihat" class="h-4 w-4 accent-clay-500">Tampilkan kata sandi
                        </label>
                        <button type="submit" class="wt-btn-primary h-11 px-7 text-xs tracking-[.12em]">{{ $adaSandi ? 'Ganti kata sandi' : 'Buat kata sandi' }}</button>
                    </div>
                </form>
            </section>

            {{-- Akun terhubung --}}
            <section class="wt-card p-5 sm:p-6" aria-labelledby="judul-google">
                <h2 id="judul-google" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">Akun terhubung</h2>
                <div class="mt-4 flex items-center gap-4 rounded-lg border border-cream-300 bg-cream-100 px-4 py-3.5">
                    <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-cream-50 shadow-wt-xs">
                        <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2c-2 1.5-4.5 2.4-7.2 2.4-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold">Google</div>
                        <div class="truncate text-xs text-ink-500">{{ $google ? 'Terhubung · '.($google->provider_email ?? $user->email) : 'Belum terhubung. Masuk dengan Google memakai email ini untuk menghubungkan.' }}</div>
                    </div>
                    @if ($google)
                        <span class="inline-flex flex-none items-center gap-1.5 rounded-full bg-[rgba(92,158,103,.14)] px-2.5 py-1 text-xs font-semibold text-status-available"><span class="h-1.5 w-1.5 rounded-full bg-status-available"></span>Aktif</span>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
