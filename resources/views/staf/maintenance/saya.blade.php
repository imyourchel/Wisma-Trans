<x-layouts.staf title="Laporan Saya">
<div>
    @if (session('peringatan'))
        <div class="mb-4 flex items-start gap-3 rounded-md border border-[rgba(232,172,69,.5)] bg-[rgba(232,172,69,.12)] px-4 py-3 text-[13px] text-[#6B470A]" role="alert">
            <x-tamu.ikon nama="info" ukuran="18" class="mt-px" />
            <span>{{ session('peringatan') }}</span>
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <p class="m-0 flex-1 text-[13px] text-ink-500">Laporan kerusakan yang Anda kirim dan perkembangannya.</p>
        <a href="{{ route('staf.maintenance.lapor') }}" class="inline-flex h-10 items-center gap-2 rounded bg-clay-500 px-4 font-display text-[13px] font-semibold text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">
            <x-staf.ikon nama="tambah" ukuran="16" />Lapor kerusakan
        </a>
    </div>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Lokasi</th>
                    <th class="wt-th">Kerusakan</th>
                    <th class="wt-th">Prioritas</th>
                    <th class="wt-th">Dilaporkan</th>
                    <th class="wt-th">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($laporan as $l)
                    <tr class="hover:bg-row-hover">
                        <td class="wt-td whitespace-nowrap font-semibold tabular-nums">{{ $l->request_code }}</td>
                        <td class="wt-td whitespace-nowrap">{{ $l->lokasi() }}</td>
                        <td class="wt-td max-w-[320px]">
                            <div class="line-clamp-2">{{ $l->damage_description }}</div>
                            @if ($l->status === 'in_progress' && $l->pengerja())<div class="mt-0.5 text-[11px] text-ink-500">Dikerjakan: {{ $l->pengerja() }}</div>@endif
                            @if (in_array($l->status, ['completed', 'cancelled'], true) && $l->resolution_note)<div class="mt-0.5 text-[11px] text-ink-500">{{ $l->status === 'completed' ? 'Perbaikan' : 'Alasan batal' }}: {{ $l->resolution_note }}</div>@endif
                        </td>
                        <td class="wt-td"><x-staf.lencana-mnt :prioritas="$l->priority" /></td>
                        <td class="wt-td whitespace-nowrap text-ink-500">{{ $l->reported_at->translatedFormat('d M Y, H.i') }}</td>
                        <td class="wt-td"><x-staf.lencana-mnt :status="$l->status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="wt-td py-10 text-center text-sm text-ink-500">Anda belum pernah melapor kerusakan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $laporan->links('partials.halaman') }}
</div>
</x-layouts.staf>
