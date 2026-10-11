@php
    use App\Services\SmartCleaning\PembagianTugasService;

    $angka = fn ($n) => PembagianTugasService::angka($n);
    $gayaStatus = [
        'assigned' => 'bg-cream-200 text-ink-500',
        'in_progress' => 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]',
        'completed' => 'bg-[rgba(92,158,103,.16)] text-status-available',
        'verified' => 'bg-[rgba(92,158,103,.16)] text-status-available',
    ];
@endphp

<div>
    {{-- Tugas yang dimulai hari sebelumnya tetapi belum selesai: perlu ditindaklanjuti Manager --}}
    @if ($belumSelesaiKemarin->isNotEmpty())
        <div class="wt-card mb-4 border-l-4 border-l-status-dirty p-4">
            <h2 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Belum selesai kemarin ({{ $belumSelesaiKemarin->count() }})</h2>
            <p class="mb-0 mt-1 text-xs text-ink-500">Sudah dimulai tetapi belum ditandai selesai. Minta staf menyelesaikannya di Tugas Saya, atau sesuaikan status kamar di Papan Status.</p>
            <ul class="m-0 mt-3 flex list-none flex-col gap-1.5 p-0 text-[13px]">
                @foreach ($belumSelesaiKemarin as $t)
                    <li wire:key="kemarin-{{ $t->id }}" class="flex flex-wrap items-center gap-2 border-t border-cream-300 pt-1.5">
                        <span class="font-display font-extrabold tabular-nums">{{ $t->room->room_number }}</span>
                        <span class="text-ink-500">{{ $t->namaStaf() ?: 'Tanpa staf' }}</span>
                        <span class="text-[11px] text-ink-500">· tugas {{ $t->task_date->translatedFormat('j M') }}, dimulai {{ $t->started_at?->translatedFormat('j M H.i') ?? '—' }}</span>
                        <span class="wt-lencana {{ $gayaStatus['in_progress'] }}">{{ $t->room->status->label() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $usulan['batch'])
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">
            Hitung prioritas dulu. <a href="{{ route('staf.housekeeping.prioritas') }}" class="font-semibold">Buka Prioritas Pembersihan</a>
        </div>
    @else
        <div class="mb-4 flex flex-wrap items-center gap-3">
            @if (! $pratinjau)
                <button type="button" wire:click="buatUsulan" @disabled($menunggu->isEmpty())
                        class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-50">Buat usulan</button>
            @else
                <button type="button" wire:click="simpan" wire:loading.attr="disabled" wire:target="simpan"
                        class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">Simpan pembagian</button>
                <button type="button" wire:click="batalUsulan" class="wt-btn-sm h-10">Batal</button>
            @endif
            <button type="button" wire:click="bagiUlang" wire:confirm="Tugas yang belum dimulai dilepas lalu dibagi lagi. Lanjutkan?" class="wt-btn-sm h-10">Bagi ulang</button>
            <span class="text-[13px] text-ink-500">Prioritas dihitung {{ $usulan['batch']->generated_at->translatedFormat('H.i') }} WITA ·
                <a href="{{ route('staf.housekeeping.prioritas') }}">lihat prioritas</a></span>
        </div>

        @foreach (['pembagian', 'ke', 'status'] as $f)
            @error($f)<p class="mb-4 rounded-md border border-[rgba(212,81,74,.3)] bg-[rgba(212,81,74,.06)] px-4 py-3 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror
        @endforeach

        @if ($pratinjau)
            <p class="mb-4 rounded-md border border-cream-300 bg-cream-100 px-4 py-3 text-[13px]">
                Usulan Greedy: setiap kamar, menurut urutan prioritas, diberikan ke staf hadir dengan beban terkecil tanpa melewati batasnya. Belum disimpan.
            </p>
        @endif

        {{-- Kartu per staf --}}
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($staf as $s)
                @php
                    $usul = $pratinjau ? ($usulan['penugasan'][$s['id']] ?? []) : [];
                    $bebanTampil = $pratinjau ? ($usulan['beban'][$s['id']] ?? $s['beban']) : $s['beban'];
                    $milik = $tugasPerStaf[$s['id']] ?? collect();
                @endphp
                <div wire:key="staf-{{ $s['id'] }}" @class(['wt-card flex flex-col p-4', 'opacity-60' => ! $s['hadir']])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate font-semibold">{{ $s['nama'] }}</div>
                            <div class="text-[12px] text-ink-500">
                                {{ $s['hadir'] ? $milik->count() + count($usul).' kamar' : 'Tidak hadir' }}
                                @if ($s['rangkap']) · rangkap: {{ $s['rangkap'] }}@endif
                            </div>
                        </div>
                        <div @class(['text-right font-display text-lg font-extrabold tabular-nums', 'text-status-dirty' => $bebanTampil > $s['batas']])>
                            {{ $angka($bebanTampil) }}<span class="text-[13px] font-semibold text-ink-500"> / {{ $angka($s['batas']) }}</span>
                        </div>
                    </div>

                    <ul class="m-0 mt-3 flex list-none flex-col gap-1.5 p-0 text-[13px]">
                        @foreach ($milik as $t)
                            <li wire:key="tugas-{{ $t->id }}" class="flex flex-wrap items-center gap-2 border-t border-cream-300 pt-1.5">
                                <span class="font-display font-extrabold tabular-nums">{{ $t->room->room_number }}</span>
                                <span class="text-[11px] text-ink-500">{{ $angka($t->workload_point) }} poin @if ($t->item) · #{{ $t->item->priority_rank }}@endif</span>
                                <span class="wt-lencana {{ $gayaStatus[$t->status] ?? 'wt-lencana-netral' }}">{{ $t->label() }}</span>
                                <span class="ml-auto flex gap-1.5">
                                    @if ($t->status === 'assigned')
                                        <button type="button" wire:click="mulaiPindah({{ $t->id }})" class="wt-btn-sm h-7 px-2 text-[12px]">Pindahkan</button>
                                    @elseif ($t->status === 'completed')
                                        <button type="button" wire:click="verifikasi({{ $t->id }})" class="wt-btn-sm h-7 px-2 text-[12px]">Verifikasi</button>
                                    @endif
                                </span>
                                @if ($pindahId === $t->id)
                                    <form wire:submit="pindahkan" class="flex w-full flex-wrap items-end gap-2 pt-1" novalidate>
                                        <x-staf.isian nama="pindahKe" label="Pindahkan ke" class="min-w-[180px] flex-1">
                                            <select wire:model="pindahKe" @class(['wt-input h-9', 'is-invalid' => $errors->has('pindahKe')])>
                                                <option value="">Pilih staf</option>
                                                @foreach ($pilihanStaf as $p)
                                                    @if ($p->id !== $s['id'])<option value="{{ $p->id }}">{{ $p->name }}</option>@endif
                                                @endforeach
                                            </select>
                                        </x-staf.isian>
                                        <button type="submit" class="h-9 rounded border-0 bg-brown-800 px-3 text-[12px] font-semibold text-cream-50">Pindahkan</button>
                                        <button type="button" wire:click="batalPindah" class="wt-btn-sm h-9">Batal</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                        @foreach ($usul as $t)
                            <li wire:key="usul-{{ $s['id'] }}-{{ $t['room_id'] }}" class="flex items-center gap-2 border-t border-dashed border-cream-300 pt-1.5">
                                <span class="font-display font-extrabold tabular-nums">{{ $t['nomor'] }}</span>
                                <span class="text-[11px] text-ink-500">{{ $angka($t['poin']) }} poin · #{{ $t['peringkat'] }}</span>
                                <span class="wt-lencana bg-[rgba(181,113,74,.14)] text-clay-600">Usulan</span>
                            </li>
                        @endforeach
                        @if ($milik->isEmpty() && ! $usul)
                            <li class="border-t border-cream-300 pt-1.5 text-[12px] text-ink-500">Belum ada tugas hari ini.</li>
                        @endif
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- Kamar yang belum punya tugas --}}
        @php $belum = $pratinjau ? collect($usulan['belum']) : $menunggu; @endphp
        <div class="wt-card mt-4 p-4">
            <h2 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Belum terbagi ({{ $belum->count() }})</h2>
            @if ($belum->isEmpty())
                <p class="mb-0 mt-2 text-[13px] text-ink-500">Semua kamar kotor pada perhitungan terakhir sudah punya tugas.</p>
            @else
                @if ($pratinjau)
                    <p class="mb-0 mt-1 text-xs text-ink-500">Tidak muat di batas beban staf mana pun. Naikkan batas di Ketersediaan Staf, atau pindahkan tugas secara manual.</p>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($belum as $t)
                        <span wire:key="belum-{{ $t['room_id'] }}" class="inline-flex items-center gap-2 rounded-md border border-cream-300 bg-cream-100 px-3 py-1.5 text-[13px]">
                            <span class="font-display font-extrabold tabular-nums">{{ $t['nomor'] }}</span>
                            <span class="text-[11px] text-ink-500">#{{ $t['peringkat'] ?? '—' }} · {{ $t['status'] }} · {{ $angka($t['poin']) }} poin</span>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
