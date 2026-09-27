@props(['title', 'grup' => null])
@php
    $user = auth()->user()->loadMissing('roles');
    $menu = \App\Support\MenuStaf::untuk($user);
    $grup ??= \App\Support\MenuStaf::grupAktif();
    $inisial = collect(explode(' ', $user->name))->filter()->take(2)->map(fn ($k) => mb_substr($k, 0, 1))->join('');
    $namaPeran = $user->roles->whereIn('code', \App\Models\Role::STAFF)->pluck('name')->join(', ');
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
<body class="min-h-screen" x-data="panelStaf" @keydown.escape.window="laci = false; akun = false">

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
        <button type="button" @click="laci = false" class="ml-auto flex h-9 w-9 flex-none items-center justify-center rounded border-0 bg-transparent text-cream-150 hover:bg-[rgba(242,235,220,.12)] md:hidden" aria-label="Tutup menu">
            <x-staf.ikon nama="tutup" />
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4 [scrollbar-color:rgba(242,235,220,.22)_transparent] [scrollbar-width:thin]" aria-label="Menu staf" @scroll="sembunyi()">
        @foreach ($menu as $g)
            @if (! $g['anak'])
                <a href="{{ $g['url'] }}" data-tip="{{ $g['label'] }}"
                   @class(['mb-1 flex h-10 items-center gap-3 rounded px-[15px] text-sm hover:no-underline',
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
                            @class(['flex h-10 w-full items-center gap-3 rounded border-0 bg-transparent pl-[15px] pr-3 text-left text-sm hover:bg-[rgba(242,235,220,.10)]',
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

    <button type="button" @click="ubahCiut()" :data-tip="ciut ? 'Lebarkan sidebar' : 'Ciutkan sidebar'" :aria-label="ciut ? 'Lebarkan sidebar' : 'Ciutkan sidebar'" :aria-expanded="!ciut"
            class="hidden h-12 flex-none items-center gap-3 border-0 border-t border-[rgba(242,235,220,.12)] bg-transparent px-[27px] text-[13px] text-[rgba(242,235,220,.66)] hover:text-cream-50 md:flex">
        <x-staf.ikon nama="ciut" class="wt-ciut-ikon" />
        <span class="wt-label">Ciutkan</span>
    </button>
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

<div class="wt-konten flex min-h-screen min-w-0 flex-col" :class="ciut && 'is-ciut'">
    <header class="sticky top-0 z-40 flex min-h-16 items-center gap-3 border-b border-cream-300 bg-cream-50 px-4 py-2 sm:px-6">
        <button type="button" @click="laci = true" class="flex h-9 w-9 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 md:hidden" aria-label="Buka menu">
            <x-staf.ikon nama="menu" />
        </button>
        <div class="min-w-0 flex-1">
            <h1 class="m-0 truncate font-display text-lg font-bold uppercase leading-tight tracking-[.04em]">{{ $title }}</h1>
            <div class="truncate text-xs text-ink-500">{{ $grup ? $grup.' · '.$title : 'Panel staf' }}</div>
        </div>
        {{-- Tanggal dan jam berjalan (WITA, mengikuti jam server) --}}
        <div class="hidden text-[13px] tabular-nums text-ink-500 lg:block" x-data="jamStaf(@js(['ms' => now()->getTimestampMs(), 'zona' => config('app.timezone')]))">
            <span x-text="tanggal">{{ now()->translatedFormat('l, j F Y') }}</span>
            <span class="mx-1.5 text-cream-400">|</span>
            <span class="font-semibold text-ink-900" x-text="jam">{{ now()->format('H.i') }}</span> WITA
        </div>
        <div class="relative" @click.outside="akun = false">
            <button type="button" @click="akun = !akun" :aria-expanded="akun" class="flex items-center gap-2.5 rounded-full border-0 bg-transparent p-0.5 pr-1 hover:bg-cream-200 sm:pr-3">
                <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-cream-200 font-display text-[13px] font-bold text-brown-800">{{ $inisial }}</span>
                <span class="hidden min-w-0 text-left sm:block">
                    <span class="block max-w-[180px] truncate text-[13px] font-semibold leading-tight">{{ $user->name }}</span>
                    <span class="block max-w-[180px] truncate text-[11px] leading-tight text-ink-500">{{ $namaPeran }}</span>
                </span>
            </button>
            <div x-show="akun" x-cloak x-transition.opacity.duration.150ms
                 class="absolute right-0 top-[calc(100%+8px)] z-50 w-56 overflow-hidden rounded-md border border-cream-300 bg-cream-50 py-1 shadow-wt-lg">
                <div class="border-b border-cream-300 px-4 py-2.5 sm:hidden">
                    <div class="truncate text-sm font-semibold">{{ $user->name }}</div>
                    <div class="truncate text-xs text-ink-500">{{ $namaPeran }}</div>
                </div>
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-ink-900 hover:bg-row-hover hover:text-ink-900 hover:no-underline"><x-staf.ikon nama="situs" ukuran="16" class="text-ink-500" />Lihat situs</a>
                <a href="{{ route('profil') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-ink-900 hover:bg-row-hover hover:text-ink-900 hover:no-underline"><x-staf.ikon nama="pelanggan" ukuran="16" class="text-ink-500" />Profil</a>
                <form method="POST" action="{{ route('logout') }}" class="border-t border-cream-300">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-2.5 border-0 bg-transparent px-4 py-2.5 text-left text-sm text-status-dirty hover:bg-row-hover"><x-staf.ikon nama="keluar" ukuran="16" />Keluar</button>
                </form>
            </div>
        </div>
    </header>

    <div class="border-b border-[rgba(232,172,69,.4)] bg-[rgba(232,172,69,.14)] px-4 py-2 text-[13px] text-ink-900 md:hidden">
        Paling nyaman dibuka di laptop.
    </div>

    <main class="min-w-0 flex-1 p-4 sm:p-6">
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
