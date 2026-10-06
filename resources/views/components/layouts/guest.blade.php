@props(['title' => null])
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · Wisma Trans' : 'Wisma Trans · Penginapan di Malili' }}</title>
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
@php
    $nav = [
        ['label' => 'Kamar', 'route' => 'kamar', 'aktif' => ['kamar', 'kamar.tipe']],
        ['label' => 'Resto', 'route' => 'menu', 'aktif' => ['menu']],
        ['label' => 'Laundry', 'route' => 'laundry', 'aktif' => ['laundry']],
        ['label' => 'Aula', 'route' => 'aula', 'aktif' => ['aula']],
        ['label' => 'Kontak', 'route' => 'kontak', 'aktif' => ['kontak']],
    ];
@endphp

<header x-data="{ laci: false, akun: false }" @keydown.escape.window="laci = false; akun = false"
        class="sticky top-0 z-40 bg-brown-800">
    <div class="flex min-h-[72px] items-center gap-4 px-4 py-2 sm:px-6">
        <a href="{{ route('home') }}" class="flex-none">
            <img src="{{ asset('ds/logo/DC-wismatrans-landscape.png') }}" alt="Wisma Trans" class="h-10 w-auto sm:h-12">
        </a>

        <nav class="mx-auto hidden flex-wrap justify-center gap-1 lg:flex" aria-label="Menu utama">
            @foreach ($nav as $item)
                @php $aktif = request()->routeIs(...$item['aktif']); @endphp
                {{-- Menu aktif: kotak dengan garis bawah yang menyatu di tepi bawahnya --}}
                <a href="{{ route($item['route']) }}"
                   @class([
                       'relative whitespace-nowrap rounded-t px-2.5 py-2 font-display text-xs font-semibold uppercase leading-none tracking-[.08em] hover:bg-[rgba(242,235,220,.12)] hover:no-underline',
                       'bg-[rgba(242,235,220,.14)] text-cream-50 hover:text-cream-50 after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:bg-sand-300' => $aktif,
                       'text-cream-150 hover:text-cream-150' => ! $aktif,
                   ])
                   @if ($aktif) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="ml-auto flex flex-none items-center gap-3 lg:ml-0">
            {{-- Cek Pesanan hanya untuk tamu tanpa akun; yang sudah masuk memakai Pesanan saya --}}
            @guest
                <a href="{{ route('cek') }}" class="hidden whitespace-nowrap p-2 text-sm text-[rgba(242,235,220,.78)] hover:text-cream-150 hover:no-underline sm:inline-block">Cek Pesanan</a>
            @endguest

            @auth
                <div class="relative hidden lg:block" @click.outside="akun = false">
                    <button type="button" @click="akun = !akun" :aria-expanded="akun"
                            class="flex h-10 max-w-[200px] items-center gap-2 rounded border border-[rgba(242,235,220,.22)] bg-[rgba(242,235,220,.12)] px-4 font-display text-[13px] font-semibold text-cream-150">
                        <span class="truncate">{{ auth()->user()->name }}</span>
                        <img src="{{ asset('ds/icons/chevron-up.svg') }}" alt="" width="12" height="12" class="flex-none brightness-0 invert transition-transform" :class="akun ? '' : 'rotate-180'">
                    </button>
                    <div x-cloak x-show="akun" x-transition.opacity.duration.150ms
                         class="absolute right-0 top-12 z-50 w-52 overflow-hidden rounded-md border border-cream-300 bg-cream-50 py-1 shadow-wt-lg">
                        @if (auth()->user()->isStaff())
                            <a href="{{ route('staf.dashboard') }}" class="block px-4 py-2.5 text-sm text-ink-900 hover:bg-row-hover hover:text-ink-900 hover:no-underline">Panel staf</a>
                        @endif
                        <a href="{{ route('pesanan') }}" class="block px-4 py-2.5 text-sm text-ink-900 hover:bg-row-hover hover:text-ink-900 hover:no-underline">Pesanan saya</a>
                        <a href="{{ route('profil') }}" class="block px-4 py-2.5 text-sm text-ink-900 hover:bg-row-hover hover:text-ink-900 hover:no-underline">Profil</a>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-cream-300">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2.5 text-left text-sm text-status-dirty hover:bg-row-hover">Keluar</button>
                        </form>
                    </div>
                </div>
            @else
                <a href="{{ route('login') }}" @click.prevent="$dispatch('buka-akun', { mode: 'masuk' })"
                   class="wt-btn-primary h-10 px-5 text-[13px] !font-semibold !normal-case">Masuk</a>
            @endauth

            <button type="button" @click="laci = true" class="flex h-10 w-10 flex-col items-center justify-center gap-[5px] rounded border border-[rgba(242,235,220,.22)] lg:hidden" aria-label="Buka menu">
                <span class="block h-0.5 w-[18px] rounded bg-cream-150"></span>
                <span class="block h-0.5 w-[18px] rounded bg-cream-150"></span>
                <span class="block h-0.5 w-[18px] rounded bg-cream-150"></span>
            </button>
        </div>
    </div>

    {{-- Laci menu untuk layar kecil --}}
    <div x-cloak x-show="laci" class="fixed inset-0 z-50 lg:hidden">
        <div x-show="laci" x-transition.opacity @click="laci = false" class="absolute inset-0 bg-[rgba(62,42,28,.44)] backdrop-blur-[2px]"></div>
        <div x-show="laci" x-transition:enter="transition duration-200 ease-wt" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition duration-150 ease-wt" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="absolute inset-y-0 right-0 flex w-72 max-w-[85vw] flex-col overflow-y-auto bg-brown-800 p-5">
            <div class="mb-6 flex items-center justify-between">
                <img src="{{ asset('ds/logo/DC-wismatrans-landscape.png') }}" alt="Wisma Trans" class="h-9 w-auto">
                <button type="button" @click="laci = false" class="flex h-9 w-9 items-center justify-center rounded hover:bg-[rgba(242,235,220,.12)]" aria-label="Tutup menu">
                    <img src="{{ asset('ds/icons/x-cream.svg') }}" alt="" width="18" height="18">
                </button>
            </div>
            @auth
                @php
                    $pengguna = auth()->user();
                    $inisial = collect(explode(' ', trim($pengguna->name)))->filter()->take(2)->map(fn ($k) => mb_strtoupper(mb_substr($k, 0, 1)))->join('');
                @endphp
                {{-- Akun (di layar lebar ada di tombol profil header) --}}
                <div class="mb-5 rounded-md border border-[rgba(242,235,220,.16)] bg-[rgba(242,235,220,.06)] p-3">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-sand-300 font-display text-sm font-bold text-brown-800">{{ $inisial }}</span>
                        <div class="min-w-0">
                            <div class="truncate font-display text-sm font-semibold text-cream-50">{{ $pengguna->name }}</div>
                            <div class="truncate text-xs text-[rgba(242,235,220,.66)]">{{ $pengguna->email }}</div>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-col gap-0.5 border-t border-[rgba(242,235,220,.14)] pt-2">
                        @if ($pengguna->isStaff())
                            <a href="{{ route('staf.dashboard') }}" class="rounded px-2 py-2 text-sm text-cream-150 hover:bg-[rgba(242,235,220,.12)] hover:text-cream-50 hover:no-underline">Panel staf</a>
                        @endif
                        <a href="{{ route('pesanan') }}" @class(['rounded px-2 py-2 text-sm hover:bg-[rgba(242,235,220,.12)] hover:text-cream-50 hover:no-underline', 'bg-[rgba(242,235,220,.14)] text-cream-50' => request()->routeIs('pesanan'), 'text-cream-150' => ! request()->routeIs('pesanan')])>Pesanan saya</a>
                        <a href="{{ route('profil') }}" @class(['rounded px-2 py-2 text-sm hover:bg-[rgba(242,235,220,.12)] hover:text-cream-50 hover:no-underline', 'bg-[rgba(242,235,220,.14)] text-cream-50' => request()->routeIs('profil'), 'text-cream-150' => ! request()->routeIs('profil')])>Profil</a>
                    </div>
                </div>
            @endauth
            <nav class="flex flex-col gap-1" aria-label="Menu utama">
                @foreach ($nav as $item)
                    @php $aktif = request()->routeIs(...$item['aktif']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'rounded px-3 py-3 font-display text-[13px] font-semibold uppercase tracking-[.08em] hover:bg-[rgba(242,235,220,.12)] hover:no-underline',
                           'bg-[rgba(242,235,220,.14)] text-cream-50 hover:text-cream-50' => $aktif,
                           'text-cream-150 hover:text-cream-150' => ! $aktif,
                       ])>{{ $item['label'] }}</a>
                @endforeach
                @guest<a href="{{ route('cek') }}" class="mt-2 rounded border-t border-[rgba(242,235,220,.16)] px-3 py-3 text-sm text-[rgba(242,235,220,.78)] hover:text-cream-150 hover:no-underline">Cek Pesanan</a>@endguest
            </nav>
            @auth
                <form method="POST" action="{{ route('logout') }}" class="mt-auto border-t border-[rgba(242,235,220,.16)] pt-3">
                    @csrf
                    <button type="submit" class="w-full rounded border-0 bg-transparent px-3 py-3 text-left text-sm font-semibold text-[#F0A29C] hover:bg-[rgba(242,235,220,.08)]">Keluar</button>
                </form>
            @endauth
        </div>
    </div>
