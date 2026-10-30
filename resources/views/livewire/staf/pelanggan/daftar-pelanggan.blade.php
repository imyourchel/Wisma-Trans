@php
    use App\Services\Reservasi\FolioService;
    use App\Services\Pelanggan\ProfilPelanggan;

    $rp = fn ($n) => FolioService::rp($n);
    $tgl = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->translatedFormat('j M Y') : '—';
    $lencanaSegmen = fn (bool $b2b) => $b2b ? 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]' : 'bg-[rgba(181,113,74,.14)] text-clay-600';
    $galat = fn (string $k) => $errors->has($k) ? 'is-invalid' : '';
@endphp

<div>
    {{-- Penyaring --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <label class="relative block min-w-0 flex-[1_1_240px] sm:max-w-[300px]">
            <span class="sr-only">Cari pelanggan</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Nama, HP, atau email" class="wt-input pl-9">
        </label>
        <select wire:model.live="segmen" class="wt-input w-full sm:w-[150px]" aria-label="Segmen">
            <option value="">Semua segmen</option><option value="b2b">B2B (instansi)</option><option value="b2c">B2C (perorangan)</option>
        </select>
        @if ($daftarInstansi->isNotEmpty())
            <select wire:model.live="instansi" class="wt-input w-full sm:w-[190px]" aria-label="Instansi">
                <option value="">Semua instansi</option>
                @foreach ($daftarInstansi as $id => $nama)<option value="{{ $id }}">{{ $nama }}</option>@endforeach
            </select>
        @endif
        <select wire:model.live="status" class="wt-input w-full sm:w-[140px]" aria-label="Status">
            <option value="">Semua status</option><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option>
        </select>
        <label class="flex h-10 items-center gap-2 text-[13px]">
            <input type="checkbox" wire:model.live="berulang" class="h-4 w-4 accent-brown-800"> Tamu berulang (≥ 2 kunjungan)
        </label>
        @if ($cari.$segmen.$instansi.$status !== '' || $berulang)
            <button type="button" wire:click="resetSaringan" class="wt-btn-sm h-10">Hapus saringan</button>
        @endif
    </div>

    <div class="wt-card overflow-x-auto shadow-wt-xs">
        <table class="w-full min-w-[980px] border-collapse">
            <thead><tr>
                <th class="wt-th">Nama</th><th class="wt-th">HP</th><th class="wt-th">Segmen</th><th class="wt-th">Instansi</th>
                <th class="wt-th text-right">Kunjungan</th><th class="wt-th text-right">Malam</th><th class="wt-th">Kunjungan terakhir</th>
                @if ($manager)<th class="wt-th text-right">Total belanja</th>@endif
            </tr></thead>
            <tbody>
                @forelse ($daftar as $x)
                    <tr wire:key="plg-{{ $x->id }}" wire:click="open({{ $x->id }})" class="cursor-pointer hover:bg-row-hover">
                        <td class="wt-td"><span class="font-semibold">{{ $x->full_name }}</span>
                            @unless ($x->is_active)<span class="wt-lencana ml-1.5 bg-[rgba(155,139,120,.20)] text-[#6E604F]">Nonaktif</span>@endunless</td>
                        <td class="wt-td whitespace-nowrap tabular-nums text-[13px]">{{ $x->phone }}</td>
                        <td class="wt-td"><span class="wt-lencana {{ $lencanaSegmen((bool) $x->b2b) }}">{{ $x->b2b ? 'B2B' : 'B2C' }}</span></td>
                        <td class="wt-td text-[13px]">{{ $x->institution?->name ?? '—' }}</td>
                        <td class="wt-td text-right tabular-nums">{{ (int) $x->kunjungan }}</td>
                        <td class="wt-td text-right tabular-nums">{{ (int) $x->malam }}</td>
                        <td class="wt-td whitespace-nowrap text-[13px]">{{ $tgl($x->terakhir) }}</td>
                        @if ($manager)<td class="wt-td text-right tabular-nums">{{ $rp($x->belanja) }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $manager ? 8 : 7 }}" class="wt-td py-16 text-center text-sm text-ink-500">Tidak ada pelanggan yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $daftar->links('partials.halaman') }}

    {{-- Detail pelanggan --}}
    @if ($c)
        <div class="wt-modal fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="plg-judul" class="wt-dialog relative flex max-h-[94dvh] w-[860px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="plg-judul" class="flex flex-wrap items-center gap-2 font-display text-[17px] font-extrabold uppercase tracking-[.04em]">
                            {{ $c->full_name }}
                            <span class="wt-lencana {{ $lencanaSegmen($profil['segmen']['b2b']) }} font-sans text-[11px] normal-case tracking-normal">{{ $profil['segmen']['label'] }}</span>
                            @unless ($c->is_active)<span class="wt-lencana bg-[rgba(155,139,120,.20)] font-sans text-[11px] normal-case tracking-normal text-[#6E604F]">Nonaktif</span>@endunless
                        </div>
                        <div class="mt-0.5 truncate text-[13px] text-ink-500" data-segmen-rincian>{{ $profil['segmen']['rincian'] }}</div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    {{-- Profil --}}
                    <section>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <div class="wt-field-label">Kontak</div>
                            @unless ($ubah)<button type="button" wire:click="mulaiUbah" class="wt-btn-sm h-8">Ubah kontak</button>@endunless
                        </div>
                        @if ($ubah)
                            <form wire:submit="simpanUbah" class="grid gap-3 rounded border border-cream-300 p-4 sm:grid-cols-2" novalidate>
                                <x-staf.isian nama="nama" label="Nama"><input type="text" wire:model="nama" maxlength="100" class="wt-input {{ $galat('nama') }}"></x-staf.isian>
                                <x-staf.isian nama="hp" label="Nomor HP / WhatsApp"><input type="tel" wire:model="hp" maxlength="20" placeholder="08xxxxxxxxxx" class="wt-input {{ $galat('hp') }}"></x-staf.isian>
                                <x-staf.isian nama="email" label="Email"><input type="email" wire:model="email" maxlength="150" class="wt-input {{ $galat('email') }}"></x-staf.isian>
                                <x-staf.isian nama="alamat" label="Alamat"><input type="text" wire:model="alamat" maxlength="500" class="wt-input {{ $galat('alamat') }}"></x-staf.isian>
                                <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                                    <button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Simpan</button>
                                    <button type="button" wire:click="tutupUbah" class="wt-btn-sm h-10">Batal</button>
                                    <span class="text-xs text-ink-500">Perubahan hanya untuk data pelanggan; akun login tamu tidak ikut berubah.</span>
                                </div>
                            </form>
                        @else
                            <dl class="m-0 grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-3">
                                <div><dt class="text-[11px] text-ink-500">HP</dt><dd class="m-0 tabular-nums">{{ $c->phone }}</dd></div>
                                <div><dt class="text-[11px] text-ink-500">Email</dt><dd class="m-0 break-all">{{ $c->email ?: '—' }}</dd></div>
                                <div><dt class="text-[11px] text-ink-500">Akun tertaut</dt><dd class="m-0">{{ $c->user_id ? 'Ya' : 'Tidak' }}</dd></div>
                                <div><dt class="text-[11px] text-ink-500">Instansi</dt><dd class="m-0">{{ $c->institution?->name ?? '—' }}</dd></div>
                                <div class="sm:col-span-2"><dt class="text-[11px] text-ink-500">Alamat</dt><dd class="m-0">{{ $c->address ?: '—' }}</dd></div>
                            </dl>
                        @endif
                    </section>

                    {{-- Ringkasan kunjungan dan rekomendasi --}}
                    <section class="grid gap-3 sm:grid-cols-4">
                        @foreach ([
                            ['Kunjungan', $profil['kunjungan']['jumlah']],
                            ['Total malam', $profil['kunjungan']['malam']],
                            ['Rata-rata', str_replace('.', ',', (string) $profil['kunjungan']['rata']).' malam'],
                            ['Pertama · terakhir', $profil['kunjungan']['pertama'] ? $tgl($profil['kunjungan']['pertama']).' · '.$tgl($profil['kunjungan']['terakhir']) : '—'],
                        ] as [$l, $v])
                            <div class="rounded border border-cream-300 px-4 py-3"><div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">{{ $l }}</div><div class="mt-1 text-[15px] font-bold tabular-nums">{{ $v }}</div></div>
                        @endforeach
                    </section>
                    @if ($manager)
                        <div class="text-[13px]" data-belanja>
                            Total belanja pribadi: <strong class="tabular-nums">{{ $rp($profil['nilai']['belanja']) }}</strong>
                            @if ($profil['segmen']['b2b'])<span class="text-ink-500"> · {{ ProfilPelanggan::CATATAN_INSTANSI }}</span>@endif
                        </div>
                    @endif
                    @if ($profil['kunjungan']['pribadi'] >= ProfilPelanggan::MIN_KUNJUNGAN)
                        <section>
                            <div class="wt-field-label mb-2">Rekomendasi</div>
                            @if ($profil['rekomendasi'])
                                <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[13px]" data-rekomendasi>
                                    @foreach ($profil['rekomendasi'] as $saran)<li class="flex items-start gap-2"><x-staf.ikon nama="bintang" ukuran="14" class="mt-0.5 text-clay-500" />{{ $saran }}</li>@endforeach
                                </ul>
                            @else
                                <p class="m-0 text-[13px] text-ink-500">Belum ada pola yang menonjol.</p>
                            @endif
                        </section>
                    @else
                        <p class="m-0 text-[13px] text-ink-500">{{ ProfilPelanggan::labelBerikutnya($profil) }}: rekomendasi muncul setelah 2 kunjungan pribadi selesai.</p>
                    @endif
                    @if ($profil['catatan'])
                        <p class="m-0 text-[12px] text-ink-500" data-catatan-rekomendasi>{{ $profil['catatan'] }}</p>
                    @endif

                    {{-- Riwayat kunjungan --}}
                    <section>
                        <div class="wt-field-label mb-2">Riwayat kunjungan</div>
                        <div class="overflow-x-auto rounded border border-cream-300">
                            <table class="w-full min-w-[640px] border-collapse text-[13px]">
                                <thead><tr><th class="wt-th">Periode</th><th class="wt-th">Kamar</th><th class="wt-th text-right">Malam</th><th class="wt-th text-right">Total nota</th><th class="wt-th">Status</th></tr></thead>
                                <tbody>
                                    @forelse ($riwayat as $r)
                                        @php $baris = $r->rooms->where('status', '!=', 'cancelled'); @endphp
                                        <tr wire:key="rw-{{ $r->id }}">
                                            <td class="wt-td whitespace-nowrap">
                                                <a href="{{ route('staf.reservasi', ['cari' => $r->reservation_code, 'status' => '']) }}" class="font-semibold text-clay-600">{{ $r->reservation_code }}</a>
                                                <div class="text-[11px] text-ink-500">{{ $r->masuk()?->translatedFormat('j M Y') }} – {{ $r->keluar()?->translatedFormat('j M Y') }}{{ $r->institution ? ' · '.$r->institution->name : '' }}</div>
                                            </td>
                                            <td class="wt-td">{{ $baris->where('status', '!=', 'moved')->map(fn ($b) => $b->room?->room_number)->filter()->unique()->join(', ') ?: '—' }}</td>
                                            <td class="wt-td text-right tabular-nums">{{ $baris->sum(fn ($b) => $b->malam()) }}</td>
                                            <td class="wt-td text-right tabular-nums">{{ $rp($r->total_nota) }}</td>
                                            <td class="wt-td"><x-staf.status-reservasi :status="$r->status" /></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="wt-td py-6 text-center text-ink-500">Belum ada reservasi.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {{-- Layanan (kunjungan pribadi dan pesanan langsung sebagai tamu luar) --}}
                    <section class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <div class="wt-field-label mb-2">Makanan</div>
                            @forelse ($profil['layanan']['menu'] as $m)
                                <div class="flex justify-between gap-2 text-[13px]"><span>{{ $m['nama'] }}</span><span class="tabular-nums text-ink-500">{{ $m['pesanan'] }}× pesan</span></div>
                            @empty
                                <p class="m-0 text-[13px] text-ink-500">Belum pernah memesan.</p>
                            @endforelse
                        </div>
                        <div>
                            <div class="wt-field-label mb-2">Laundry</div>
                            <p class="m-0 text-[13px]">{{ $profil['layanan']['laundry_pesanan'] }} pesanan · {{ rtrim(rtrim(number_format($profil['layanan']['laundry_kg'], 1, ',', '.'), '0'), ',') ?: '0' }} kg</p>
                            @if ($profil['kunjungan']['pribadi'] > 0)
                                <p class="m-0 text-[12px] text-ink-500">Dipakai di {{ $profil['layanan']['laundry_kunjungan'] }} dari {{ $profil['kunjungan']['pribadi'] }} kunjungan pribadi</p>
                            @endif
                        </div>
                        <div>
                            <div class="wt-field-label mb-2">Aula</div>
                            @forelse ($aula as $a)
                                <div class="text-[13px]">{{ $a->hall?->name }} · {{ $a->start_at->translatedFormat('j M Y') }} <span class="text-ink-500">({{ $a->label() }})</span></div>
                            @empty
                                <p class="m-0 text-[13px] text-ink-500">Belum pernah menyewa.</p>
                            @endforelse
                        </div>
                    </section>
                </div>
            </div>
        </div>
    @endif
</div>
