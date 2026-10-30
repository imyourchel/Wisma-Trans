@php
    use App\Models\BillingStatement;
    use App\Models\Payment;

    $warna = [
        'draft' => 'bg-[rgba(155,139,120,.20)] text-[#6E604F]', 'issued' => 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]',
        'partially_paid' => 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]', 'paid' => 'bg-[rgba(92,158,103,.16)] text-status-available',
        'overdue' => 'bg-[rgba(212,81,74,.12)] text-status-dirty', 'cancelled' => 'bg-[rgba(155,139,120,.20)] text-[#6E604F]',
    ];
    $uraian = function ($n) {
        if ($n->invoice_type === 'stay') {
            $kamar = $n->reservation?->rooms->pluck('room.room_number')->filter()->unique()->implode(', ');

            return trim(($n->customer?->full_name ?? '').($kamar ? ' · kamar '.$kamar : '').($n->reservation ? ' · '.$n->reservation->reservation_code : ''));
        }

        return $n->invoice_type === 'hall' ? 'Sewa '.($n->hallBooking?->hall?->name ?? 'aula').($n->hallBooking ? ' · '.$n->hallBooking->booking_code : '') : ($n->note ?: 'Layanan');
    };
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <select wire:model.live="status" class="wt-input w-full sm:w-[170px]" aria-label="Status">
            <option value="">Semua status</option>
            @foreach (BillingStatement::STATUS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </select>
        <select wire:model.live="instansi" class="wt-input w-full sm:w-[220px]" aria-label="Instansi">
            <option value="">Semua instansi</option>
            @foreach ($instansiBulanan as $i)<option value="{{ $i->id }}">{{ $i->name }}</option>@endforeach
        </select>
        <input type="date" wire:model.live="periode" class="wt-input w-full min-w-0 flex-1 sm:w-[170px] sm:flex-none" aria-label="Tanggal dalam periode" title="Tampilkan periode yang memuat tanggal ini">
        <button type="button" wire:click="bukaSusun" class="ml-auto inline-flex h-10 items-center gap-2 rounded border-0 bg-clay-500 px-4 font-display text-[13px] font-semibold text-cream-50 hover:bg-clay-600">
            <x-staf.ikon nama="tambah" ukuran="16" />Susun sekarang
        </button>
    </div>

    @if ($formSusun)
        <form wire:submit="susun" class="wt-card mb-4 grid gap-3 p-4 sm:grid-cols-[1fr_200px_auto] sm:items-end" novalidate>
            <x-staf.isian nama="susunInstansi" label="Instansi (tagihan bulanan)">
                <select wire:model="susunInstansi" @class(['wt-input', 'is-invalid' => $errors->has('susunInstansi')])>
                    <option value="">Pilih instansi</option>
                    @foreach ($instansiBulanan as $i)<option value="{{ $i->id }}">{{ $i->name }}{{ $i->billing_day ? ' · tgl '.$i->billing_day : ' · tanggal tagih belum diatur' }}</option>@endforeach
                </select>
            </x-staf.isian>
            <x-staf.isian nama="susunTanggal" label="Tanggal dalam periode"><input type="date" wire:model="susunTanggal" @class(['wt-input', 'is-invalid' => $errors->has('susunTanggal')])></x-staf.isian>
            <div class="flex gap-2 sm:pb-0.5">
                <button class="h-10 rounded border-0 bg-brown-800 px-4 text-xs font-bold text-cream-50 hover:bg-brown-900">Susun draft</button>
                <button type="button" wire:click="$set('formSusun', false)" class="wt-btn-sm h-10">Batal</button>
            </div>
            @error('periode')<p class="m-0 text-[13px] text-status-dirty sm:col-span-3">{{ $message }}</p>@enderror
        </form>
    @endif

    <div class="wt-card overflow-x-auto shadow-wt-xs">
        <table class="w-full min-w-[920px] border-collapse">
            <thead><tr><th class="wt-th">Nomor</th><th class="wt-th">Instansi</th><th class="wt-th">Periode</th><th class="wt-th text-right">Total</th><th class="wt-th text-right">Sisa</th><th class="wt-th">Jatuh tempo</th><th class="wt-th">Status</th></tr></thead>
            <tbody>
                @forelse ($daftar as $x)
                    <tr wire:key="bs-{{ $x->id }}" wire:click="open({{ $x->id }})" class="cursor-pointer hover:bg-row-hover">
                        <td class="wt-td whitespace-nowrap font-semibold tabular-nums">{{ $x->statement_number }}</td>
                        <td class="wt-td">{{ $x->institution?->name }}</td>
                        <td class="wt-td whitespace-nowrap text-[13px]">{{ $x->rentang() }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $rp($x->total_amount) }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $x->status === 'cancelled' ? '—' : $rp($x->sisa()) }}</td>
                        <td class="wt-td whitespace-nowrap text-[13px]">{{ $x->status === 'draft' ? 'Saat diterbitkan' : $x->due_date->translatedFormat('j M Y') }}</td>
                        <td class="wt-td"><span class="wt-lencana {{ $warna[$x->status] ?? 'wt-lencana-netral' }}">{{ $x->label() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="wt-td py-16 text-center text-sm text-ink-500">Belum ada tagihan bulanan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $daftar->links('partials.halaman') }}

    @if ($s)
        <div class="wt-modal fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="bs-judul" class="wt-dialog relative flex max-h-[94dvh] w-[820px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="bs-judul" class="flex flex-wrap items-center gap-2 font-display text-[17px] font-extrabold">{{ $s->statement_number }} <span class="wt-lencana {{ $warna[$s->status] ?? '' }} font-sans text-[11px]">{{ $s->label() }}</span></div>
                        <div class="mt-0.5 truncate text-[13px] text-ink-500">{{ $s->institution->name }} · periode {{ $s->rentang() }}{{ $s->issue_date ? ' · terbit '.$s->issue_date->translatedFormat('j M Y') : '' }}{{ $s->status !== 'draft' ? ' · jatuh tempo '.$s->due_date->translatedFormat('j M Y') : '' }}</div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    <div class="overflow-x-auto rounded border border-cream-300">
                        <table class="w-full min-w-[640px] border-collapse text-[13px]">
                            <thead><tr><th class="wt-th">Nota</th><th class="wt-th">Tamu/kamar atau acara</th><th class="wt-th">Tanggal</th><th class="wt-th text-right">Jumlah</th>@if ($s->status === 'draft')<th class="wt-th"></th>@endif</tr></thead>
                            <tbody>
                                @foreach ($s->invoices as $n)
                                    <tr wire:key="bsn-{{ $n->id }}">
                                        <td class="wt-td whitespace-nowrap"><a href="{{ route('staf.keuangan.nota', ['lihat' => $n->id]) }}" class="font-semibold text-clay-600">{{ $n->invoice_number }}</a></td>
                                        <td class="wt-td">{{ $uraian($n) }}
                                            <div class="text-[11px] text-ink-500">{{ $n->items->map(fn ($i) => $i->description)->take(3)->join('; ') }}</div></td>
                                        <td class="wt-td whitespace-nowrap">{{ $n->period_start ? $n->period_start->translatedFormat('j M').' – '.$n->period_end->translatedFormat('j M') : $n->issue_date?->translatedFormat('j M Y') }}</td>
                                        <td class="wt-td text-right tabular-nums">{{ $rp($n->total_amount) }}</td>
                                        @if ($s->status === 'draft')
                                            <td class="wt-td text-right"><button type="button" wire:click="keluarkan({{ $n->id }})" class="border-0 bg-transparent p-0 text-[12px] font-semibold text-status-dirty hover:underline">Keluarkan</button></td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <dl class="m-0 grid gap-1.5 border-t border-cream-300 bg-cream-100 px-4 py-3 text-[13px]">
                            <div class="flex justify-between font-semibold"><dt>Total</dt><dd class="m-0 tabular-nums" data-total>{{ $rp($s->total_amount) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Terbayar</dt><dd class="m-0 tabular-nums">{{ $rp($s->paid_amount) }}</dd></div>
                            <div class="flex justify-between border-t border-cream-300 pt-1.5"><dt class="font-semibold">Sisa</dt><dd class="m-0 font-display text-[16px] font-extrabold tabular-nums">{{ $rp($s->sisa()) }}</dd></div>
                        </dl>
                    </div>

                    @if ($s->payments->isNotEmpty())
                        <div>
                            <div class="wt-field-label mb-1.5">Pembayaran</div>
                            <ul class="m-0 flex list-none flex-col divide-y divide-cream-300 rounded border border-cream-300 p-0 text-[13px]">
                                @foreach ($s->payments as $p)
                                    <li class="flex justify-between gap-3 px-3 py-2"><span><span class="font-semibold tabular-nums">{{ $p->payment_code }}</span> · {{ Payment::METHODS[$p->method] ?? $p->method }}<div class="text-[11px] text-ink-500">{{ $p->paid_at?->translatedFormat('j M Y H.i') }}{{ $p->receivedBy ? ' · '.$p->receivedBy->name : '' }}{{ $p->note ? ' · '.$p->note : '' }}</div></span><span class="font-semibold tabular-nums">{{ $rp($p->amount) }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($formBayar)
                        <form wire:submit="bayar" class="grid gap-3 rounded border border-cream-300 bg-cream-100 p-4 sm:grid-cols-3 sm:items-end" novalidate>
                            <x-staf.isian nama="jumlah" label="Jumlah diterima"><input type="text" wire:model="jumlah" inputmode="numeric" placeholder="{{ number_format($s->sisa(), 0, ',', '.') }}" @class(['wt-input tabular-nums', 'is-invalid' => $errors->has('jumlah')])></x-staf.isian>
                            <x-staf.isian nama="metode" label="Metode"><select wire:model="metode" class="wt-input">@foreach ($metodeBayar as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></x-staf.isian>
                            <x-staf.isian nama="referensi" label="Nomor referensi (opsional)"><input type="text" wire:model="referensi" maxlength="100" class="wt-input"></x-staf.isian>
                            <div class="flex gap-2 sm:col-span-3">
                                <button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Simpan pembayaran</button>
                                <button type="button" wire:click="$set('formBayar', false)" class="wt-btn-sm h-10">Batal</button>
                            </div>
                        </form>
                    @endif
                    @error('status')<p class="m-0 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-none flex-wrap gap-2 border-t border-cream-300 px-6 py-4">
                    @if ($s->status === 'draft')
                        <button type="button" wire:click="terbitkan" wire:loading.attr="disabled" wire:target="terbitkan" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Terbitkan dan kirim</button>
                    @elseif (in_array($s->status, ['issued', 'partially_paid', 'overdue'], true) && ! $formBayar)
                        <button type="button" wire:click="$set('formBayar', true)" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Catat pembayaran</button>
                    @endif
                    <a href="{{ $urlPdf }}" target="_blank" rel="noopener" class="wt-btn-sm h-10 hover:no-underline">Unduh PDF</a>
                    @if ($s->status !== 'cancelled' && $s->payments->isEmpty())
                        <button type="button" wire:click="batalkan" wire:confirm="Batalkan {{ $s->statement_number }}? Semua nota dilepas." class="wt-btn-sm ml-auto h-10 !text-status-dirty">Batalkan</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
