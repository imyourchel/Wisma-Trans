@php
    use App\Services\FolioService;

    $rp = fn ($n) => FolioService::rp($n);
    $tgl = fn ($t) => \Illuminate\Support\Carbon::parse($t)->translatedFormat('j M Y');
    $porsi = fn (int $n) => $ringkasan['malam'] > 0 ? str_replace('.', ',', (string) round($n / $ringkasan['malam'] * 100, 1)).'%' : '—';
@endphp

<div>
    {{-- Penyaring --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <label class="block">
            <span class="wt-field-label mb-1.5 block">Tanggal masuk</span>
            <span class="flex items-center gap-1.5">
                <input type="date" wire:model.live="dari" class="wt-input w-[150px]" aria-label="Dari">
                <span class="text-ink-500">–</span>
                <input type="date" wire:model.live="sampai" class="wt-input w-[150px]" aria-label="Sampai">
            </span>
        </label>
        <select wire:model.live="segmen" class="wt-input w-[150px]" aria-label="Segmen">
            <option value="">Semua segmen</option><option value="b2b">B2B (instansi)</option><option value="b2c">B2C (perorangan)</option>
        </select>
        @if ($daftarInstansi->isNotEmpty())
            <select wire:model.live="instansi" class="wt-input w-[190px]" aria-label="Instansi">
                <option value="">Semua instansi</option>
                @foreach ($daftarInstansi as $id => $nama)<option value="{{ $id }}">{{ $nama }}</option>@endforeach
            </select>
        @endif
        <select wire:model.live="tipe" class="wt-input w-[150px]" aria-label="Tipe kamar">
            <option value="">Semua tipe</option>
            @foreach ($daftarTipe as $id => $nama)<option value="{{ $id }}">{{ $nama }}</option>@endforeach
        </select>
        <div class="flex h-10 items-center text-[13px] text-ink-500" data-rentang>{{ $mulai->translatedFormat('j M Y') }} – {{ $akhir->translatedFormat('j M Y') }}{{ $dari.$sampai === '' ? ' (3 bulan terakhir)' : '' }}</div>
        @if ($dari.$sampai.$segmen.$instansi.$tipe !== '')
            <button type="button" wire:click="resetSaringan" class="wt-btn-sm h-10">Hapus saringan</button>
        @endif
        <button type="button" wire:click="unduhCsv" class="wt-btn-sm ml-auto h-10">Unduh CSV</button>
    </div>

    {{-- Ringkasan --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="wt-card px-5 py-4 shadow-wt-xs" data-kartu="kunjungan">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">Kunjungan</div>
            <div class="mt-1.5 font-display text-[26px] font-extrabold leading-none tabular-nums">{{ $ringkasan['kunjungan'] }}</div>
            <div class="mt-2 text-[12px] text-ink-500">{{ $ringkasan['tamu'] }} tamu · {{ $ringkasan['malam'] }} malam kamar</div>
        </div>
        <div class="wt-card px-5 py-4 shadow-wt-xs" data-kartu="baru">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">Tamu baru</div>
            <div class="mt-1.5 font-display text-[26px] font-extrabold leading-none tabular-nums">{{ $ringkasan['baru'] }}</div>
            <div class="mt-2 text-[12px] text-ink-500">Belum pernah menginap sebelumnya</div>
        </div>
        <div class="wt-card px-5 py-4 shadow-wt-xs" data-kartu="berulang">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">Tamu berulang</div>
            <div class="mt-1.5 font-display text-[26px] font-extrabold leading-none tabular-nums">{{ $ringkasan['berulang'] }}</div>
            <div class="mt-2 text-[12px] text-ink-500">Pernah menginap sebelum {{ $mulai->translatedFormat('j M Y') }}</div>
        </div>
        <div class="wt-card px-5 py-4 shadow-wt-xs" data-kartu="porsi">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">Malam kamar B2B / B2C</div>
            <div class="mt-1.5 font-display text-[22px] font-extrabold leading-none tabular-nums">{{ $porsi($ringkasan['malam_b2b']) }} / {{ $porsi($ringkasan['malam_b2c']) }}</div>
            <div class="mt-2 text-[12px] text-ink-500">{{ $ringkasan['malam_b2b'] }} malam instansi · {{ $ringkasan['malam_b2c'] }} malam perorangan</div>
        </div>
    </div>

    <div class="wt-card overflow-x-auto shadow-wt-xs">
        <table class="w-full min-w-[1080px] border-collapse">
            <thead><tr>
                <th class="wt-th">Tanggal</th><th class="wt-th">Pelanggan</th><th class="wt-th">Segmen</th><th class="wt-th">Instansi</th>
                <th class="wt-th">Kamar</th><th class="wt-th text-right">Malam</th><th class="wt-th text-right">Total nota</th><th class="wt-th">Layanan</th>
            </tr></thead>
            <tbody>
                @forelse ($daftar as $x)
                    <tr wire:key="kj-{{ $x->id }}" class="hover:bg-row-hover">
                        <td class="wt-td whitespace-nowrap text-[13px]">{{ $tgl($x->masuk) }} – {{ $tgl($x->keluar) }}<div class="text-[11px] text-ink-500">{{ $x->reservation_code }}</div></td>
                        <td class="wt-td"><a href="{{ route('staf.pelanggan', ['lihat' => $x->customer_id]) }}" class="font-semibold text-clay-600">{{ $x->full_name }}</a></td>
                        <td class="wt-td"><span @class(['wt-lencana', 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]' => $x->b2b, 'bg-[rgba(181,113,74,.14)] text-clay-600' => ! $x->b2b])>{{ $x->b2b ? 'B2B' : 'B2C' }}</span></td>
                        <td class="wt-td text-[13px]">{{ $x->instansi ?? '—' }}</td>
                        <td class="wt-td text-[13px]">{{ $x->kamar ?? '—' }}</td>
                        <td class="wt-td text-right tabular-nums">{{ (int) $x->malam }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $rp($x->total_nota) }}</td>
                        <td class="wt-td">
                            <span class="flex flex-wrap gap-1">
                                @if ($x->makanan)<span class="wt-lencana bg-[rgba(232,172,69,.18)] text-[#8A5E10]" title="Memesan makanan">Makanan</span>@endif
                                @if ($x->laundry)<span class="wt-lencana bg-[rgba(108,149,196,.16)] text-[#3F6D9E]" title="Memakai laundry">Laundry</span>@endif
                                @if ($x->aula)<span class="wt-lencana bg-[rgba(92,158,103,.16)] text-status-available" title="Menyewa aula">Aula</span>@endif
                                @if (! $x->makanan && ! $x->laundry && ! $x->aula)<span class="text-ink-500">—</span>@endif
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="wt-td py-16 text-center text-sm text-ink-500">Tidak ada kunjungan selesai pada rentang ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $daftar->links('partials.halaman') }}
</div>
