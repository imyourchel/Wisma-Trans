@php
    use App\Livewire\Staf\Pelanggan\Instansi;
    use App\Models\Institution;
    use App\Services\Reservasi\FolioService;

    $rp = fn ($n) => FolioService::rp($n);
    $galat = fn (string $k) => $errors->has($k) ? 'is-invalid' : '';
@endphp

<div>
    {{-- Penyaring dan tambah --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <label class="relative block min-w-0 flex-[1_1_240px] sm:max-w-[300px]">
            <span class="sr-only">Cari instansi</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Nama atau kode instansi" class="wt-input pl-9">
        </label>
        <label class="block">
            <span class="wt-field-label mb-1.5 block">Periode pendapatan</span>
            <select wire:model.live="periode" class="wt-input w-[180px]">
                @foreach (Instansi::PERIODE as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
        </label>
        @if ($periode === 'rentang')
            <div class="flex items-center gap-1.5">
                <input type="date" wire:model.live="dari" class="wt-input w-[150px]" aria-label="Dari">
                <span class="text-ink-500">–</span>
                <input type="date" wire:model.live="sampai" class="wt-input w-[150px]" aria-label="Sampai">
            </div>
        @endif
        <button type="button" wire:click="mulaiTambah" class="ml-auto flex h-10 items-center gap-2 rounded border-0 bg-brown-800 px-4 font-display text-xs font-bold text-cream-50 hover:bg-brown-900">
            <x-staf.ikon nama="tambah" ukuran="16" /> Tambah instansi
        </button>
    </div>

    <div class="wt-card overflow-x-auto shadow-wt-xs">
        <table class="w-full min-w-[1040px] border-collapse">
            <thead><tr>
                <th class="wt-th">Instansi</th><th class="wt-th">Skema</th><th class="wt-th">Narahubung utama</th>
                <th class="wt-th text-right">Reservasi</th><th class="wt-th text-right">Malam kamar</th>
                <th class="wt-th text-right" data-kolom-pendapatan>Pendapatan, {{ $labelPeriode }}</th>
                <th class="wt-th text-right">Piutang berjalan</th>
            </tr></thead>
            <tbody>
                @forelse ($daftar as $x)
                    @php $ins = $x['instansi']; @endphp
                    <tr wire:key="ins-{{ $ins->id }}" class="hover:bg-row-hover">
                        <td class="wt-td"><button type="button" wire:click="open({{ $ins->id }})" class="border-0 bg-transparent p-0 text-left font-semibold text-ink-900 hover:text-clay-600">{{ $ins->name }}</button>
                            @if ($ins->code)<div class="text-[11px] text-ink-500">{{ $ins->code }}</div>@endif</td>
                        <td class="wt-td text-[13px]">{{ $ins->ringkasSkema() }}</td>
                        <td class="wt-td text-[13px]">{{ $ins->primaryContact?->full_name ?? '—' }}@if ($ins->primaryContact)<div class="text-[11px] text-ink-500">{{ $ins->primaryContact->phone }}</div>@endif</td>
                        <td class="wt-td text-right tabular-nums">{{ $x['reservasi'] }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $x['malam'] }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $rp($x['pendapatan']) }}</td>
                        <td class="wt-td text-right tabular-nums">
                            @if ($x['piutang'] > 0)
                                <a href="{{ route('staf.keuangan.piutang', ['lihat' => $ins->id]) }}" class="font-semibold text-clay-600">{{ $rp($x['piutang']) }}</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="wt-td py-16 text-center text-sm text-ink-500">Belum ada instansi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-[12px] text-ink-500">Reservasi, malam kamar, dan pendapatan (pendapatan diakui, sama dengan Laporan) dihitung untuk {{ $labelPeriode }}. Piutang menunjukkan keadaan saat ini.</p>

    {{-- Formulir tambah / ubah --}}
    @if ($formulir)
        <div class="fixed inset-0 z-[350] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.tutupFormulir()">
            <div wire:click="tutupFormulir" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <form wire:submit="simpan" role="dialog" aria-modal="true" aria-labelledby="ins-form-judul" novalidate
                  class="relative flex max-h-[94vh] w-[620px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div id="ins-form-judul" class="min-w-0 flex-1 font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $formulir === 'ubah' ? 'Ubah instansi' : 'Tambah instansi' }}</div>
                    <button type="button" wire:click="tutupFormulir" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="grid min-h-0 flex-1 gap-4 overflow-y-auto px-6 py-5 sm:grid-cols-[140px_1fr]">
                    <x-staf.isian nama="kode" label="Kode (opsional)"><input type="text" wire:model="kode" maxlength="20" placeholder="PTVALE" class="wt-input uppercase {{ $galat('kode') }}"></x-staf.isian>
                    <x-staf.isian nama="nama" label="Nama instansi"><input type="text" wire:model="nama" maxlength="150" class="wt-input {{ $galat('nama') }}"></x-staf.isian>
                    <x-staf.isian nama="alamat" label="Alamat" class="sm:col-span-2"><input type="text" wire:model="alamat" maxlength="500" class="wt-input {{ $galat('alamat') }}"></x-staf.isian>
                    <x-staf.isian nama="telepon" label="Telepon"><input type="tel" wire:model="telepon" maxlength="20" class="wt-input {{ $galat('telepon') }}"></x-staf.isian>
                    <x-staf.isian nama="email" label="Email"><input type="email" wire:model="email" maxlength="150" class="wt-input {{ $galat('email') }}"></x-staf.isian>
                    <x-staf.isian nama="skema" label="Skema penagihan bawaan" class="sm:col-span-2">
                        <select wire:model.live="skema" class="wt-input {{ $galat('skema') }}">
                            @foreach (Institution::SKEMA as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </x-staf.isian>
                    @if ($skema === 'monthly_fixed_date')
                        <x-staf.isian nama="tanggalTagih" label="Tanggal tagih"><input type="number" wire:model="tanggalTagih" min="1" max="31" placeholder="25" class="wt-input {{ $galat('tanggalTagih') }}"></x-staf.isian>
                        <x-staf.isian nama="jatuhTempo" label="Jatuh tempo (hari, opsional)" bantuan="Dipakai untuk jatuh tempo tagihan bulanan."><input type="number" wire:model="jatuhTempo" min="1" max="365" placeholder="14" class="wt-input {{ $galat('jatuhTempo') }}"></x-staf.isian>
                    @elseif ($skema === 'due_after_checkout')
                        <x-staf.isian nama="jatuhTempo" label="Jatuh tempo (hari setelah check-out)" class="sm:col-span-2"><input type="number" wire:model="jatuhTempo" min="1" max="365" placeholder="14" class="wt-input w-[160px] {{ $galat('jatuhTempo') }}"></x-staf.isian>
                    @endif
                    @if ($formulir === 'ubah')
                        <p class="m-0 rounded bg-cream-200 px-3 py-2 text-[12px] text-ink-500 sm:col-span-2" data-keterangan-skema>Berlaku untuk reservasi dan tagihan berikutnya. Reservasi, nota, dan tagihan bulanan yang sudah ada tidak berubah.</p>
                    @endif
                </div>
                <div class="flex flex-none gap-2 border-t border-cream-300 px-6 py-4">
                    <button type="submit" class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600">Simpan</button>
                    <button type="button" wire:click="tutupFormulir" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Detail dan narahubung --}}
    @if ($i)
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="ins-judul" class="relative flex max-h-[94vh] w-[780px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="ins-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $i->name }}</div>
                        <div class="mt-0.5 truncate text-[13px] text-ink-500">{{ $i->code ? $i->code.' · ' : '' }}{{ $i->ringkasSkema() }}</div>
                    </div>
                    <button type="button" wire:click="mulaiUbah" class="wt-btn-sm h-8">Ubah data</button>
                    <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    <dl class="m-0 grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-3">
                        <div><dt class="text-[11px] text-ink-500">Telepon</dt><dd class="m-0">{{ $i->phone ?: '—' }}</dd></div>
                        <div><dt class="text-[11px] text-ink-500">Email</dt><dd class="m-0 break-all">{{ $i->email ?: '—' }}</dd></div>
                        <div><dt class="text-[11px] text-ink-500">Alamat</dt><dd class="m-0">{{ $i->address ?: '—' }}</dd></div>
                    </dl>

                    @include('livewire.staf.partials.kartu-pola-rombongan', ['pola' => $pola])

                    <section>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <div class="wt-field-label">Narahubung</div>
                            @unless ($tambahNarahubung)<button type="button" wire:click="mulaiNarahubung" class="wt-btn-sm h-8">Tambah narahubung</button>@endunless
                        </div>
                        @error('narahubung')<p class="mb-2 mt-0 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror

                        @if ($tambahNarahubung)
                            <form wire:submit="simpanNarahubung" class="mb-3 grid gap-3 rounded border border-cream-300 p-4 sm:grid-cols-3" novalidate>
                                <x-staf.isian nama="hpNarahubung" label="Nomor HP"><input type="tel" wire:model.blur="hpNarahubung" maxlength="20" placeholder="08xxxxxxxxxx" class="wt-input {{ $galat('hpNarahubung') }}"></x-staf.isian>
                                @if ($calon && ! $calon->trashed())
                                    <div class="text-[13px] sm:col-span-2 sm:self-end" data-calon>
                                        Pelanggan terdaftar: <strong>{{ $calon->full_name }}</strong>{{ $calon->institution ? ' · narahubung '.$calon->institution->name : '' }}
                                    </div>
                                @else
                                    <x-staf.isian nama="namaNarahubung" label="Nama"><input type="text" wire:model="namaNarahubung" maxlength="100" class="wt-input {{ $galat('namaNarahubung') }}"></x-staf.isian>
                                    <x-staf.isian nama="emailNarahubung" label="Email (opsional)"><input type="email" wire:model="emailNarahubung" maxlength="150" class="wt-input {{ $galat('emailNarahubung') }}"></x-staf.isian>
                                @endif
                                <div class="flex gap-2 sm:col-span-3">
                                    <button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">{{ $calon && ! $calon->trashed() ? 'Tautkan '.$calon->full_name : 'Tambah' }}</button>
                                    <button type="button" wire:click="tutupNarahubung" class="wt-btn-sm h-10">Batal</button>
                                </div>
                            </form>
                        @endif

                        <div class="overflow-hidden rounded border border-cream-300">
                            @forelse ($narahubung as $n)
                                @php $utama = $i->primary_contact_id === $n->id; @endphp
                                <div wire:key="nh-{{ $n->id }}" @class(['flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-cream-300 px-4 py-3 text-[13px] first:border-t-0', 'opacity-60' => ! $n->is_active])>
                                    <div class="min-w-[180px] flex-1">
                                        <div class="font-semibold">{{ $n->full_name }}
                                            @if ($utama)<span class="wt-lencana ml-1.5 bg-[rgba(92,158,103,.16)] text-status-available">Utama</span>@endif
                                            @unless ($n->is_active)<span class="wt-lencana ml-1.5 bg-[rgba(155,139,120,.20)] text-[#6E604F]">Nonaktif</span>@endunless
                                        </div>
                                        <div class="text-[12px] text-ink-500">{{ $n->phone }}{{ $n->email ? ' · '.$n->email : '' }}</div>
                                    </div>
                                    <div class="flex gap-1.5">
                                        @if ($n->is_active && ! $utama)
                                            <button type="button" wire:click="jadikanUtama({{ $n->id }})" class="wt-btn-sm h-8">Jadikan utama</button>
                                        @endif
                                        @if ($n->is_active)
                                            <button type="button" wire:click="nonaktifkan({{ $n->id }})" class="wt-btn-sm h-8">Nonaktifkan</button>
                                        @else
                                            <button type="button" wire:click="aktifkan({{ $n->id }})" class="wt-btn-sm h-8">Aktifkan lagi</button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="m-0 px-4 py-4 text-[13px] text-ink-500">Belum ada narahubung. Tagihan dikirim ke narahubung utama.</p>
                            @endforelse
                        </div>
                        <p class="mb-0 mt-2 text-[12px] text-ink-500">Narahubung yang sudah tidak menjabat dinonaktifkan, bukan dihapus; riwayat reservasi dan notanya tetap utuh.</p>
                    </section>
                </div>
            </div>
        </div>
    @endif
</div>
