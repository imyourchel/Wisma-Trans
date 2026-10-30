@php
    use App\Models\BillingReminder;
    use App\Services\Reservasi\FolioService;
    use App\Services\Keuangan\PengingatPenagihan;

    $rp = fn ($n) => FolioService::rp($n);
    $warna = [
        'pending' => 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]', 'verified' => 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]',
        'sent' => 'bg-[rgba(92,158,103,.16)] text-status-available', 'failed' => 'bg-[rgba(212,81,74,.12)] text-status-dirty',
        'cancelled' => 'bg-[rgba(155,139,120,.20)] text-[#6E604F]',
    ];
@endphp

<div>
    <div class="mb-4 wt-geser inline-flex max-w-full rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Pengingat penagihan">
        @foreach (['periksa' => 'Perlu diperiksa', 'tertunda' => 'Ditunda', 'riwayat' => 'Riwayat'] as $v => $l)
            <button type="button" wire:click="$set('tab', '{{ $v }}')" role="tab" aria-selected="{{ $tab === $v ? 'true' : 'false' }}"
                    @class(['inline-flex h-8 items-center gap-2 rounded-md border-0 px-3.5 text-[13px] font-semibold', 'bg-brown-800 text-cream-50' => $tab === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $tab !== $v])>
                {{ $l }}
                @if ($v === 'periksa' && $periksa->isNotEmpty())<span class="rounded-full bg-status-dirty px-1.5 text-[11px] text-cream-50">{{ $periksa->count() }}</span>@endif
                @if ($v === 'tertunda' && $tertunda->isNotEmpty())<span class="rounded-full bg-cream-200 px-1.5 text-[11px] text-ink-500">{{ $tertunda->count() }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($tab === 'riwayat')
        <div class="wt-card overflow-x-auto shadow-wt-xs">
            <table class="w-full min-w-[900px] border-collapse">
                <thead><tr><th class="wt-th">Tagihan</th><th class="wt-th">Tahap</th><th class="wt-th">Kanal</th><th class="wt-th">Penerima</th><th class="wt-th">Jadwal</th><th class="wt-th">Status</th><th class="wt-th">Petugas</th></tr></thead>
                <tbody>
                    @forelse ($riwayat as $x)
                        @php $tg = $x->tagihan(); @endphp
                        <tr wire:key="rw-{{ $x->id }}">
                            <td class="wt-td"><div class="font-semibold tabular-nums">{{ $tg ? PengingatPenagihan::nomor($tg) : '—' }}</div><div class="text-[11px] text-ink-500">{{ $tg?->institution?->name }}</div></td>
                            <td class="wt-td text-[13px]">{{ $x->reminder_stage === 'internal' ? 'Internal (Manager)' : 'Ke instansi' }}</td>
                            <td class="wt-td text-[13px]">{{ BillingReminder::KANAL[$x->channel] ?? $x->channel }}</td>
                            <td class="wt-td max-w-[220px] truncate text-[13px]">{{ $x->recipient }}</td>
                            <td class="wt-td whitespace-nowrap text-[13px]">{{ $x->scheduled_date->translatedFormat('j M Y') }}{{ $x->sent_at ? ' · terkirim '.$x->sent_at->format('H.i') : '' }}</td>
                            <td class="wt-td"><span class="wt-lencana {{ $warna[$x->status] ?? '' }}">{{ BillingReminder::STATUS[$x->status] ?? $x->status }}</span></td>
                            <td class="wt-td text-[13px]">{{ $x->verifiedBy?->name ?? ($x->reminder_stage === 'internal' ? 'Sistem' : '—') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="wt-td py-16 text-center text-sm text-ink-500">Belum ada pengingat.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $riwayat->links('partials.halaman') }}
    @else
        @php $isi = $tab === 'tertunda' ? $tertunda : $periksa; @endphp
        <div class="wt-card overflow-x-auto shadow-wt-xs">
            <table class="w-full min-w-[860px] border-collapse">
                <thead><tr><th class="wt-th">Tagihan</th><th class="wt-th">Instansi</th><th class="wt-th text-right">Sisa</th><th class="wt-th">Jatuh tempo</th><th class="wt-th">Penerima</th><th class="wt-th">{{ $tab === 'tertunda' ? 'Ditunda sampai' : 'Dijadwalkan' }}</th><th class="wt-th"></th></tr></thead>
                <tbody>
                    @forelse ($isi as $x)
                        @php $tg = $x->tagihan(); $lewat = $tg->due_date->lt(today()); @endphp
                        <tr wire:key="pg-{{ $x->id }}" @class(['bg-[rgba(232,172,69,.10)]' => $p?->id === $x->id])>
                            <td class="wt-td font-semibold tabular-nums">{{ PengingatPenagihan::nomor($tg) }}</td>
                            <td class="wt-td">{{ $tg->institution->name }}</td>
                            <td class="wt-td text-right font-semibold tabular-nums">{{ $rp(PengingatPenagihan::sisa($tg)) }}</td>
                            <td @class(['wt-td whitespace-nowrap', 'font-semibold text-status-dirty' => $lewat])>{{ $tg->due_date->translatedFormat('j M Y') }}{{ $lewat ? ' · lewat' : '' }}</td>
                            <td class="wt-td text-[13px]">{{ BillingReminder::KANAL[$x->channel] }} · {{ $x->recipient ?: '—' }}</td>
                            <td class="wt-td whitespace-nowrap text-[13px]">{{ $x->scheduled_date->translatedFormat('j M Y') }}</td>
                            <td class="wt-td whitespace-nowrap text-right">
                                <button type="button" wire:click="open({{ $x->id }})" class="h-8 rounded border-0 bg-brown-800 px-3 text-xs font-bold text-cream-50 hover:bg-brown-900">Periksa &amp; kirim</button>
                                <button type="button" wire:click="batalkan({{ $x->id }})" class="wt-btn-sm ml-1 h-8 !text-status-dirty">Batalkan</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="wt-td py-16 text-center text-sm text-ink-500">{{ $tab === 'tertunda' ? 'Tidak ada pengingat yang ditunda.' : 'Tidak ada pengingat yang perlu diperiksa.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($p && $t)
        <div class="wt-modal fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="pg-judul" class="wt-dialog relative flex max-h-[94dvh] w-[640px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="pg-judul" class="font-display text-[17px] font-extrabold">Pengingat {{ PengingatPenagihan::nomor($t) }}</div>
                        <div class="mt-0.5 truncate text-[13px] text-ink-500">{{ $t->institution->name }} · sisa {{ $rp(PengingatPenagihan::sisa($t)) }} · jatuh tempo {{ $t->due_date->translatedFormat('j M Y') }}</div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                    <div>
                        <div class="wt-field-label mb-1.5">Pratinjau pesan</div>
                        <p class="m-0 whitespace-pre-line rounded border border-cream-300 bg-cream-100 px-4 py-3 text-[13px]" data-pratinjau>{{ $pratinjau }}</p>
                    </div>
                    <form wire:submit="kirim" class="flex flex-col gap-3" novalidate>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 flex items-center gap-2 text-[13px] font-semibold"><input type="checkbox" wire:model.live="kirimWa" class="h-4 w-4 accent-brown-800">WhatsApp</label>
                                <input type="tel" wire:model="hp" @disabled(! $kirimWa) placeholder="08xxxxxxxxxx" @class(['wt-input', 'is-invalid' => $errors->has('hp')])>
                                @error('hp')<p class="m-0 mt-1 text-xs text-status-dirty">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="mb-1.5 flex items-center gap-2 text-[13px] font-semibold"><input type="checkbox" wire:model.live="kirimEmail" class="h-4 w-4 accent-brown-800">Email (PDF terlampir)</label>
                                <input type="email" wire:model="email" @disabled(! $kirimEmail) placeholder="Email narahubung atau instansi" @class(['wt-input', 'is-invalid' => $errors->has('email')])>
                                @error('email')<p class="m-0 mt-1 text-xs text-status-dirty">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        @error('kanal')<p class="m-0 text-[13px] text-status-dirty">{{ $message }}</p>@enderror
                        <button class="h-10 rounded border-0 bg-clay-500 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Sudah diperiksa, kirim</button>
                    </form>

                    <form wire:submit="tunda" class="flex flex-wrap items-end gap-3 border-t border-cream-300 pt-4" novalidate>
                        <x-staf.isian nama="tanggal" label="Tunda sampai" class="w-[180px]"><input type="date" wire:model="tanggalTunda" min="{{ today()->addDay()->toDateString() }}" @class(['wt-input', 'is-invalid' => $errors->has('tanggal')])></x-staf.isian>
                        <button class="wt-btn-sm h-10">Tunda</button>
                    </form>

                    @if ($perluSurat)
                        <div class="flex flex-wrap items-center gap-2 rounded border border-[rgba(232,172,69,.45)] bg-[rgba(232,172,69,.10)] px-4 py-3 text-[13px] text-[#7A520C]" data-surat>
                            <span class="min-w-0 flex-1">Sisa di atas {{ $rp(PengingatPenagihan::ambangSurat()) }}: kirim juga surat tagihan ke kantor instansi.</span>
                            <a href="{{ $urlSurat }}" target="_blank" rel="noopener" class="wt-btn-sm h-9 hover:no-underline">Cetak surat tagihan</a>
                            <button type="button" wire:click="suratTerkirim" class="h-9 rounded border-0 bg-brown-800 px-3 text-xs font-bold text-cream-50">Tandai surat terkirim</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
