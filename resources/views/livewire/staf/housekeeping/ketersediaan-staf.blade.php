<div>
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <label class="block">
            <span class="wt-field-label mb-1.5 block">Tanggal</span>
            <input type="date" wire:model.live="tanggal" min="{{ today()->toDateString() }}" max="{{ $maks }}" class="wt-input h-10 w-[180px]">
        </label>
        <p class="m-0 flex-1 text-[13px] text-ink-500">Staf yang tidak diubah dianggap hadir dengan batas beban {{ $batasDefault }} poin (kosong kotor 1 poin, terisi kotor 0,5 poin).</p>
    </div>

    @foreach ($peringatan as $p)
        <div wire:key="peringatan-{{ $loop->index }}" class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.12)] px-4 py-3 text-[13px] text-[#7A520C]" role="status">
            <span class="flex-1">{{ $p }}</span>
            @if ($hariIni)
                <a href="{{ route('staf.housekeeping.pembagian') }}" class="wt-btn-sm hover:no-underline">Ke Pembagian Tugas</a>
            @endif
        </div>
    @endforeach

    <form wire:submit="simpan" novalidate>
        <div class="wt-card overflow-x-auto">
            <table class="w-full min-w-[860px] border-collapse">
                <thead>
                    <tr>
                        <th class="wt-th">Staf</th>
                        <th class="wt-th">Hadir</th>
                        <th class="wt-th">Tugas rangkap</th>
                        <th class="wt-th">Batas beban</th>
                        <th class="wt-th">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($staf as $s)
                        <tr wire:key="staf-{{ $s->id }}-{{ $tanggal }}">
                            <td class="wt-td font-semibold">{{ $s->name }}</td>
                            <td class="wt-td">
                                <label class="inline-flex cursor-pointer items-center gap-2 text-[13px]">
                                    <input type="checkbox" wire:model="baris.{{ $s->id }}.hadir" class="h-4 w-4 accent-brown-800">
                                    Hadir
                                </label>
                            </td>
                            <td class="wt-td">
                                <x-staf.isian :nama="'baris.'.$s->id.'.rangkap'" label="" class="w-[200px]">
                                    <input type="text" wire:model="baris.{{ $s->id }}.rangkap" maxlength="50" placeholder="Contoh: Bantu dapur" aria-label="Tugas rangkap {{ $s->name }}"
                                           @class(['wt-input', 'is-invalid' => $errors->has('baris.'.$s->id.'.rangkap')])>
                                </x-staf.isian>
                            </td>
                            <td class="wt-td">
                                <x-staf.isian :nama="'baris.'.$s->id.'.batas'" label="" class="w-[130px]">
                                    <input type="text" wire:model="baris.{{ $s->id }}.batas" inputmode="decimal" placeholder="{{ $batasDefault }}" aria-label="Batas beban {{ $s->name }}"
                                           @class(['wt-input tabular-nums', 'is-invalid' => $errors->has('baris.'.$s->id.'.batas')])>
                                </x-staf.isian>
                            </td>
                            <td class="wt-td">
                                <x-staf.isian :nama="'baris.'.$s->id.'.catatan'" label="" class="min-w-[200px]">
                                    <input type="text" wire:model="baris.{{ $s->id }}.catatan" maxlength="255" placeholder="Contoh: izin sakit" aria-label="Catatan {{ $s->name }}"
                                           @class(['wt-input', 'is-invalid' => $errors->has('baris.'.$s->id.'.catatan')])>
                                </x-staf.isian>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="wt-td py-10 text-center text-sm text-ink-500">Belum ada akun Housekeeping yang aktif.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <button type="submit" wire:loading.attr="disabled" wire:target="simpan" class="mt-4 h-10 rounded border-0 bg-clay-500 px-5 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">Simpan ketersediaan</button>
    </form>
</div>
