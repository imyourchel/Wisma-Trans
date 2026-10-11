@php
    $user = auth()->user();
    $jam = (int) now()->format('G');
    $salam = match (true) { $jam < 11 => 'Selamat pagi', $jam < 15 => 'Selamat siang', $jam < 19 => 'Selamat sore', default => 'Selamat malam' };
@endphp

<x-layouts.staf title="Dashboard">
<div class="flex flex-col gap-6">
    <div>
        <div class="font-display text-xl font-bold tracking-[.02em]">{{ $salam }}, {{ \Illuminate\Support\Str::before($user->name, ' ') }}.</div>
        <div class="text-sm text-ink-500">{{ now()->translatedFormat('l, j F Y') }}</div>
    </div>

    @if ($operasional)
        <livewire:staf.dashboard-operasional />
    @endif

    @foreach (array_filter(['Layanan F&B' => $fnb ?? null, 'Housekeeping' => $housekeeping ?? null, 'Tugas Housekeeping' => $tugasSaya ?? null]) as $judulHk => $kartuHk)
        <section aria-label="{{ $judulHk }}">
            <h2 class="mb-2.5 mt-0 font-display text-sm font-bold uppercase tracking-[.08em]">{{ $judulHk }}</h2>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                @foreach ($kartuHk as $k)
                    <a href="{{ $k['url'] }}" class="wt-card block p-5 text-ink-900 hover:border-cream-400 hover:bg-row-hover hover:text-ink-900 hover:no-underline">
                        <div class="text-xs font-semibold uppercase tracking-[.08em] text-ink-500">{{ $k['label'] }}</div>
                        <div @class(['mt-1 font-display text-[28px] font-extrabold tabular-nums', 'text-status-dirty' => $k['awas']])>{{ $k['nilai'] }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($maintenance)
        <section aria-labelledby="judul-mnt">
            <h2 id="judul-mnt" class="mb-2.5 mt-0 font-display text-sm font-bold uppercase tracking-[.08em]">Maintenance</h2>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                @foreach ($maintenance as $k)
                    <a href="{{ $k['url'] }}" class="wt-card block p-5 text-ink-900 hover:border-cream-400 hover:bg-row-hover hover:text-ink-900 hover:no-underline">
                        <div class="text-xs font-semibold uppercase tracking-[.08em] text-ink-500">{{ $k['label'] }}</div>
                        <div @class(['mt-1 font-display text-[28px] font-extrabold tabular-nums', 'text-status-dirty' => $k['awas']])>{{ $k['nilai'] }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($admin)
        <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            @foreach ($admin['kartu'] as $k)
                <a href="{{ $k['url'] }}" class="wt-card block p-5 text-ink-900 hover:border-cream-400 hover:bg-row-hover hover:text-ink-900 hover:no-underline">
                    <div class="text-xs font-semibold uppercase tracking-[.08em] text-ink-500">{{ $k['label'] }}</div>
                    <div class="mt-1 font-display text-[28px] font-extrabold tabular-nums">{{ $k['nilai'] }}</div>
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
            <section class="wt-card p-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="m-0 font-display text-sm font-bold uppercase tracking-[.08em]">Perlu dilengkapi</h2>
                </div>
                @forelse ($admin['lengkapi'] as $b)
                    <a href="{{ $b['url'] }}" class="flex items-center gap-3 border-t border-cream-300 py-3 text-sm text-ink-900 first-of-type:border-t-0 hover:text-clay-600 hover:no-underline">
                        <span class="flex h-7 min-w-7 items-center justify-center rounded-full bg-[rgba(232,172,69,.18)] px-2 text-xs font-bold tabular-nums text-[#9A6B12]">{{ $b['jumlah'] }}</span>
                        <span class="flex-1">{{ $b['teks'] }}</span>
                        <x-staf.ikon nama="panah" ukuran="14" class="-rotate-90 text-ink-500" />
                    </a>
                @empty
                    <p class="m-0 text-sm text-ink-500">Semua data master sudah lengkap.</p>
                @endforelse
                <p class="mb-0 mt-3 text-xs text-ink-500">Foto dan data ini tampil di halaman tamu.</p>
            </section>

            <section class="wt-card p-5">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="m-0 font-display text-sm font-bold uppercase tracking-[.08em]">Staf aktif per peran</h2>
                    <a href="{{ route('staf.pengguna.index') }}" class="text-[13px] text-olive-600">Kelola pengguna</a>
                </div>
                @foreach ($admin['perPeran'] as $r)
                    <div class="flex items-center justify-between border-t border-cream-300 py-2.5 text-sm first-of-type:border-t-0">
                        <span>{{ $r->name }}</span>
                        <span class="font-semibold tabular-nums">{{ $r->users_count }} orang</span>
                    </div>
                @endforeach
                @if ($admin['pengaturan'])
                    <p class="mb-0 mt-3 text-xs text-ink-500">
                        Pengaturan terakhir diubah {{ $admin['pengaturan']['waktu']->translatedFormat('j F Y, H.i') }}{{ $admin['pengaturan']['oleh'] ? ' oleh '.$admin['pengaturan']['oleh'] : '' }}.
                        <a href="{{ route('staf.pengaturan') }}" class="text-olive-600">Lihat pengaturan</a>
                    </p>
                @endif
            </section>
        </div>
    @endif

    @if ($pintasan->isNotEmpty())
        <section>
            <h2 class="mb-3 mt-0 font-display text-sm font-bold uppercase tracking-[.08em]">Menu Anda</h2>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(240px,1fr))] gap-4">
                @foreach ($pintasan as $g)
                    <div class="wt-card p-5">
                        <div class="mb-2 flex items-center gap-2.5 text-clay-500">
                            <x-staf.ikon :nama="$g['ikon']" />
                            <span class="font-display text-[13px] font-bold uppercase tracking-[.08em] text-ink-900">{{ $g['label'] }}</span>
                        </div>
                        @foreach ($g['anak'] as $item)
                            <a href="{{ $item['url'] }}" class="block py-1 text-sm text-olive-600 hover:text-brown-800">{{ $item['label'] }}</a>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <p class="mb-0 mt-3 text-xs text-ink-500">Halaman operasional ini dibangun pada tahap berikutnya.</p>
        </section>
    @endif
</div>
</x-layouts.staf>
