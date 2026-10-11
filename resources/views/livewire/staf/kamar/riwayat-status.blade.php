@php
    use App\Enums\RoomStatus;
    use App\Livewire\Staf\Kamar\RiwayatStatus;
    use Illuminate\Support\Carbon;

    $lencana = fn (?RoomStatus $s) => $s
        ? '<span class="wt-lencana" style="color: '.$s->color().'; background: color-mix(in srgb, '.$s->color().' 14%, transparent)">'.e($s->label()).'</span>'
        : '<span class="text-ink-500">—</span>';
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <label class="relative block min-w-0 flex-[1_1_220px] sm:max-w-[260px]">
            <span class="sr-only">Cari di catatan</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Cari catatan: RSV-0012, MNT-0006, Jalur cepat" class="wt-input pl-9">
        </label>
        <select wire:model.live="kamar" class="wt-input w-[130px]" aria-label="Kamar">
            <option value="">Semua kamar</option>
            @foreach ($kamarPilihan as $id => $no)<option value="{{ $id }}">Kamar {{ $no }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="wt-input w-[190px]" aria-label="Status baru">
            <option value="">Semua status</option>
            @foreach (RoomStatus::cases() as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
        </select>
        <select wire:model.live="staf" class="wt-input w-[170px]" aria-label="Diubah oleh">
            <option value="">Semua staf</option>
            <option value="sistem">Sistem</option>
            @foreach ($stafPilihan as $id => $nama)<option value="{{ $id }}">{{ $nama }}</option>@endforeach
        </select>
        <div class="flex items-center gap-1.5">
            <input type="date" wire:model.live="dari" max="{{ today()->toDateString() }}" class="wt-input w-[150px]" aria-label="Dari tanggal">
            <span class="text-ink-500">–</span>
            <input type="date" wire:model.live="sampai" max="{{ today()->toDateString() }}" class="wt-input w-[150px]" aria-label="Sampai tanggal">
        </div>
        <button type="button" wire:click="resetSaringan" class="wt-btn-sm h-10">Atur ulang</button>
    </div>

    <div class="wt-card overflow-x-auto shadow-wt-xs">
        <table class="w-full min-w-[1040px] border-collapse">
            <thead><tr>
                <th class="wt-th">Waktu</th><th class="wt-th">Kamar</th><th class="wt-th">Status baru</th><th class="wt-th">Status sebelumnya</th>
                <th class="wt-th">Lama di status sebelumnya</th><th class="wt-th">Diubah oleh</th><th class="wt-th">Catatan</th>
            </tr></thead>
            <tbody>
                @forelse ($logs as $l)
                    @php
                        $lalu = $sebelum[$l->id] ?? null;
                        $statusLalu = $lalu?->status_lalu ? RoomStatus::tryFrom($lalu->status_lalu) : null;
                        $lama = $lalu?->waktu_lalu ? RiwayatStatus::lama((int) Carbon::parse($lalu->waktu_lalu)->diffInSeconds($l->changed_at)) : '—';
                    @endphp
                    <tr wire:key="log-{{ $l->id }}" data-log="{{ $l->id }}">
                        <td class="wt-td whitespace-nowrap text-[13px] tabular-nums">{{ $l->changed_at->translatedFormat('j M Y, H.i') }}</td>
                        <td class="wt-td"><button type="button" wire:click="lini({{ $l->room_id }})" class="border-0 bg-transparent p-0 font-display text-[15px] font-extrabold tabular-nums text-clay-600 hover:underline" title="Lini masa kamar {{ $l->room?->room_number }}">{{ $l->room?->room_number }}</button></td>
                        <td class="wt-td">{!! $lencana($l->status) !!}</td>
                        <td class="wt-td" data-sebelum>{!! $lencana($statusLalu) !!}</td>
                        <td class="wt-td whitespace-nowrap text-[13px]" data-lama>{{ $lama }}</td>
                        <td class="wt-td text-[13px]">{{ $l->changedBy?->name ?? 'Sistem' }}</td>
                        <td class="wt-td max-w-[320px] text-[13px] text-ink-500">{{ $l->note ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="wt-td py-16 text-center text-sm text-ink-500">Tidak ada perubahan status pada {{ $rentangTeks }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $logs->links('partials.halaman') }}

    @if ($lini)
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.tutupLini()">
            <div wire:click="tutupLini" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="lini-judul" class="relative flex max-h-[92vh] w-[640px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="lini-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">Lini masa kamar {{ $lini['room']->room_number }}</div>
                        <div class="mt-0.5 text-[13px] text-ink-500">{{ $rentangTeks }} · sekarang {{ $lini['room']->status->label() }}</div>
                    </div>
                    <button type="button" wire:click="tutupLini" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    <section>
                        <div class="wt-field-label mb-2">Total waktu per status dalam rentang</div>
                        @if ($lini['total'] === [])
                            <p class="m-0 text-[13px] text-ink-500">Belum ada catatan status untuk kamar ini.</p>
                        @else
                            <ul class="m-0 flex list-none flex-col gap-1.5 p-0 text-[13px]" data-total-status>
                                @foreach ($lini['total'] as $s => $detik)
                                    <li class="flex items-center justify-between gap-3"><span>{!! $lencana(RoomStatus::from($s)) !!}</span><span class="tabular-nums">{{ RiwayatStatus::lama($detik) }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                    <section>
                        <div class="wt-field-label mb-2">Urutan perubahan</div>
                        <ol class="m-0 list-none border-l-2 border-cream-300 pl-5">
                            @if ($lini['awal'])
                                <li class="relative pb-3 text-[13px] text-ink-500">
                                    <span class="absolute -left-[27px] top-1.5 h-2.5 w-2.5 rounded-full bg-cream-400"></span>
                                    Awal rentang: {!! $lencana($lini['awal']->status) !!} sejak {{ $lini['awal']->changed_at->translatedFormat('j M Y, H.i') }}
                                </li>
                            @endif
                            @forelse ($lini['log'] as $l)
                                <li class="relative pb-3 text-[13px]">
                                    <span class="absolute -left-[27px] top-1.5 h-2.5 w-2.5 rounded-full" style="background-color: {{ $l->status->color() }}"></span>
                                    <div class="flex flex-wrap items-center gap-2">{!! $lencana($l->status) !!}<span class="text-ink-500">{{ $l->changed_at->translatedFormat('j M Y, H.i') }} · {{ $l->changedBy?->name ?? 'Sistem' }}</span></div>
                                    @if ($l->note)
                                        <div class="mt-0.5 text-ink-500">
                                            {!! preg_replace_callback('/MNT-\d+/', fn ($m) => isset($lini['tautan'][$m[0]]) ? '<a href="'.e($lini['tautan'][$m[0]]).'" class="font-semibold text-clay-600">'.$m[0].'</a>' : $m[0], e($l->note)) !!}
                                        </div>
                                    @endif
                                </li>
                            @empty
                                <li class="text-[13px] text-ink-500">Tidak ada perubahan dalam rentang ini.</li>
                            @endforelse
                        </ol>
                    </section>
                </div>
            </div>
        </div>
    @endif
</div>
