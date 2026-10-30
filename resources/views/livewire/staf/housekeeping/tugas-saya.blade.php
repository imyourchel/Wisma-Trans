@php
    use App\Services\SmartCleaning\PembagianTugasService;

    $angka = fn ($n) => PembagianTugasService::angka($n);
@endphp

<div class="mx-auto max-w-[640px]">
    <div class="mb-4 rounded-md bg-cream-200 px-4 py-3 text-[14px] font-semibold">
        {{ $selesai }} dari {{ $hariIni->count() }} kamar selesai · beban {{ $angka($beban) }} dari {{ $angka($batas) }}
    </div>

    @error('status')
        <p class="mb-4 rounded-md border border-[rgba(212,81,74,.3)] bg-[rgba(212,81,74,.06)] px-4 py-3 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>
    @enderror

    @forelse ($tugas as $t)
        @php
            $room = $t->room;
            $ket = $keterangan[$room->id] ?? null;
            $beres = in_array($t->status, ['completed', 'verified'], true);
        @endphp
        <div wire:key="tugas-{{ $t->id }}" @class(['wt-card mb-3 p-4', 'opacity-60' => $beres, 'ring-2 ring-[#5B7FA6]' => $t->status === 'in_progress'])>
            {{-- HP: tombol status selebar kartu di bawah info kamar, mudah ditekan dengan satu tangan --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2">
                        <span class="font-display text-[34px] font-extrabold leading-none tabular-nums">{{ $room->room_number }}</span>
                        <span class="text-[13px] text-ink-500">{{ $room->roomType?->name }}</span>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[12px]">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" style="background-color: {{ $room->status->color() }}"></span>{{ $room->status->label() }}</span>
                        <span class="text-ink-500">· {{ $angka($t->workload_point) }} poin</span>
                        @if ($t->item)<span class="text-ink-500">· prioritas #{{ $t->item->priority_rank }}</span>@endif
                    </div>
                    @unless ($t->task_date->isToday())
                        <div class="mt-2 text-[13px] font-semibold text-status-dirty">Belum selesai sejak {{ $t->task_date->translatedFormat('j M') }}. Selesaikan dulu.</div>
                    @endunless
                    @if ($ket && ! $beres)
                        <div class="mt-2 text-[13px]">{{ $ket['C1'] }}</div>
                        <div class="text-[12px] text-ink-500">Kotor {{ $ket['C3'] }}</div>
                    @elseif ($beres)
                        <div class="mt-2 text-[12px] text-status-available">{{ $t->label() }} {{ $t->completed_at?->format('H.i') }}</div>
                    @endif
                </div>
                <div class="sm:flex-none">
                    @if ($t->status === 'assigned')
                        <button type="button" wire:click="mulai({{ $t->id }})" wire:loading.attr="disabled" wire:target="mulai({{ $t->id }})"
                                class="h-14 w-full min-w-[96px] rounded border-0 bg-clay-500 px-4 font-display text-[15px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70 sm:h-12 sm:w-auto sm:text-[14px]">Mulai</button>
                    @elseif ($t->status === 'in_progress' && $selesaiId !== $t->id)
                        <button type="button" wire:click="bukaSelesai({{ $t->id }})"
                                class="h-14 w-full min-w-[96px] rounded border-0 bg-status-available px-4 font-display text-[15px] font-bold text-cream-50 sm:h-12 sm:w-auto sm:text-[14px]">Selesai</button>
                    @endif
                </div>
            </div>

            @if ($selesaiId === $t->id)
                <form wire:submit="selesai" novalidate class="mt-3 flex flex-col gap-3 border-t border-cream-300 pt-3">
                    <x-staf.isian nama="catatan" label="Catatan (opsional)">
                        <input type="text" wire:model="catatan" maxlength="255" placeholder="Contoh: handuk diganti, sabun habis" @class(['wt-input h-11', 'is-invalid' => $errors->has('catatan')])>
                    </x-staf.isian>
                    <div class="flex gap-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="selesai" class="h-14 flex-1 rounded border-0 bg-status-available font-display text-[15px] font-bold text-cream-50 disabled:opacity-70 sm:h-12 sm:text-[14px]">Tandai selesai</button>
                        <button type="button" wire:click="batalSelesai" class="h-14 rounded border sm:h-12 border-cream-300 bg-transparent px-5 text-[13px] text-brown-800">Batal</button>
                    </div>
                </form>
            @endif
        </div>
    @empty
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">Belum ada tugas untuk Anda hari ini.</div>
    @endforelse
</div>
