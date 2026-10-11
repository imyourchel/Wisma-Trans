@php
    use App\Enums\RoomStatus;
    use Illuminate\Support\Js;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    // Latar kotak: warna status yang dipudarkan, supaya teks tetap mudah dibaca
    $latar = fn (RoomStatus $s) => "border-color: {$s->color()}; background: color-mix(in srgb, {$s->color()} 11%, #FFFDF6)";
@endphp

{{-- cari: saring kartu kamar menurut nomor di browser, tanpa memuat ulang halaman --}}
<div x-data="{ cari: '', cocok(n) { const k = this.cari.trim().toLowerCase(); return !k || String(n).toLowerCase().includes(k); } }">
    {{-- Ringkasan per status (kamar aktif). Klik kartu untuk menyaring papan. --}}
    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        @foreach (RoomStatus::cases() as $s)
            <button type="button" wire:click="filterBy('{{ $s->value }}')" aria-pressed="{{ $filter === $s->value ? 'true' : 'false' }}"
                    @class(['wt-card flex items-center gap-3 border-l-4 px-4 py-3 text-left hover:bg-row-hover',
                        'ring-2 ring-brown-800 ring-offset-1' => $filter === $s->value])
                    style="border-left-color: {{ $s->color() }}">
                <span class="font-display text-2xl font-extrabold tabular-nums" style="color: {{ $s->color() }}">{{ $counts[$s->value] ?? 0 }}</span>
                <span class="text-[12px] font-medium leading-tight text-ink-900">{{ $s->label() }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3 text-[13px] text-ink-500">
        <label wire:ignore class="relative block w-[190px]">
            <span class="sr-only">Cari nomor kamar</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" x-model="cari" inputmode="numeric" placeholder="Cari nomor kamar" class="wt-input h-9 pl-9" @keydown.escape="cari = ''">
        </label>
        @if ($filter !== '')
            <span>Menampilkan status <strong class="text-ink-900">{{ RoomStatus::from($filter)->label() }}</strong></span>
            <button type="button" wire:click="filterBy('')" class="wt-btn-sm">Tampilkan semua ({{ $total }})</button>
        @else
            <span>{{ $total }} kamar aktif</span>
        @endif
        {{-- Tautan biasa (bukan wire:click) supaya sidebar dan judul halaman ikut berganti --}}
        <nav class="inline-flex rounded-lg border border-cream-300 bg-cream-50 p-0.5" aria-label="Tampilan papan">
            @foreach (['papan' => 'Papan', 'kalender' => 'Kalender'] as $v => $l)
                <a href="{{ route('staf.papan-status', array_filter(['tampilan' => $v === 'kalender' ? 'kalender' : null, 'status' => $filter ?: null])) }}"
                   @if ($view === $v) aria-current="page" @endif
                   @class(['inline-flex h-8 items-center rounded-md px-3.5 text-[13px] font-semibold transition-colors hover:no-underline',
                       'bg-brown-800 text-cream-50 hover:text-cream-50' => $view === $v, 'text-ink-500 hover:text-ink-900' => $view !== $v])>{{ $l }}</a>
            @endforeach
        </nav>
        {{-- Muat ulang otomatis tiap 30 detik; elemen polling dilepas selama detail kamar terbuka --}}
        @if ($selected)
            <span wire:key="poll-jeda" class="ml-auto">Diperbarui {{ now()->format('H.i') }} WITA · jeda</span>
        @else
            <span wire:key="poll-jalan" wire:poll.30s class="ml-auto">Diperbarui {{ now()->format('H.i') }} WITA</span>
        @endif
    </div>

    @if ($view === 'kalender')
    {{-- Kalender: pemesanan per kamar selama 14 hari. Balok = malam menginap (check-in sampai sehari sebelum check-out). --}}
    @php
        $gayaBalok = [
            'menginap' => 'bg-brown-800 text-cream-50',
            'akan' => 'bg-[rgba(181,113,74,.14)] text-clay-600 ring-1 ring-inset ring-[rgba(181,113,74,.45)]',
            'selesai' => 'bg-cream-300 text-ink-500',
        ];
        $namaJenis = ['menginap' => 'Sedang menginap', 'akan' => 'Akan datang', 'selesai' => 'Sudah check-out'];
        $hariIni = today();
        $arsir = 'background: repeating-linear-gradient(135deg, rgba(201,121,58,.24) 0 6px, rgba(201,121,58,.07) 6px 12px)';
        // Sel sisa: merah bila habis, kuning bila tinggal satu
        $warnaSisa = fn (int $n) => match (true) { $n <= 0 => 'bg-[rgba(212,81,74,.14)] text-status-dirty', $n === 1 => 'bg-[rgba(232,172,69,.24)] text-[#8A5E10]', default => 'text-ink-900' };
        $teksBalok = fn ($b) => $b->nama ? $b->nama.($b->instansi ? ' ('.$b->instansi.')' : '').' · '.$b->kode : $b->kode;
    @endphp
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="shiftDays(-7)" class="wt-btn-sm" aria-label="7 hari sebelumnya">‹</button>
        <button type="button" wire:click="shiftDays(0)" class="wt-btn-sm">Hari ini</button>
        <button type="button" wire:click="shiftDays(7)" class="wt-btn-sm" aria-label="7 hari berikutnya">›</button>
        {{-- Pemilih tanggal: tanggal yang dipilih menjadi kolom pertama; pita menandai 14 hari yang akan tampil.
             wire:key ikut tanggal awal supaya kalender kecil mengikuti tombol geser. --}}
        @php $awalKal = $kalender['hari']->first(); @endphp
        <div wire:key="pilih-tgl-{{ $awalKal->toDateString() }}" class="relative ml-1"
             x-data="pilihTanggal(@js(['mulai' => $awalKal->toDateString(), 'hariIni' => today()->toDateString(), 'panjang' => $kalender['hari']->count()]))"
             @click.outside="buka = false" @keydown.escape="buka = false">
            <button type="button" @click="bukaTutup()" :aria-expanded="buka" aria-haspopup="dialog" title="Pilih tanggal"
                    class="inline-flex h-8 items-center gap-2 rounded border border-cream-300 bg-cream-50 px-3 font-display text-sm font-bold uppercase tracking-[.04em] text-ink-900 hover:bg-cream-200"
                    :class="buka && 'border-brown-800 bg-cream-200'">
                <x-staf.ikon nama="kalender" ukuran="15" class="text-clay-600" />
                {{ $awalKal->translatedFormat('j M') }} – {{ $kalender['akhir']->translatedFormat('j M Y') }}
                <x-staf.ikon nama="panah" ukuran="14" class="text-ink-500 transition-transform" ::class="buka && 'rotate-180'" />
            </button>

            <div x-show="buka" x-cloak role="dialog" aria-label="Pilih tanggal awal kalender"
                 x-transition:enter="transition duration-150 ease-wt" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                 x-transition:leave="transition duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="absolute left-0 top-full z-50 mt-2 w-[308px] rounded-lg border border-cream-300 bg-cream-50 p-4 shadow-wt-lg">
                <div class="mb-3 flex items-center gap-2">
                    <button type="button" @click="geser(-1)" aria-label="Bulan sebelumnya"
                            class="flex h-8 w-8 items-center justify-center rounded-md border-0 bg-transparent text-brown-800 hover:bg-cream-200">
                        <x-staf.ikon nama="panah" ukuran="16" class="rotate-90" />
                    </button>
                    <div class="flex-1 text-center font-display text-sm font-bold uppercase tracking-[.08em] text-ink-900" x-text="judul" aria-live="polite"></div>
                    <button type="button" @click="geser(1)" aria-label="Bulan berikutnya"
                            class="flex h-8 w-8 items-center justify-center rounded-md border-0 bg-transparent text-brown-800 hover:bg-cream-200">
                        <x-staf.ikon nama="panah" ukuran="16" class="-rotate-90" />
                    </button>
                </div>

                <div class="grid grid-cols-7 pb-1">
                    @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $h)
                        <div @class(['py-1 text-center font-display text-[10px] font-semibold uppercase tracking-[.1em]', 'text-clay-600' => $h === 'Min', 'text-ink-500' => $h !== 'Min'])>{{ $h }}</div>
                    @endforeach
                </div>
                <div class="grid grid-cols-7 gap-y-1" @mouseleave="sorot = null">
                    <template x-for="c in sel" :key="c.t">
                        <button type="button" @click="pilih(c.t)" @mouseenter="sorot = c.t" :aria-label="c.label" :aria-pressed="c.awal"
                                class="relative h-9 border-0 p-0 text-[13px] tabular-nums transition-colors" :class="kelas(c)">
                            <span x-text="c.n"></span>
                            <span x-show="c.hariIni" class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full" :class="c.awal ? 'bg-sand-300' : 'bg-clay-500'"></span>
                        </button>
                    </template>
                </div>

                <div class="mt-3 flex items-center justify-between gap-3 border-t border-cream-300 pt-3">
                    <p class="m-0 text-xs leading-snug text-ink-500">
                        <span x-text="panjang"></span> hari mulai <span class="font-semibold text-ink-900" x-text="labelAcuan"></span>
                    </p>
                    <button type="button" @click="pilih(hariIni)" class="h-8 flex-none rounded-md border border-cream-300 bg-transparent px-3 text-xs font-semibold text-brown-800 hover:bg-cream-200">Hari ini</button>
                </div>
            </div>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-3 text-xs text-ink-500">
            @foreach ($namaJenis as $jenis => $label)
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-5 rounded-sm {{ $gayaBalok[$jenis] }}"></span>{{ $label }}</span>
            @endforeach
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-5 rounded-sm" style="{{ $arsir }}"></span>Perbaikan</span>
        </div>
    </div>
    <p class="m-0 mb-3 text-xs text-ink-500" data-keterangan-sisa>
        Sisa kamar mengikuti aturan pemesanan: kamar Perbaikan tidak dihitung tersedia, dan pemesanan yang belum dapat nomor kamar ikut mengurangi sisa.
        @if ($bolehPesan) Klik sel kosong (hari ini dan sesudahnya) untuk membuat reservasi langsung. @endif
    </p>

    {{-- Layar lebar: kisi kamar × tanggal --}}
    <div class="wt-card hidden overflow-x-auto md:block">
        <div class="min-w-[1080px]">
            <div class="grid grid-cols-[150px_minmax(0,1fr)] border-b border-cream-300 bg-cream-200">
                <div class="sticky left-0 z-20 flex items-end bg-cream-200 px-3 pb-2 font-display text-[11px] font-bold uppercase tracking-[.06em] text-ink-500">Kamar</div>
                <div class="grid grid-cols-14">
                    @foreach ($kalender['hari'] as $h)
                        @php $hariIniKah = $h->isSameDay($hariIni); @endphp
                        <div @class(['border-l border-cream-300 px-1 py-1.5 text-center', 'bg-[rgba(181,113,74,.16)]' => $hariIniKah])>
                            <div @class(['text-[10px] font-semibold uppercase tracking-[.08em]', 'text-clay-600' => $hariIniKah, 'text-ink-500' => ! $hariIniKah])>{{ $h->translatedFormat('D') }}</div>
                            <div @class(['font-display text-sm font-bold tabular-nums', 'text-clay-600' => $hariIniKah, 'text-ink-900' => ! $hariIniKah])>
                                {{ $h->format('j') }}@if ($loop->first || $h->day === 1)<span class="ml-0.5 text-[10px] font-semibold text-ink-500">{{ $h->translatedFormat('M') }}</span>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Sisa kamar per tipe per malam --}}
            <div class="border-b border-cream-300 bg-cream-100 px-3 py-1.5 font-display text-[11px] font-bold uppercase tracking-[.08em] text-ink-500">Sisa kamar</div>
            @foreach ($kalender['tipe'] as $t)
                <div wire:key="sisa-{{ $t->id }}" class="grid grid-cols-[150px_minmax(0,1fr)] border-b border-cream-300" data-sisa-tipe="{{ $t->code }}">
                    <div class="sticky left-0 z-20 flex items-center gap-2 border-r border-cream-300 bg-cream-50 px-3 py-1.5 text-[12px] font-semibold text-ink-900">
                        <span class="truncate">{{ $t->name }}</span>
                    </div>
                    <div class="grid grid-cols-14">
                        @foreach ($kalender['hari'] as $h)
                            @php $n = (int) ($kalender['sisa'][$h->toDateString()][$t->id] ?? 0); @endphp
                            <div @class(['border-l border-cream-300/70 py-1.5 text-center text-[12px] font-semibold tabular-nums', $warnaSisa($n)])
                                 title="{{ $t->name }}, malam {{ $h->translatedFormat('j M') }}: sisa {{ $n }} dari {{ $kalender['jumlahKamar'][$t->id] ?? 0 }} kamar">{{ $n }}/{{ $kalender['jumlahKamar'][$t->id] ?? 0 }}</div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @forelse ($floors as $lantai => $rooms)
                <div wire:key="kal-lantai-{{ $lantai }}" x-show="{{ Js::from($rooms->pluck('room_number')->values()) }}.some(n => cocok(n))" class="border-b border-cream-300 bg-cream-100 px-3 py-1.5 font-display text-[11px] font-bold uppercase tracking-[.08em] text-ink-500">Lantai {{ $lantai }}</div>
                @foreach ($rooms as $room)
                    @php $perbaikan = $room->status === RoomStatus::Maintenance; @endphp
                    <div wire:key="kal-{{ $room->id }}" x-show="cocok('{{ $room->room_number }}')" class="grid grid-cols-[150px_minmax(0,1fr)] border-b border-cream-300">
                        <button type="button" wire:click="open({{ $room->id }})" title="Kamar {{ $room->room_number }}: {{ $room->status->label() }}"
                                class="sticky left-0 z-20 flex items-center gap-2 border-0 border-r border-cream-300 bg-cream-50 px-3 text-left hover:bg-row-hover">
                            <span class="h-2.5 w-2.5 flex-none rounded-full" style="background-color: {{ $room->status->color() }}"></span>
                            <span class="font-display text-sm font-bold tabular-nums text-ink-900">{{ $room->room_number }}</span>
                            <span class="text-[11px] font-semibold text-ink-500">{{ $room->roomType?->code }}</span>
                        </button>
                        <div class="grid h-12 grid-cols-14">
                            @foreach ($kalender['hari'] as $h)
                                @php $hariIniKah = $h->isSameDay($hariIni); @endphp
                                @if ($perbaikan && $hariIniKah)
                                    {{-- Kamar yang sekarang Perbaikan: arsir dan label di kolom hari ini --}}
                                    <div style="grid-row: 1; grid-column: {{ $loop->iteration }}; {{ $arsir }}" data-perbaikan="{{ $room->room_number }}"
                                         class="flex items-center justify-center border-l border-cream-300/70 text-[10px] font-bold uppercase tracking-[.06em] text-[#9A5A26]">Perbaikan</div>
                                @elseif ($bolehPesan && ! $h->lt($hariIni))
                                    <a href="{{ route('staf.reservasi-langsung', ['kamar' => $room->id, 'masuk' => $h->toDateString()]) }}" style="grid-row: 1; grid-column: {{ $loop->iteration }}"
                                       title="Reservasi langsung kamar {{ $room->room_number }} mulai {{ $h->translatedFormat('j M') }}" data-sel-kosong
                                       @class(['border-l border-cream-300/70 hover:bg-[rgba(181,113,74,.14)]', 'bg-[rgba(181,113,74,.07)]' => $hariIniKah, 'bg-[rgba(229,220,198,.28)]' => $h->isWeekend() && ! $hariIniKah])></a>
                                @else
                                    <div style="grid-row: 1; grid-column: {{ $loop->iteration }}"
                                         @class(['border-l border-cream-300/70', 'bg-[rgba(181,113,74,.07)]' => $hariIniKah, 'bg-[rgba(229,220,198,.28)]' => $h->isWeekend() && ! $hariIniKah])></div>
                                @endif
                            @endforeach
                            @foreach ($kalender['perKamar'][$room->id] ?? [] as $b)
                                @include('livewire.staf.partials.balok-kalender', ['b' => $b])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @empty
                <div class="px-6 py-12 text-center text-sm text-ink-500">Tidak ada kamar dengan status ini.</div>
            @endforelse

            {{-- Pemesanan yang belum dapat nomor kamar, di bawah lantai terakhir --}}
            @foreach ($kalender['tanpaKamar'] as $tipe => $lajur)
                @foreach ($lajur as $isi)
                    <div wire:key="tanpa-{{ \Illuminate\Support\Str::slug($tipe) }}-{{ $loop->index }}" class="grid grid-cols-[150px_minmax(0,1fr)] border-b border-cream-300 bg-[rgba(232,172,69,.05)]">
                        <div class="sticky left-0 z-20 flex items-center border-r border-cream-300 bg-cream-50 px-3 text-[11px] font-semibold leading-tight text-ink-500">
                            {{ $loop->first ? 'Belum dapat kamar · '.$tipe : '' }}
                        </div>
                        <div class="grid h-12 grid-cols-14">
                            @foreach ($kalender['hari'] as $h)
                                <div style="grid-row: 1; grid-column: {{ $loop->iteration }}" class="border-l border-cream-300/70"></div>
                            @endforeach
                            @foreach ($isi as $b)
                                @include('livewire.staf.partials.balok-kalender', ['b' => $b])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    {{-- Layar sempit (< 768 px): daftar per tanggal sebagai pengganti kisi --}}
    <div class="flex flex-col gap-3 md:hidden" data-kalender-daftar>
        @foreach ($kalender['perTanggal'] as $tanggal => $daftarTipe)
            @php $h = \Illuminate\Support\Carbon::parse($tanggal); @endphp
            <section wire:key="hp-{{ $tanggal }}" class="wt-card p-4">
                <h3 @class(['m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]', 'text-clay-600' => $h->isSameDay($hariIni)])>{{ $h->translatedFormat('l, j M') }}</h3>
                <ul class="m-0 mt-2 flex list-none flex-col gap-2 p-0 text-[13px]">
                    @foreach ($daftarTipe as $x)
                        <li>
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="font-semibold">{{ $x['tipe']->name }}</span>
                                <span @class(['rounded px-1.5 text-[12px] font-semibold tabular-nums', $warnaSisa($x['sisa'])])>sisa {{ $x['sisa'] }}/{{ $kalender['jumlahKamar'][$x['tipe']->id] ?? 0 }}</span>
                            </div>
                            @if ($x['terisi']->isNotEmpty())
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach ($x['terisi'] as $isi)
                                        @php $label = ($isi['kamar'] ? 'Kamar '.$isi['kamar'] : 'Belum dapat kamar').' · '.$isi['kode']; @endphp
                                        @if ($isi['url'])
                                            <a href="{{ $isi['url'] }}" class="rounded bg-cream-200 px-2 py-0.5 text-[12px] text-ink-900 hover:no-underline">{{ $label }}</a>
                                        @else
                                            <span class="rounded bg-cream-200 px-2 py-0.5 text-[12px]">{{ $label }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
    @else
    <div x-show="cari.trim() && !{{ Js::from($floors->flatten()->pluck('room_number')->values()) }}.some(n => cocok(n))" x-cloak
         class="wt-card mb-4 px-6 py-8 text-center text-sm text-ink-500">Tidak ada kamar bernomor “<span x-text="cari.trim()"></span>”{{ $filter !== '' ? ' dengan status ini' : '' }}.</div>
    @forelse ($floors as $lantai => $rooms)
        <section wire:key="lantai-{{ $lantai }}" x-show="{{ Js::from($rooms->pluck('room_number')->values()) }}.some(n => cocok(n))" class="mb-6">
            <h2 class="mb-3 font-display text-sm font-bold uppercase tracking-[.06em] text-ink-500">Lantai {{ $lantai }}</h2>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(132px,1fr))] gap-3">
                @foreach ($rooms as $room)
                    <button type="button" wire:key="kotak-{{ $room->id }}" x-show="cocok('{{ $room->room_number }}')" wire:click="open({{ $room->id }})"
                            aria-label="Kamar {{ $room->room_number }}, {{ $room->status->label() }}"
                            class="flex min-h-[112px] flex-col rounded-md border border-l-[5px] p-3 text-left shadow-wt-xs transition hover:-translate-y-0.5 hover:shadow-wt-lg"
                            style="{{ $latar($room->status) }}">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="font-display text-[26px] font-extrabold leading-none tabular-nums text-ink-900">{{ $room->room_number }}</span>
                            <span class="text-[11px] font-semibold text-ink-500">{{ $room->roomType?->code }}</span>
                        </span>
                        <span class="mt-auto pt-3 text-[12px] font-semibold leading-tight" style="color: {{ $room->status->color() }}">{{ $room->status->label() }}</span>
                        @if ($lama = $room->dirtyDuration())
                            <span class="mt-0.5 text-[11px] leading-tight text-ink-500">{{ $lama }}</span>
                        @endif
                        @if (isset($rusak[$room->id]) && $room->status !== RoomStatus::Maintenance)
                            <span class="mt-1.5 inline-flex self-start rounded bg-[rgba(212,81,74,.12)] px-1.5 py-0.5 text-[10px] font-semibold leading-tight text-status-dirty"
                                  title="{{ $rusak[$room->id] }}: kamar tidak bisa dipakai. Setelah dibersihkan, kamar langsung ditandai Perbaikan.">Ada laporan kerusakan</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </section>
    @empty
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">Tidak ada kamar dengan status ini.</div>
    @endforelse
    @endif

    {{-- Detail kamar: ganti status (FO, HK, Admin) dan riwayat 10 perubahan terakhir --}}
    @if ($selected)
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.jalurCepat ? $wire.tutupJalurCepat() : $wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="detail-kamar-judul"
                 class="relative flex max-h-[92vh] w-[560px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="detail-kamar-judul" class="font-display text-[17px] font-extrabold uppercase leading-tight tracking-[.04em]">Kamar {{ $selected->room_number }}</div>
                        <div class="mt-1 inline-flex items-center gap-2 text-[13px]">
                            <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $selected->status->color() }}"></span>
                            {{ $selected->status->label() }}
                            @if ($lama = $selected->dirtyDuration())<span class="text-ink-500">· {{ $lama }}</span>@endif
                        </div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup">
                        <x-staf.ikon nama="tutup" ukuran="16" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <dl class="m-0 grid grid-cols-2 gap-x-6 gap-y-3 text-[13px] sm:grid-cols-4">
                        <div><dt class="wt-field-label">Tipe</dt><dd class="m-0 mt-1">{{ $selected->roomType?->name }}</dd></div>
                        <div><dt class="wt-field-label">Kasur</dt><dd class="m-0 mt-1">{{ $selected->bedTypeLabel() }}</dd></div>
                        <div><dt class="wt-field-label">Lantai</dt><dd class="m-0 mt-1">{{ $selected->floor }}</dd></div>
                        <div><dt class="wt-field-label">Harga dasar</dt><dd class="m-0 mt-1 tabular-nums">{{ $rp($selected->roomType?->base_price) }}</dd></div>
                    </dl>

                    @if (isset($rusak[$selected->id]) && $selected->status !== RoomStatus::Maintenance)
                        <p class="mb-0 mt-4 rounded-md border border-[rgba(212,81,74,.3)] bg-[rgba(212,81,74,.06)] px-3 py-2 text-xs text-status-dirty">
                            Ada laporan kerusakan yang belum selesai ({{ $rusak[$selected->id] }}) dan membuat kamar ini tidak bisa dipakai.
                            Setelah tamu keluar dan kamar dibersihkan, kamar langsung ditandai Perbaikan, bukan Siap.
                        </p>
                    @endif

                    @if ($canChange && $options)
                        <form wire:submit="save" novalidate class="mt-5 flex flex-col gap-4 border-t border-cream-300 pt-5">
                            <x-staf.isian nama="status" label="Ubah status menjadi" tag="div">
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    @foreach ($options as $s)
                                        <label wire:key="pilihan-{{ $s->value }}" class="flex cursor-pointer items-center gap-2.5 rounded border border-cream-300 px-3 py-2 text-[13px] has-[:checked]:border-brown-800 has-[:checked]:bg-cream-200 has-[:checked]:font-semibold">
                                            <input type="radio" wire:model.live="status" value="{{ $s->value }}" class="accent-brown-800">
                                            <span class="h-2.5 w-2.5 flex-none rounded-full" style="background-color: {{ $s->color() }}"></span>
                                            <span>{{ $s->label() }}</span>
                                            @if ($s === $suggested)<span class="ml-auto text-[11px] font-normal text-status-available">disarankan</span>@endif
                                        </label>
                                    @endforeach
                                </div>
                            </x-staf.isian>
                            <x-staf.isian nama="note" :label="$status === 'maintenance' ? 'Catatan (wajib untuk Perbaikan)' : 'Catatan (opsional)'">
                                <input type="text" wire:model="note" maxlength="255" placeholder="{{ $status === 'maintenance' ? 'Contoh: AC tidak dingin' : 'Contoh: tamu minta kamar dirapikan' }}"
                                       @class(['wt-input', 'is-invalid' => $errors->has('note')])>
                            </x-staf.isian>
                            <div class="flex gap-2">
                                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                                        class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70">
                                    <span wire:loading.remove wire:target="save">Simpan</span>
                                    <span wire:loading wire:target="save">Menyimpan…</span>
                                </button>
                                <button type="button" wire:click="close" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                            </div>
                        </form>
                    @elseif ($canChange)
                        <p class="mb-0 mt-5 rounded bg-cream-200 px-4 py-3 text-[13px] text-ink-900">Tidak ada perubahan status dari <strong>{{ $selected->status->label() }}</strong> yang bisa dilakukan peran Anda.</p>
                    @else
                        <p class="mb-0 mt-5 rounded bg-cream-200 px-4 py-3 text-[13px] text-ink-900">Anda dapat melihat status kamar. Perubahan status dilakukan oleh Front Office atau Housekeeping.</p>
                    @endif

                    {{-- Kamar Perbaikan yang tertahan laporan kerusakan: dibuka lewat jalur cepat dengan persetujuan Manager --}}
                    @if ($penahan->isNotEmpty())
                        <div class="mt-4 rounded-md border border-cream-300 bg-cream-100 px-4 py-3 text-[13px]">
                            <p class="m-0 text-ink-900">Kamar ini belum bisa dibuka sampai laporan {{ $penahan->pluck('kode')->implode(', ') }} ditutup. Bila perbaikan sudah beres dan Manager ada di lokasi, minta persetujuannya di sini.</p>
                            <button type="button" wire:click="bukaJalurCepat" class="wt-btn-sm mt-3 h-9">Buka dengan persetujuan Manager</button>
                        </div>
                    @endif

                    <h3 class="mb-2 mt-6 font-display text-[13px] font-bold uppercase tracking-[.06em] text-ink-500">Riwayat perubahan</h3>
                    @if ($history->isEmpty())
                        <p class="m-0 text-[13px] text-ink-500">Belum ada riwayat perubahan status.</p>
                    @else
                        <ol class="m-0 flex list-none flex-col p-0">
                            @foreach ($history as $log)
                                <li wire:key="log-{{ $log->id }}" class="flex gap-3 border-t border-cream-300 py-2.5 text-[13px] first:border-t-0">
                                    <span class="mt-1.5 h-2 w-2 flex-none rounded-full" style="background-color: {{ $log->status->color() }}"></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                            <span class="font-semibold">{{ $log->status->label() }}</span>
                                            <span class="text-[12px] tabular-nums text-ink-500">{{ $log->changed_at->translatedFormat('d M Y, H.i') }}</span>
                                        </div>
                                        <div class="text-[12px] text-ink-500">oleh {{ $log->changedBy?->name ?? 'Sistem' }}</div>
                                        @if ($log->note)<div class="mt-0.5 text-[12px] text-ink-900">{{ $log->note }}</div>@endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    <div class="mb-2 mt-6 flex items-baseline justify-between gap-3">
                        <h3 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em] text-ink-500">Riwayat perawatan</h3>
                        @if ($bolehMaintenance)
                            <a href="{{ route('staf.maintenance.riwayat', ['kamar' => $selected->id]) }}" class="text-[12px] font-semibold">Lihat semua</a>
                        @endif
                    </div>
                    @if ($perawatan->isEmpty())
                        <p class="m-0 text-[13px] text-ink-500">Belum ada laporan perawatan untuk kamar ini.</p>
                    @else
                        <ul class="m-0 flex list-none flex-col p-0">
                            @foreach ($perawatan as $m)
                                <li wire:key="mnt-{{ $m->id }}" class="border-t border-cream-300 py-2.5 text-[13px] first:border-t-0">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="font-semibold tabular-nums">{{ $m->request_code }} <span class="font-normal text-ink-500">· {{ $m->lokasi() }}</span></span>
                                        <x-staf.lencana-mnt :status="$m->status" />
                                    </div>
                                    <div class="mt-0.5 line-clamp-2 text-[12px] text-ink-900">{{ $m->damage_description }}</div>
                                    <div class="text-[12px] text-ink-500">{{ $m->reported_at->translatedFormat('d M Y') }}{{ $m->pengerja() ? ' · '.$m->pengerja() : '' }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @if ($jalurCepat && $penahan->isNotEmpty())
                <div class="absolute inset-0 z-10 flex items-center justify-center p-4">
                    <div wire:click="tutupJalurCepat" class="absolute inset-0 bg-[rgba(62,42,28,.30)]"></div>
                    <form wire:submit="simpanJalurCepat" novalidate role="dialog" aria-modal="true" aria-labelledby="jalur-cepat-judul"
                          class="relative flex w-[440px] max-w-full animate-wt-fade flex-col gap-4 rounded-md border border-cream-300 bg-cream-50 p-6 shadow-wt-lg">
                        <div>
                            <div id="jalur-cepat-judul" class="font-display text-[15px] font-extrabold uppercase tracking-[.04em]">Buka Kamar {{ $selected->room_number }}</div>
                            <p class="mb-0 mt-1 text-[13px] text-ink-500">Laporan berikut ditutup atas persetujuan Manager, lalu kamar menjadi Siap. Biaya dan pengerja dilengkapi Manager nanti.</p>
                        </div>
                        <ul class="m-0 flex list-none flex-col gap-1.5 rounded-md bg-cream-100 p-3 text-[13px]">
                            @foreach ($penahan as $p)
                                <li wire:key="penahan-{{ $p['kode'] }}"><span class="font-semibold tabular-nums">{{ $p['kode'] }}</span> <span class="text-ink-500">· {{ $p['deskripsi'] }}</span></li>
                            @endforeach
                        </ul>
                        <x-staf.isian nama="emailManager" label="Email Manager">
                            <input type="email" wire:model="emailManager" autocomplete="off" placeholder="manager@wismatrans.id" @class(['wt-input', 'is-invalid' => $errors->has('emailManager')])>
                        </x-staf.isian>
                        <x-staf.isian nama="sandiManager" label="Kata sandi Manager">
                            <input type="password" wire:model="sandiManager" autocomplete="new-password" @class(['wt-input', 'is-invalid' => $errors->has('sandiManager')])>
                        </x-staf.isian>
                        <div class="flex gap-2">
                            <button type="submit" wire:loading.attr="disabled" wire:target="simpanJalurCepat"
                                    class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70">Setujui dan buka kamar</button>
                            <button type="button" wire:click="tutupJalurCepat" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    @endif
</div>