</header>

<main>
    {{ $slot }}
</main>

<footer class="bg-brown-800 text-[rgba(242,235,220,.72)]">
    <div class="wt-container grid grid-cols-1 items-start gap-x-8 gap-y-10 py-14 sm:grid-cols-2 lg:grid-cols-[1.3fr_1fr_1fr_1fr]">
        <div class="flex min-w-0 flex-col gap-5">
            <img src="{{ asset('ds/logo/wismatrans-landscape-dark.png') }}" alt="Wisma Trans" class="-mb-3 h-[52px] w-auto self-start">
            <p class="m-0 max-w-[280px] text-sm leading-[1.7]">Menginap nyaman, bersantap hangat, dan ruang pertemuan untuk setiap acara di pusat kota Malili.</p>
            <div class="flex items-center gap-4">
                <a href="https://wa.me/{{ $wisma['whatsapp'] }}" target="_blank" rel="noopener" title="WhatsApp" class="flex opacity-80 hover:opacity-100"><img src="{{ asset('ds/icons/whatsapp.svg') }}" alt="WhatsApp" width="20" height="20"></a>
                <a href="{{ $wisma['instagram'] }}" target="_blank" rel="noopener" title="Instagram" class="flex opacity-80 hover:opacity-100"><img src="{{ asset('ds/icons/instagram.svg') }}" alt="Instagram" width="20" height="20"></a>
                <a href="{{ $wisma['facebook'] }}" target="_blank" rel="noopener" title="Facebook" class="flex opacity-80 hover:opacity-100"><img src="{{ asset('ds/icons/facebook.svg') }}" alt="Facebook" width="20" height="20"></a>
            </div>
            <div class="font-display text-[11px] font-bold uppercase tracking-[.2em] text-sand-300">Simple · Clean · Favour</div>
        </div>
        <div class="flex min-w-0 flex-col gap-3">
            <div class="font-display text-[11px] font-bold uppercase tracking-[.16em] text-[rgba(242,235,220,.45)]">Alamat</div>
            <div class="text-sm leading-[1.7] text-[rgba(242,235,220,.82)]">{!! implode('<br>', array_map('e', $wisma['alamat'])) !!}</div>
            <a href="{{ $wisma['maps'] }}" target="_blank" rel="noopener" class="self-start text-sm text-sand-300 underline underline-offset-4 hover:text-cream-150">Lihat di Google Maps</a>
        </div>
        <div class="flex min-w-0 flex-col gap-3">
            <div class="font-display text-[11px] font-bold uppercase tracking-[.16em] text-[rgba(242,235,220,.45)]">Jam layanan</div>
            @foreach ([['Check-in', $wisma['checkIn'].' WITA'], ['Check-out', $wisma['checkOut'].' WITA'], ['Resepsionis', '24 jam']] as [$label, $nilai])
                <div class="flex flex-col gap-0.5">
                    <span class="text-[13px] text-[rgba(242,235,220,.55)]">{{ $label }}</span>
                    <span class="text-[15px] tabular-nums text-[rgba(242,235,220,.82)]">{{ $nilai }}</span>
                </div>
            @endforeach
        </div>
        <div class="flex min-w-0 flex-col gap-3">
            <div class="font-display text-[11px] font-bold uppercase tracking-[.16em] text-[rgba(242,235,220,.45)]">Reservasi</div>
            <div class="whitespace-nowrap font-display text-[19px] font-bold tabular-nums tracking-[.02em] text-cream-150">{{ $wisma['telepon'] }}</div>
            <div class="text-sm text-[rgba(242,235,220,.62)]">Telepon atau WhatsApp</div>
            <a href="{{ route('instansi') }}" class="self-start text-sm text-sand-300 underline underline-offset-4 hover:text-cream-150">Pengajuan untuk instansi</a>
        </div>
    </div>
    <div class="border-t border-[rgba(242,235,220,.16)]">
        <div class="wt-container flex flex-wrap items-center justify-between gap-x-6 gap-y-1 py-6 text-[15px] text-[rgba(242,235,220,.62)]">
            <div>© {{ now()->year }} Wisma Trans. Semua hak dilindungi.</div>
            <div class="italic">{{ implode(', ', array_slice($wisma['alamat'], 1)) }}</div>
        </div>
    </div>
</footer>

@guest
    @include('partials.auth-modal')
@endguest

@include('partials.toasts')
@stack('scripts')
@livewireScripts
</body>
</html>
