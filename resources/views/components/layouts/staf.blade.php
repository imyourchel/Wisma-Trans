@props(['title', 'grup' => null])
@php
    $user = auth()->user()->loadMissing('roles');
    $menu = \App\Support\MenuStaf::untuk($user);
    $grup ??= \App\Support\MenuStaf::grupAktif();
    $inisial = collect(explode(' ', $user->name))->filter()->take(2)->map(fn ($k) => mb_substr($k, 0, 1))->join('');
    $namaPeran = $user->roles->whereIn('code', \App\Models\Role::STAFF)->pluck('name')->join(', ');
    // Menu akun: Admin dan Manager mendapat Lihat situs dan Keluar; staf operasional (FO, F&B, HK) hanya Keluar
    $lihatSitus = $user->roles->whereIn('code', ['admin', 'manager'])->isNotEmpty();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Panel Staf Wisma Trans</title>
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh" x-data="panelStaf" @keydown.escape.window="laci = false; akun = false">

{{-- Sidebar: 256px, dapat diciutkan menjadi 72px berisi ikon. Di layar kecil menjadi laci.
     Gaya ciut diatur CSS (.wt-sidebar.is-ciut) supaya perubahan lebar terasa halus. --}}
<div x-show="laci" x-cloak @click="laci = false" class="fixed inset-0 z-[55] bg-[rgba(62,42,28,.44)] md:hidden"></div>
<aside class="wt-sidebar fixed inset-y-0 left-0 z-[60] flex -translate-x-full flex-col overflow-hidden bg-brown-800 text-cream-150 md:translate-x-0"
       :class="ciut && !laci && 'is-ciut'" :style="laci && 'translate: none'"
       @mouseover="tunjuk($event.target.closest('[data-tip]'))" @mouseleave="sembunyi(); tutupMelayang()"
       @focusin="tunjuk($event.target.closest('[data-tip]'))" @focusout="sembunyi()">
    <div class="relative flex h-16 flex-none items-center gap-2 border-b border-[rgba(242,235,220,.12)] pl-5 pr-4">
        <a href="{{ route('staf.dashboard') }}" class="flex min-w-0 items-center hover:no-underline" aria-label="Dashboard">
            <span class="wt-label block"><img src="{{ asset('ds/logo/DC-wismatrans-landscape.png') }}" alt="Wisma Trans" class="h-9 w-auto max-w-none"></span>
            <img src="{{ asset('ds/logo/LC-wismatrans-Logo-NoBG.png') }}" alt="" class="wt-logo-kecil absolute left-5 top-1/2 h-8 w-8 -translate-y-1/2 object-contain">
        </a>
        <button type="button" @click="laci = false" class="ml-auto flex h-11 w-11 flex-none items-center justify-center rounded border-0 bg-transparent text-cream-150 hover:bg-[rgba(242,235,220,.12)] md:hidden" aria-label="Tutup menu">
            <x-staf.ikon nama="tutup" />
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4 [scrollbar-color:rgba(242,235,220,.22)_transparent] [scrollbar-width:thin]" aria-label="Menu staf" @scroll="sembunyi()">
        @foreach ($menu as $g)
            @if (! $g['anak'])
                <a href="{{ $g['url'] }}" data-tip="{{ $g['label'] }}"
                   @class(['mb-1 flex h-10 pointer-coarse:h-11 items-center gap-3 rounded px-[15px] text-sm hover:no-underline',
                       'wt-grup-aktif bg-[rgba(242,235,220,.16)] font-semibold text-cream-50' => $g['aktif'],
                       'text-[rgba(242,235,220,.78)] hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50' => ! $g['aktif']])>
                    <x-staf.ikon :nama="$g['ikon']" />
                    <span class="wt-label">{{ $g['label'] }}</span>
                </a>
            @else
                <div x-data="{ buka: @js($g['aktif']) }" class="mb-1">
                    {{-- Saat sidebar diciutkan, grup membuka panel melayang (lihat di bawah) --}}
                    <button type="button" data-tip="{{ $g['label'] }}" data-grup="g{{ $loop->index }}" :aria-expanded="modeCiut() ? melayang === 'g{{ $loop->index }}' : buka"
                            @click="modeCiut() ? alihMelayang($el) : buka = !buka"
                            :class="melayang === 'g{{ $loop->index }}' && 'bg-[rgba(242,235,220,.10)] text-cream-50'"
                            @class(['flex h-10 pointer-coarse:h-11 w-full items-center gap-3 rounded border-0 bg-transparent pl-[15px] pr-3 text-left text-sm hover:bg-[rgba(242,235,220,.10)]',
                                'wt-grup-aktif text-cream-50' => $g['aktif'], 'text-[rgba(242,235,220,.78)] hover:text-cream-50' => ! $g['aktif']])>
                        <x-staf.ikon :nama="$g['ikon']" />
                        <span class="wt-label flex-1 @if ($g['aktif']) font-semibold @endif">{{ $g['label'] }}</span>
                        <span class="wt-label flex flex-none"><x-staf.ikon nama="panah" ukuran="14" class="opacity-60 transition-transform duration-300" ::class="buka && 'rotate-180'" /></span>
                    </button>
                    {{-- Submenu membuka dan menutup dengan animasi tinggi, juga saat sidebar diciutkan --}}
                    <div x-show="buka && (!ciut || laci)" x-collapse.duration.300ms x-cloak>
                        {{-- Garis cabang tepat di bawah tengah ikon grup (12px nav + 15px + 9px) --}}
                        <x-staf.submenu :anak="$g['anak']" class="wt-label ml-6 pb-1 pt-0.5" />
                    </div>
                </div>
            @endif
        @endforeach
    </nav>

    {{-- Bawah sidebar: ke situs tamu (Admin dan Manager) dan keluar (ciut/lebar hanya lewat tombol ☰ di kepala) --}}
    <div class="flex-none border-t border-[rgba(242,235,220,.12)] px-3 py-3">
        @if ($lihatSitus)
            <a href="{{ route('home') }}" target="_blank" rel="noopener" data-tip="Lihat situs tamu" data-sidebar-situs
               class="mb-1 flex h-10 pointer-coarse:h-11 items-center gap-3 rounded px-[15px] text-sm text-[rgba(242,235,220,.78)] hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50 hover:no-underline">
                <x-staf.ikon nama="tautan-keluar" />
                <span class="wt-label">Lihat situs tamu</span>
            </a>
        @endif
        <form method="POST" action="{{ route('logout') }}" data-sidebar-keluar
              @submit.prevent="$store.konfirmasi.tanya({ judul: 'Keluar dari panel staf?', pesan: 'Anda perlu masuk lagi untuk membuka panel staf.', label: 'Keluar', aksi: () => $el.submit() })">
            @csrf
            <button type="submit" data-tip="Keluar"
                    class="flex h-10 pointer-coarse:h-11 w-full items-center gap-3 rounded border-0 bg-transparent px-[15px] text-left text-sm font-medium text-[#F0918A] hover:bg-[rgba(212,81,74,.22)] hover:text-[#FFC2BC]">
                <x-staf.ikon nama="keluar" />
                <span class="wt-label">Keluar</span>
            </button>
        </form>
    </div>
