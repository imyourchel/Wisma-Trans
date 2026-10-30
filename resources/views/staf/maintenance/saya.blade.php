{{-- Laporan Kerusakan (hanya dibaca): semua kerusakan yang belum selesai, dan laporan yang saya kirim.
     Biaya, nama vendor, dan catatan penyelesaian sengaja tidak ditampilkan; penanganan ada di Penanganan (Manager). --}}
@php
    use Illuminate\Support\Str;

    $pelapor = fn ($l) => Str::before((string) $l->reporter?->name, ' ') ?: '—';
    $tabUrl = fn ($t) => route('staf.maintenance.saya', $t === 'saya' ? ['tab' => 'saya'] : []);
@endphp
<x-layouts.staf title="Laporan Kerusakan">
<div>
    @if (session('peringatan'))
        <div class="mb-4 flex items-start gap-3 rounded-md border border-[rgba(232,172,69,.5)] bg-[rgba(232,172,69,.12)] px-4 py-3 text-[13px] text-[#6B470A]" role="alert">
            <x-tamu.ikon nama="info" ukuran="18" class="mt-px" />
            <span>{{ session('peringatan') }}</span>
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <nav class="wt-geser inline-flex max-w-full rounded-lg border border-cream-300 bg-cream-50 p-0.5" aria-label="Tab laporan kerusakan">
            @foreach (['terbuka' => 'Belum selesai', 'saya' => 'Laporan saya'] as $t => $label)
                <a href="{{ $tabUrl($t) }}" @if ($tab === $t) aria-current="page" @endif
                   @class(['inline-flex h-8 items-center rounded-md px-3.5 text-[13px] font-semibold hover:no-underline',
                       'bg-brown-800 text-cream-50 hover:text-cream-50' => $tab === $t, 'text-ink-500 hover:text-ink-900' => $tab !== $t])>{{ $label }} ({{ $jumlah[$t] }})</a>
            @endforeach
        </nav>
        <p class="m-0 flex-1 text-[13px] text-ink-500">
            {{ $tab === 'saya' ? 'Laporan kerusakan yang Anda kirim dan perkembangannya.' : 'Kerusakan yang sedang menunggu atau dikerjakan, dari semua staf.' }}
        </p>
        <a href="{{ route('staf.maintenance.lapor') }}" class="inline-flex h-10 items-center gap-2 rounded bg-clay-500 px-4 font-display text-[13px] font-semibold text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">
            <x-staf.ikon nama="tambah" ukuran="16" />Lapor kerusakan
        </a>
    </div>

    @if ($tab === 'terbuka')
        <form method="GET" action="{{ route('staf.maintenance.saya') }}" class="mb-4 flex flex-wrap items-center gap-3">
            <label class="relative block min-w-0 flex-[1_1_220px] sm:max-w-[300px]">
                <span class="sr-only">Cari laporan</span>
                <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
                <input type="search" name="cari" value="{{ $saring['cari'] }}" placeholder="Nomor kamar atau kode laporan" class="wt-input pl-9">
            </label>
            <x-staf.pilih name="lokasi" :nilai="$saring['lokasi']" kirim label="Saring lokasi" class="w-[170px]"
                          :pilihan="['' => 'Semua lokasi', 'kamar' => 'Kamar', 'area' => 'Area umum']" />
            <label class="flex items-center gap-2 text-[13px]">
                <input type="checkbox" name="menahan" value="1" @checked($saring['menahan']) onchange="this.form.requestSubmit()" class="accent-brown-800">
                Hanya yang membuat kamar tidak bisa dipakai
            </label>
            <button type="submit" class="wt-btn-sm h-10">Terapkan</button>
            @if ($saring['cari'] !== '' || $saring['lokasi'] !== '' || $saring['menahan'])
                <a href="{{ route('staf.maintenance.saya') }}" class="text-[13px]">Atur ulang</a>
            @endif
        </form>
    @endif

    <div class="wt-card divide-y divide-cream-300 overflow-hidden shadow-wt-xs">
        @forelse ($laporan as $l)
            <a href="{{ request()->fullUrlWithQuery(['lihat' => $l->id]) }}" class="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-4 text-ink-900 hover:bg-row-hover hover:text-ink-900 hover:no-underline">
                <div class="min-w-0 flex-[1_1_320px]">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[12px] font-semibold tabular-nums text-ink-500">{{ $l->request_code }}</span>
                        <span class="font-semibold">{{ $l->lokasiLaporan() }}</span>
                        @if ($tab === 'terbuka' && $l->reported_by === auth()->id())
                            <span class="wt-lencana bg-[rgba(181,113,74,.14)] text-clay-600">Laporan saya</span>
                        @endif
                    </div>
                    <div class="mt-0.5 text-[13px] text-ink-500">{{ Str::limit($l->deskripsiTanpaArea(), 80) }}</div>
                    @if ($l->blocks_room && $l->terbuka())
                        <div class="mt-1.5 inline-flex items-center gap-1.5 text-[12px] font-semibold text-status-dirty"><span class="h-1.5 w-1.5 rounded-full bg-status-dirty"></span>Kamar tidak bisa dipakai</div>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-staf.lencana-mnt :status="$l->status" />
                    <x-staf.lencana-mnt :prioritas="$l->priority" />
                    @if ($l->isJalurCepat())<x-staf.lencana-mnt jalur-cepat />@endif
                </div>
                <div class="w-full text-[12px] text-ink-500 sm:w-auto sm:min-w-[180px] sm:text-right">
                    <div>{{ $pelapor($l) }} · {{ $l->reported_at->translatedFormat('j M H.i') }}</div>
                    @if ($l->status === 'in_progress' && $l->pengerjaUntukStaf())
                        <div class="mt-0.5">Sedang dikerjakan · {{ $l->pengerjaUntukStaf() }}</div>
                    @endif
                </div>
            </a>
        @empty
            <div class="px-6 py-12 text-center text-sm text-ink-500">
                {{ $tab === 'saya' ? 'Anda belum pernah melapor kerusakan.' : 'Tidak ada kerusakan yang belum selesai.' }}
            </div>
        @endforelse
    </div>
    {{ $laporan->links('partials.halaman') }}

    {{-- Detail hanya-baca --}}
    @if ($detail)
        @php $tutup = request()->fullUrlWithoutQuery(['lihat']); @endphp
        <div class="wt-modal fixed inset-0 z-[340] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="window.location.href = @js($tutup)">
            <a href="{{ $tutup }}" class="absolute inset-0 bg-[rgba(62,42,28,.44)]" aria-label="Tutup"></a>
            <div role="dialog" aria-modal="true" aria-labelledby="mnt-judul" class="wt-dialog relative flex max-h-[92dvh] w-[560px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-start gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="mnt-judul" class="flex flex-wrap items-center gap-2.5">
                            <span class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $detail->request_code }}</span>
                            <x-staf.lencana-mnt :status="$detail->status" />
                            <x-staf.lencana-mnt :prioritas="$detail->priority" />
                        </div>
                        <div class="mt-1 text-[13px] text-ink-500">{{ $detail->lokasiLaporan() }}</div>
                    </div>
                    <a href="{{ $tutup }}" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></a>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5 text-[13px]">
                    <p class="m-0 whitespace-pre-line text-[14px] leading-relaxed">{{ $detail->deskripsiTanpaArea() }}</p>
                    @if ($detail->blocks_room && $detail->terbuka())
                        <p class="mb-0 mt-3 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 font-semibold text-status-dirty">Kamar tidak bisa dipakai sampai kerusakan ini selesai.</p>
                    @endif
                    @if ($foto = $detail->fotoUrls())
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            @foreach ($foto as $url)
                                <a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="Foto kerusakan" class="aspect-square w-full rounded object-cover"></a>
                            @endforeach
                        </div>
                    @endif
                    <dl class="m-0 mt-5 grid grid-cols-2 gap-x-6 gap-y-3">
                        <div><dt class="wt-field-label">Lokasi</dt><dd class="m-0 mt-1">{{ $detail->lokasiLaporan() }}</dd></div>
                        <div><dt class="wt-field-label">Status</dt><dd class="m-0 mt-1">{{ $detail->labelStatus() }}</dd></div>
                        <div><dt class="wt-field-label">Pelapor</dt><dd class="m-0 mt-1">{{ $detail->reporter?->name }}</dd></div>
                        <div><dt class="wt-field-label">Dilaporkan</dt><dd class="m-0 mt-1">{{ $detail->reported_at->translatedFormat('j M Y, H.i') }} WITA</dd></div>
                        @if ($detail->status !== 'reported' && $detail->pengerjaUntukStaf())
                            <div><dt class="wt-field-label">Pengerja</dt><dd class="m-0 mt-1">{{ $detail->pengerjaUntukStaf() }}</dd></div>
                        @endif
                    </dl>
                </div>
                @if ($bolehTangani)
                    <div class="flex flex-none border-t border-cream-300 px-6 py-4">
                        <a href="{{ route('staf.maintenance.index', ['tab' => $detail->status, 'cari' => $detail->request_code]) }}" class="wt-btn-sm h-10 hover:no-underline">Buka di Penanganan</a>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
</x-layouts.staf>
