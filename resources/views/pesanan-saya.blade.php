@php
    use App\Support\PesananAkun;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $gayaKeadaan = [
        'belum-bayar' => 'bg-[rgba(232,172,69,.16)] text-[#8A5A0B] ring-[rgba(232,172,69,.35)]',
        'diproses' => 'bg-[rgba(108,149,196,.12)] text-[#3F6B9C] ring-[rgba(108,149,196,.3)]',
        'selesai' => 'bg-[rgba(92,158,103,.12)] text-status-available ring-[rgba(92,158,103,.3)]',
        'batal' => 'bg-[rgba(212,81,74,.08)] text-status-dirty ring-[rgba(212,81,74,.25)]',
    ];
    $titik = ['belum-bayar' => 'bg-status-maintenance', 'diproses' => 'bg-status-reserved', 'selesai' => 'bg-status-available', 'batal' => 'bg-status-dirty'];
    $ikonJenis = ['kamar' => 'kasur', 'makanan' => 'makanan', 'laundry' => 'laundry', 'aula' => 'gedung'];
    $pesanan = $pesanan->values();
    // Data ringkas tiap pesanan untuk penyaring di peramban (urutan sama dengan kartu)
    $data = $pesanan->map(fn ($p) => ['j' => $p['jenis'], 'k' => $p['keadaan'], 'b' => $p['bulan'], 't' => $p['tanggal'], 'n' => $p['total']]);
    $ajakan = [
        'semua' => [['Pesan kamar', route('kamar'), true], ['Lihat menu Resto', route('menu'), false]],
        'kamar' => [['Pesan kamar', route('kamar'), true]],
        'makanan' => [['Lihat menu Resto', route('menu'), true]],
        'laundry' => [['Layanan laundry', route('laundry'), true]],
        'aula' => [['Ajukan sewa aula', route('aula'), true]],
    ];
@endphp