</aside>

{{-- Panel submenu melayang saat sidebar diciutkan --}}
@foreach ($menu as $g)
    @if ($g['anak'])
        <div x-show="melayang === 'g{{ $loop->index }}'" x-cloak id="melayang-g{{ $loop->index }}" :style="{ left: posMelayang.x + 'px', top: posMelayang.y + 'px' }"
             @mouseenter="tahanMelayang()" @mouseleave="tutupMelayang()" @keydown.escape.window="melayang = null"
             x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="opacity-0 -translate-x-1" x-transition:enter-end="opacity-100"
             x-transition:leave="transition duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed z-[70] hidden w-[236px] rounded-lg border border-[rgba(242,235,220,.12)] bg-brown-800 p-2 shadow-wt-lg before:absolute before:inset-y-0 before:-left-[12px] before:w-[12px] md:block">
            <div class="flex h-9 items-center px-3 font-display text-[11px] font-bold uppercase tracking-[.12em] text-sand-300">{{ $g['label'] }}</div>
            <x-staf.submenu :anak="$g['anak']" class="ml-[21px] pb-1" />
        </div>
    @endif
@endforeach

{{-- Label menu saat sidebar diciutkan: melayang di kanan ikon (di luar sidebar supaya tidak terpotong) --}}
<div x-show="tip.tampil" x-cloak role="tooltip" :style="{ left: tip.x + 'px', top: tip.y + 'px' }"
     x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="opacity-0 -translate-x-1" x-transition:enter-end="opacity-100"
     x-transition:leave="transition duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="pointer-events-none fixed z-[70] hidden -translate-y-1/2 whitespace-nowrap rounded-md bg-brown-800 px-3 py-1.5 text-[13px] font-semibold text-cream-50 shadow-wt-lg md:block"
     x-text="tip.teks"></div>

<div class="wt-konten flex min-h-dvh min-w-0 flex-col" :class="ciut && 'is-ciut'">
    {{-- Kepala: tombol menu, jejak halaman (Panel › Grup › Halaman), tanggal, dan akun --}}
    <header class="sticky top-0 z-40 flex min-h-16 items-center gap-3 border-b border-cream-300 bg-cream-50 px-4 py-2.5 shadow-[0_2px_10px_-4px_rgba(62,42,28,.12)] sm:gap-4 sm:px-6">
        {{-- Layar lebar: ciutkan/lebarkan sidebar; layar kecil: buka laci menu --}}
        <button type="button" @click="window.innerWidth >= 768 ? ubahCiut() : (laci = !laci)"
                :aria-label="window.innerWidth >= 768 ? (ciut ? 'Lebarkan sidebar' : 'Ciutkan sidebar') : (laci ? 'Tutup menu' : 'Buka menu')" :aria-expanded="window.innerWidth >= 768 ? !ciut : laci"
                class="flex h-10 w-10 flex-none items-center justify-center rounded-lg border border-cream-300 bg-cream-50 text-brown-800 shadow-wt-xs transition hover:bg-cream-200" data-tombol-menu>
            <x-staf.ikon nama="menu" ukuran="18" />
        </button>

        <nav aria-label="Jejak halaman" class="min-w-0 flex-1">
            <ol class="m-0 flex min-w-0 list-none items-center gap-1.5 p-0 text-[14px]">
                <li class="hidden flex-none sm:block">
                    <a href="{{ route('staf.dashboard') }}" class="inline-flex items-center gap-1.5 font-semibold text-clay-600 hover:text-clay-500 hover:no-underline">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/></svg>
                        Panel
                    </a>
                </li>
                @if ($grup)
                    <li class="hidden flex-none items-center gap-1.5 text-ink-500 md:flex">
                        <span class="text-cream-400" aria-hidden="true">›</span>{{ $grup }}
                    </li>
                @endif
                <li class="flex min-w-0 items-center gap-1.5">
                    <span class="hidden text-cream-400 sm:inline" aria-hidden="true">›</span>
                    <h1 class="m-0 truncate font-sans text-[14px] font-semibold normal-case leading-tight tracking-normal text-ink-900 sm:font-medium" aria-current="page" title="{{ $title }}">{{ $title }}</h1>
                </li>
            </ol>
        </nav>

        {{-- Tanggal dan jam berjalan (WITA, mengikuti jam server) --}}
        <div class="hidden flex-none items-center gap-4 lg:flex">
            <div class="text-[13px] tabular-nums text-ink-500" x-data="jamStaf(@js(['ms' => now()->getTimestampMs(), 'zona' => config('app.timezone')]))">
                <span x-text="tanggal">{{ now()->translatedFormat('j M Y') }}</span>
                <span class="mx-1 text-cream-400">·</span>
                <span x-text="jam">{{ now()->format('H.i') }}</span> WITA
            </div>
            <span class="h-6 w-px bg-cream-300" aria-hidden="true"></span>
        </div>

        <div class="relative" @click.outside="akun = false">
            <button type="button" @click="akun = !akun" :aria-expanded="akun" aria-label="Akun {{ $user->name }}" class="flex items-center gap-2.5 rounded-full border border-cream-300 bg-cream-50 p-1 shadow-wt-xs transition hover:bg-cream-200 sm:pr-4">
                <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-clay-500 font-display text-[13px] font-bold text-cream-50">{{ $inisial }}</span>
                <span class="hidden min-w-0 text-left sm:block">
                    <span class="block max-w-[180px] truncate text-[13px] font-semibold uppercase leading-tight tracking-[.02em] text-ink-900">{{ $user->name }}</span>
                    <span class="block max-w-[180px] truncate text-[11px] leading-tight text-ink-500">{{ $namaPeran }}</span>
                </span>
            </button>
            <div x-show="akun" x-cloak x-transition.opacity.duration.150ms
                 class="absolute right-0 top-[calc(100%+8px)] z-50 w-56 overflow-hidden rounded-md border border-cream-300 bg-cream-50 py-1 shadow-wt-lg">
                <div class="border-b border-cream-300 px-4 py-2.5 sm:hidden">
                    <div class="truncate text-sm font-semibold">{{ $user->name }}</div>
                    <div class="truncate text-xs text-ink-500">{{ $namaPeran }}</div>
                </div>
                @if ($lihatSitus)
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-ink-900 hover:bg-row-hover hover:text-ink-900 hover:no-underline" data-menu-situs><x-staf.ikon nama="situs" ukuran="16" class="text-ink-500" />Lihat situs</a>
                @endif
                <form method="POST" action="{{ route('logout') }}" @class(['border-t border-cream-300' => $lihatSitus])>
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-2.5 border-0 bg-transparent px-4 py-2.5 text-left text-sm text-status-dirty hover:bg-row-hover"><x-staf.ikon nama="keluar" ukuran="16" />Keluar</button>
                </form>
            </div>
        </div>
    </header>

    <main class="min-w-0 flex-1 p-4 pb-[max(16px,env(safe-area-inset-bottom))] sm:p-6">
        {{ $slot }}
    </main>