<x-layouts.guest :title="$title">
<div class="wt-container max-w-[1040px] animate-wt-masuk py-10 sm:py-14"
     x-data="pesananSaya(@js(['data' => $data, 'hariIni' => today()->toDateString(), 'keadaan' => PesananAkun::KEADAAN, 'jenis' => PesananAkun::JENIS]))">

    {{-- Kepala halaman --}}
    <header>
        <div class="wt-eyebrow">Akun saya</div>
        <h1 class="mb-0 mt-1.5 font-display text-2xl font-extrabold uppercase tracking-[.04em] sm:text-[28px]">Pesanan saya</h1>
        <p class="mb-0 mt-1.5 text-sm text-ink-500">Pantau pesanan kamar, makanan, laundry, dan aula Anda di satu tempat.</p>
    </header>

    {{-- Ringkasan: mengikuti jenis dan periode yang dipilih --}}
    <div class="mt-7 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['semua', 'Semua pesanan', 'bg-cream-200 text-clay-600', 'kalender'],
            ['belum-bayar', 'Menunggu pembayaran', 'bg-[rgba(232,172,69,.18)] text-[#8A5A0B]', 'jam'],
            ['diproses', 'Sedang aktif', 'bg-[rgba(108,149,196,.14)] text-[#3F6B9C]', 'info'],
        ] as [$kunci, $judul, $warna, $ikon])
            <button type="button" @click="saring = '{{ $kunci }}'"
                    class="group flex items-start justify-between gap-2 rounded-xl border bg-cream-50 p-4 text-left shadow-wt-xs transition hover:-translate-y-0.5 hover:shadow-wt-md"
                    :class="saring === '{{ $kunci }}' ? 'border-clay-500 ring-1 ring-clay-500' : 'border-cream-300'">
                <span class="min-w-0">
                    <span class="block font-display text-2xl font-extrabold leading-none tabular-nums text-ink-900" x-text="hitung(jenis, '{{ $kunci }}')">0</span>
                    <span class="mt-2 block text-xs leading-snug text-ink-500">{{ $judul }}</span>
                </span>
                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg {{ $warna }}"><x-tamu.ikon :nama="$ikon" ukuran="16" /></span>
            </button>
        @endforeach
        <div class="relative flex items-start justify-between gap-2 overflow-hidden rounded-xl bg-brown-800 p-4 text-cream-50 shadow-wt-xs">
            <div class="pointer-events-none absolute -bottom-10 -right-8 h-28 w-28 rounded-full bg-[rgba(255,253,246,.06)]"></div>
            <span class="relative min-w-0">
                <span class="block font-display text-lg font-extrabold leading-none tabular-nums sm:text-2xl" x-text="rupiah(totalBelanja)">Rp0</span>
                <span class="mt-2 block text-xs leading-snug text-[rgba(242,235,220,.75)]">Total transaksi, tanpa yang batal</span>
            </span>
            <span class="relative hidden h-8 w-8 flex-none items-center justify-center rounded-lg bg-[rgba(255,253,246,.12)] text-sand-300 sm:flex"><x-tamu.ikon nama="dompet" ukuran="16" /></span>
        </div>
    </div>

    {{-- Bilah penyaring --}}
    <div class="sticky top-[72px] z-20 -mx-4 mt-6 border-b border-cream-300 bg-[rgba(245,241,230,.92)] px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border sm:px-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="-mx-4 min-w-0 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:px-0">
                <div class="inline-flex gap-1 rounded-full bg-cream-200 p-1" role="tablist" aria-label="Jenis pesanan">
                    @foreach (['semua' => 'Semua'] + PesananAkun::JENIS as $kunci => $nama)
                        <button type="button" role="tab" @click="jenis = '{{ $kunci }}'" :aria-selected="jenis === '{{ $kunci }}'"
                                class="inline-flex h-9 flex-none items-center gap-1.5 whitespace-nowrap rounded-full border-0 px-3.5 text-[13px] font-semibold transition"
                                :class="jenis === '{{ $kunci }}' ? 'bg-cream-50 text-ink-900 shadow-wt-xs' : 'bg-transparent text-ink-500 hover:text-ink-900'">
                            @if ($kunci !== 'semua')
                                <x-tamu.ikon :nama="$ikonJenis[$kunci]" ukuran="15" />
                            @endif
                            {{ $nama }}
                            <span class="text-[11px] tabular-nums" :class="jenis === '{{ $kunci }}' ? 'text-clay-600' : 'text-ink-500'" x-text="hitung('{{ $kunci }}', 'semua')"></span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-none items-center gap-2">
                {{-- Periode --}}
                <div class="relative" @click.outside="bukaPeriode = false" @keydown.escape.window="bukaPeriode = false">
                    <button type="button" @click="togglePeriode()" :aria-expanded="bukaPeriode"
                            class="inline-flex h-9 items-center gap-2 whitespace-nowrap rounded-full border bg-cream-50 px-3.5 text-[13px] font-semibold transition hover:bg-white"
                            :class="dari ? 'border-clay-500 text-clay-600' : 'border-cream-300 text-ink-900'">
                        <x-tamu.ikon nama="kalender" ukuran="15" />
                        <span class="max-w-[170px] truncate" x-text="labelPeriode">Semua waktu</span>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" class="opacity-60 transition" :class="bukaPeriode && 'rotate-180'"><path d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div x-show="bukaPeriode" x-cloak x-transition.opacity.duration.150ms
                         class="absolute left-0 top-[calc(100%+8px)] z-30 flex w-[min(560px,calc(100vw-32px))] flex-col overflow-hidden rounded-xl border border-cream-300 bg-cream-50 shadow-wt-lg sm:flex-row lg:left-auto lg:right-0">
                        {{-- Pilihan cepat --}}
                        <div class="flex gap-1 overflow-x-auto border-b border-cream-300 p-2 [scrollbar-width:none] sm:w-[180px] sm:flex-none sm:flex-col sm:border-b-0 sm:border-r">
                            <template x-for="pr in presets" :key="pr.kunci">
                                <button type="button" @click="pakaiPreset(pr)"
                                        class="flex-none whitespace-nowrap rounded-lg border-0 px-3 py-2 text-left text-[13px] transition"
                                        :class="presetAktif === pr.kunci ? 'bg-brown-800 font-semibold text-cream-50' : 'bg-transparent text-ink-900 hover:bg-cream-200'"
                                        x-text="pr.label"></button>
                            </template>
                        </div>

                        {{-- Rentang sendiri --}}
                        <div class="min-w-0 flex-1 p-4">
                            <div class="flex items-center justify-between">
                                <button type="button" @click="geser(-1)" class="flex h-8 w-8 items-center justify-center rounded-full border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Bulan sebelumnya"><x-tamu.ikon nama="kiri" ukuran="16" /></button>
                                <span class="font-display text-sm font-bold capitalize" x-text="judulBulan"></span>
                                <button type="button" @click="geser(1)" :disabled="!bisaMaju" class="flex h-8 w-8 items-center justify-center rounded-full border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200 disabled:opacity-30" aria-label="Bulan berikutnya"><x-tamu.ikon nama="kanan" ukuran="16" /></button>
                            </div>
                            <div class="mt-3 grid grid-cols-7 text-center text-[11px] font-semibold uppercase tracking-[.06em] text-ink-500">
                                <template x-for="h in ['Sen','Sel','Rab','Kam','Jum','Sab','Min']" :key="h"><span class="py-1" x-text="h"></span></template>
                            </div>
                            <div class="grid grid-cols-7" @mouseleave="arah = null">
                                <template x-for="hr in hariBulan" :key="hr.kunci">
                                    <div class="relative flex h-9 items-center justify-center">
                                        <span x-show="hr.pita" class="absolute inset-y-1 bg-[rgba(181,113,74,.12)]" :class="hr.pita"></span>
                                        <button type="button" x-show="hr.iso" @click="pilihTanggal(hr.iso)" @mouseenter="arah = hr.iso" :disabled="hr.nanti"
                                                class="relative z-[1] flex h-8 w-8 items-center justify-center rounded-full border-0 text-[13px] tabular-nums transition disabled:cursor-not-allowed disabled:opacity-30"
                                                :class="hr.ujung ? 'bg-clay-500 font-bold text-cream-50' : (hr.hariIni ? 'bg-transparent font-bold text-clay-600 ring-1 ring-clay-500' : 'bg-transparent text-ink-900 hover:bg-cream-200')"
                                                x-text="hr.tgl"></button>
                                    </div>
                                </template>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-3 border-t border-cream-300 pt-3">
                                <span class="text-xs text-ink-500" x-text="petunjukPeriode"></span>
                                <div class="flex flex-none gap-2">
                                    <button type="button" @click="resetPeriode()" class="h-8 rounded border border-cream-300 bg-transparent px-3 text-xs font-semibold text-brown-800 hover:bg-cream-200">Reset</button>
                                    <button type="button" @click="terapkanRentang()" :disabled="!calonDari || !calonSampai"
                                            class="h-8 rounded border-0 bg-clay-500 px-3.5 text-xs font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-40">Terapkan</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Status: menu sendiri agar serasi dengan panel periode --}}
                <div class="relative" @click.outside="bukaStatus = false" @keydown.escape.window="bukaStatus = false">
                    <button type="button" @click="bukaStatus = !bukaStatus; bukaPeriode = false" :aria-expanded="bukaStatus" aria-haspopup="listbox"
                            class="inline-flex h-9 items-center gap-2 whitespace-nowrap rounded-full border bg-cream-50 px-3.5 text-[13px] font-semibold transition hover:bg-white"
                            :class="saring !== 'semua' ? 'border-clay-500 text-clay-600' : 'border-cream-300 text-ink-900'">
                        <span class="h-2 w-2 flex-none rounded-full" :class="warnaStatus[saring]"></span>
                        <span x-text="saring === 'semua' ? 'Semua status' : namaKeadaan[saring]">Semua status</span>
                        <span class="rounded-full bg-cream-200 px-1.5 text-[11px] tabular-nums text-ink-500" x-text="hitung(jenis, saring)"></span>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" class="opacity-60 transition" :class="bukaStatus && 'rotate-180'"><path d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div x-show="bukaStatus" x-cloak x-transition.opacity.duration.150ms role="listbox" aria-label="Status pesanan"
                         class="absolute right-0 top-[calc(100%+8px)] z-30 w-[250px] overflow-hidden rounded-xl border border-cream-300 bg-cream-50 p-1.5 shadow-wt-lg">
                        <div class="px-2.5 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Status pesanan</div>
                        <template x-for="(nama, kunci) in namaKeadaan" :key="kunci">
                            <button type="button" role="option" :aria-selected="saring === kunci" @click="saring = kunci; bukaStatus = false"
                                    class="flex w-full items-center gap-3 rounded-lg border-0 px-2.5 py-2 text-left text-[13px] transition"
                                    :class="saring === kunci ? 'bg-cream-200 font-semibold text-ink-900' : 'bg-transparent text-ink-900 hover:bg-cream-100'">
                                <span class="h-2.5 w-2.5 flex-none rounded-full" :class="warnaStatus[kunci]"></span>
                                <span class="min-w-0 flex-1" x-text="kunci === 'semua' ? 'Semua status' : nama"></span>
                                <span class="min-w-6 rounded-full px-1.5 text-center text-[11px] font-semibold tabular-nums"
                                      :class="hitung(jenis, kunci) ? 'bg-cream-50 text-ink-900 ring-1 ring-cream-300' : 'text-ink-500'" x-text="hitung(jenis, kunci)"></span>
                                <svg class="flex-none text-clay-600" :class="saring === kunci ? 'opacity-100' : 'opacity-0'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- Saringan yang aktif --}}
        <div x-show="dari || saring !== 'semua' || jenis !== 'semua'" x-cloak class="mt-3 flex flex-wrap items-center gap-2 border-t border-cream-300 pt-3 text-xs">
            <span class="text-ink-500"><span class="font-semibold text-ink-900" x-text="jumlahTampil"></span> pesanan ditampilkan</span>
            <template x-if="jenis !== 'semua'">
                <button type="button" @click="jenis = 'semua'" class="inline-flex h-7 items-center gap-1.5 rounded-full border-0 bg-cream-200 px-3 font-semibold text-ink-900 hover:bg-cream-300"><span x-text="namaJenis[jenis]"></span><x-tamu.ikon nama="tutup" ukuran="12" /></button>
            </template>
            <template x-if="saring !== 'semua'">
                <button type="button" @click="saring = 'semua'" class="inline-flex h-7 items-center gap-1.5 rounded-full border-0 bg-cream-200 px-3 font-semibold text-ink-900 hover:bg-cream-300"><span x-text="namaKeadaan[saring]"></span><x-tamu.ikon nama="tutup" ukuran="12" /></button>
            </template>
            <template x-if="dari">
                <button type="button" @click="resetPeriode()" class="inline-flex h-7 items-center gap-1.5 rounded-full border-0 bg-cream-200 px-3 font-semibold text-ink-900 hover:bg-cream-300"><span x-text="labelPeriode"></span><x-tamu.ikon nama="tutup" ukuran="12" /></button>
            </template>
            <button type="button" @click="hapusSemua()" class="ml-auto border-0 bg-transparent p-0 font-semibold text-clay-600 hover:underline">Hapus semua</button>
        </div>
    </div>

    {{-- Daftar per bulan --}}
    <div class="mt-6">
        @foreach ($pesanan->groupBy('bulan', true) as $bulan => $isi)
            <section x-show="adaDiBulan(@js($bulan))" class="mb-8 last:mb-0">
                <h2 class="mb-3 mt-0 flex items-center gap-3 font-display text-[11px] font-bold uppercase tracking-[.16em] text-ink-500">
                    {{ $bulan }}<span class="h-px flex-1 bg-cream-300"></span>
                </h2>
                <div class="flex flex-col gap-3">
                    @foreach ($isi as $i => $p)
                        <article x-show="tampil({{ $i }})" x-transition.opacity.duration.200ms data-jenis="{{ $p['jenis'] }}" data-keadaan="{{ $p['keadaan'] }}"
                                 class="group relative flex gap-4 overflow-hidden rounded-xl border border-cream-300 bg-cream-50 p-4 shadow-wt-xs transition hover:-translate-y-0.5 hover:border-cream-400 hover:shadow-wt-md sm:p-5">
                            <span class="absolute inset-y-0 left-0 w-1 {{ $titik[$p['keadaan']] }} opacity-70" aria-hidden="true"></span>
                            <span class="hidden h-12 w-12 flex-none items-center justify-center rounded-xl bg-cream-200 text-clay-600 transition group-hover:bg-clay-500 group-hover:text-cream-50 sm:flex" aria-hidden="true">
                                <x-tamu.ikon :nama="$ikonJenis[$p['jenis']]" ukuran="20" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex min-w-0 items-center gap-2 text-xs text-ink-500">
                                        <x-tamu.ikon :nama="$ikonJenis[$p['jenis']]" ukuran="14" class="text-clay-600 sm:hidden" />
                                        <span class="font-semibold uppercase tracking-[.12em] text-clay-600">{{ PesananAkun::JENIS[$p['jenis']] }}</span>
                                        <span class="h-1 w-1 flex-none rounded-full bg-cream-400"></span>
                                        <span class="truncate tabular-nums">{{ $p['kode'] }}</span>
                                    </div>
                                    <span class="inline-flex flex-none items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $gayaKeadaan[$p['keadaan']] }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $titik[$p['keadaan']] }}"></span>{{ $p['label'] }}
                                    </span>
                                </div>
                                <h3 class="mb-0 mt-2 line-clamp-2 text-[15px] font-semibold leading-snug text-ink-900">{{ $p['judul'] }}</h3>
                                @if ($p['waktu'])
                                    <p class="mb-0 mt-1 text-[13px] text-ink-500">{{ $p['waktu'] }}</p>
                                @endif

                                <div class="mt-3.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t border-dashed border-cream-300 pt-3">
                                    <span @class(['min-w-0 text-xs', 'inline-flex items-center gap-1.5 font-semibold text-[#8A5A0B]' => $p['keadaan'] === 'belum-bayar', 'text-ink-500' => $p['keadaan'] !== 'belum-bayar'])>
                                        @if ($p['keadaan'] === 'belum-bayar')<x-tamu.ikon nama="jam" ukuran="13" />@endif{{ $p['catatan'] }}
                                    </span>
                                    <div class="ml-auto flex items-center gap-3">
                                        <span class="font-display text-base font-extrabold tabular-nums text-ink-900">{{ $rp($p['total']) }}</span>
                                        @foreach ($p['aksi'] as [$teks, $url, $utama])
                                            <a href="{{ $url }}" @class(['h-9 px-4 text-[11px] tracking-[.08em]', 'wt-btn-primary' => $utama, 'wt-btn-outline' => ! $utama])>{{ $teks }}</a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach

        {{-- Kosong untuk saringan yang dipilih --}}
        <div x-show="!jumlahTampil" @if ($pesanan->isNotEmpty()) x-cloak @endif
             class="relative flex flex-col items-center overflow-hidden rounded-2xl border border-cream-300 bg-cream-50 px-6 py-16 text-center shadow-wt-xs">
            <div class="pointer-events-none absolute -top-24 left-1/2 h-48 w-48 -translate-x-1/2 rounded-full bg-[rgba(181,113,74,.08)] blur-2xl"></div>
            <span class="relative flex h-16 w-16 items-center justify-center rounded-2xl bg-cream-200 text-clay-600 shadow-wt-xs">
                @foreach ($ikonJenis as $j => $ikon)
                    <span x-show="jenis === '{{ $j }}'" x-cloak><x-tamu.ikon :nama="$ikon" ukuran="26" /></span>
                @endforeach
                <span x-show="jenis === 'semua'"><x-tamu.ikon nama="kalender" ukuran="26" /></span>
            </span>
            <h2 class="relative mb-0 mt-5 font-display text-lg font-bold text-ink-900" x-text="judulKosong">Belum ada pesanan</h2>
            <p class="relative mb-0 mt-1.5 max-w-[420px] text-sm text-ink-500" x-text="adaSaringan ? 'Coba ubah periode atau status untuk melihat pesanan lain.' : 'Pesanan yang Anda buat saat masuk ke akun ini akan tampil di sini.'">Pesanan yang Anda buat saat masuk ke akun ini akan tampil di sini.</p>
            <div x-show="adaSaringan" x-cloak class="relative mt-5">
                <button type="button" @click="saring = 'semua'; resetPeriode()" class="wt-btn-outline h-10 px-5 text-xs tracking-[.08em]">Hapus saringan</button>
            </div>
            @foreach ($ajakan as $j => $tombol)
                <div x-show="!adaSaringan && jenis === '{{ $j }}'" @if ($j !== 'semua') x-cloak @endif class="relative mt-5 flex flex-wrap justify-center gap-2.5">
                    @foreach ($tombol as [$teks, $url, $utama])
                        <a href="{{ $url }}" @class(['h-10 px-5 text-xs tracking-[.08em]', 'wt-btn-primary' => $utama, 'wt-btn-outline' => ! $utama])>{{ $teks }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        // Tanggal Y-m-d dihitung dalam UTC agar tidak bergeser oleh zona waktu perangkat
        const tgl = (iso) => { const [y, m, d] = iso.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)); };
        const iso = (t) => t.toISOString().slice(0, 10);
        const tambahHari = (s, n) => { const t = tgl(s); t.setUTCDate(t.getUTCDate() + n); return iso(t); };
        const tambahBulan = (ym, n) => { const [y, m] = ym.split('-').map(Number); return iso(new Date(Date.UTC(y, m - 1 + n, 1))).slice(0, 7); };
        const pendek = (s, tahun = true) => tgl(s).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', timeZone: 'UTC', ...(tahun && { year: 'numeric' }) });

        Alpine.data('pesananSaya', (cfg) => ({
            data: cfg.data,
            hariIni: cfg.hariIni,
            namaKeadaan: cfg.keadaan,
            namaJenis: cfg.jenis,
            jenis: 'semua',
            saring: 'semua',
            // Periode yang berlaku dan calon rentang yang sedang dipilih di kalender
            dari: null, sampai: null, presetAktif: 'semua', labelPeriode: 'Semua waktu',
            bukaPeriode: false, bukaStatus: false, calonDari: null, calonSampai: null, arah: null, bulan: cfg.hariIni.slice(0, 7),

            get presets() {
                const h = this.hariIni;
                return [
                    { kunci: 'semua', label: 'Semua waktu', dari: null, sampai: null },
                    { kunci: 'bulan-ini', label: 'Bulan ini', dari: h.slice(0, 7) + '-01', sampai: h },
                    { kunci: '30', label: '30 hari terakhir', dari: tambahHari(h, -29), sampai: h },
                    { kunci: '90', label: '3 bulan terakhir', dari: tambahHari(h, -89), sampai: h },
                    { kunci: 'tahun-ini', label: 'Tahun ini', dari: h.slice(0, 4) + '-01-01', sampai: h },
                ];
            },
            cocok(d, j = this.jenis, k = this.saring) {
                return (j === 'semua' || d.j === j) && (k === 'semua' || d.k === k)
                    && (!this.dari || (d.t >= this.dari && d.t <= this.sampai));
            },
            hitung(j, k) { return this.data.filter((d) => this.cocok(d, j, k)).length; },
            tampil(i) { return this.cocok(this.data[i]); },
            adaDiBulan(b) { return this.data.some((d) => d.b === b && this.cocok(d)); },
            get jumlahTampil() { return this.hitung(this.jenis, this.saring); },
            get totalBelanja() { return this.data.filter((d) => d.k !== 'batal' && this.cocok(d, this.jenis, 'semua')).reduce((a, d) => a + d.n, 0); },
            get adaSaringan() { return !!this.dari || this.saring !== 'semua'; },
            get judulKosong() {
                if (this.dari && this.saring === 'semua') return 'Tidak ada pesanan pada periode ini';
                if (this.saring !== 'semua') return 'Tidak ada pesanan ' + this.namaKeadaan[this.saring].toLowerCase();
                return this.jenis === 'semua' ? 'Belum ada pesanan' : 'Belum ada pesanan ' + this.namaJenis[this.jenis].toLowerCase();
            },
            rupiah(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); },

            // Panel periode
            // Titik warna tiap status, sama dengan label di kartu
            warnaStatus: { semua: 'bg-cream-400', 'belum-bayar': 'bg-status-maintenance', diproses: 'bg-status-reserved', selesai: 'bg-status-available', batal: 'bg-status-dirty' },
            togglePeriode() {
                this.bukaStatus = false;
                this.bukaPeriode = !this.bukaPeriode;
                if (this.bukaPeriode) { this.calonDari = this.dari; this.calonSampai = this.sampai; this.bulan = (this.sampai || this.hariIni).slice(0, 7); }
            },
            pakaiPreset(pr) {
                this.dari = pr.dari; this.sampai = pr.sampai; this.presetAktif = pr.kunci;
                this.labelPeriode = pr.label; this.bukaPeriode = false;
            },
            get judulBulan() { return tgl(this.bulan + '-01').toLocaleDateString('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }); },
            get bisaMaju() { return this.bulan < this.hariIni.slice(0, 7); },
            geser(n) { if (n > 0 && !this.bisaMaju) return; this.bulan = tambahBulan(this.bulan, n); },
            get hariBulan() {
                const [y, m] = this.bulan.split('-').map(Number);
                const kosong = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7; // minggu mulai Senin
                const jumlah = new Date(Date.UTC(y, m, 0)).getUTCDate();
                const akhir = this.calonSampai || (this.calonDari && this.arah > this.calonDari ? this.arah : null);
                const hari = Array.from({ length: kosong }, (_, j) => ({ kunci: 'k' + j }));
                for (let d = 1; d <= jumlah; d++) {
                    const s = this.bulan + '-' + String(d).padStart(2, '0');
                    const pita = !this.calonDari || !akhir || akhir <= this.calonDari ? null
                        : s > this.calonDari && s < akhir ? 'inset-x-0'
                        : s === this.calonDari ? 'left-1/2 right-0'
                        : s === akhir ? 'left-0 right-1/2' : null;
                    hari.push({ kunci: s, iso: s, tgl: d, nanti: s > this.hariIni, hariIni: s === this.hariIni, ujung: s === this.calonDari || s === this.calonSampai, pita });
                }
                return hari;
            },
            pilihTanggal(s) {
                if (s > this.hariIni) return;
                // Klik pertama = awal periode; klik berikutnya (setelahnya) = akhir periode
                if (!this.calonDari || this.calonSampai || s < this.calonDari) { this.calonDari = s; this.calonSampai = null; return; }
                this.calonSampai = s;
            },
            get petunjukPeriode() {
                if (!this.calonDari) return 'Pilih tanggal awal';
                if (!this.calonSampai) return 'Pilih tanggal akhir';
                return pendek(this.calonDari, false) + ' – ' + pendek(this.calonSampai);
            },
            terapkanRentang() {
                if (!this.calonDari || !this.calonSampai) return;
                this.dari = this.calonDari; this.sampai = this.calonSampai; this.presetAktif = null;
                this.labelPeriode = this.dari === this.sampai ? pendek(this.dari) : pendek(this.dari, this.dari.slice(0, 4) !== this.sampai.slice(0, 4)) + ' – ' + pendek(this.sampai);
                this.bukaPeriode = false;
            },
            resetPeriode() { this.pakaiPreset(this.presets[0]); this.calonDari = null; this.calonSampai = null; },
            hapusSemua() { this.jenis = 'semua'; this.saring = 'semua'; this.resetPeriode(); },
        }));
    });
</script>
@endpush
</x-layouts.guest>