</div>

{{-- Konfirmasi sebelum tindakan seperti menonaktifkan data --}}
<form id="form-aksi" method="POST" class="hidden">
    @csrf
    <input type="hidden" name="_method" value="PATCH">
</form>
<div x-data x-show="$store.konfirmasi.buka" x-cloak @keydown.escape.window="$store.konfirmasi.buka = false"
     class="fixed inset-0 z-[380] flex items-center justify-center p-4">
    <div @click="$store.konfirmasi.buka = false" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
    <div role="alertdialog" aria-modal="true" aria-labelledby="konfirmasi-judul"
         class="relative w-[420px] max-w-full animate-wt-fade rounded-md border border-cream-300 bg-cream-50 p-6 shadow-wt-lg">
        <div id="konfirmasi-judul" class="font-display text-base font-bold uppercase tracking-[.04em]" x-text="$store.konfirmasi.judul"></div>
        <p class="mb-0 mt-2 text-sm text-ink-500" x-text="$store.konfirmasi.pesan"></p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" @click="$store.konfirmasi.buka = false" class="h-10 rounded border border-cream-300 bg-transparent px-4 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
            <button type="button" x-ref="ya" @click="$store.konfirmasi.lanjut()" x-effect="$store.konfirmasi.buka && setTimeout(() => $refs.ya.focus(), 60)"
                    class="h-10 rounded border-0 bg-status-dirty px-4 font-display text-[13px] font-semibold text-cream-50 hover:opacity-90" x-text="$store.konfirmasi.label"></button>
        </div>
    </div>
</div>

@include('partials.toasts')
@include('partials.staf-skrip')
@stack('scripts')
@livewireScripts
</body>
</html>
