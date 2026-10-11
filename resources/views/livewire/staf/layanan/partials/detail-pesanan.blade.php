{{-- Detail pesanan makanan (Papan Pesanan, Riwayat Pesanan). Variabel: $o, $waSiap, $bolehBatal, $formBatal, $aksiDapur --}}
@php
    use App\Models\Payment;
    use App\Services\PesananMakananService as PM;
    use App\Support\GayaPesanan;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    [$kunciBayar, $labelBayar] = PM::pembayaran($o);
    $lunas = $o->invoice?->payments?->where('status', 'success')->where('transaction_type', 'payment')->sortBy('paid_at')->first();
    $masukDapur = $o->payment_mode === 'charge_to_room' || $o->sudahDibayar();
    $waktu = array_filter([
        ['Dipesan', $o->order_date],
        $lunas ? ['Lunas · '.(Payment::METHODS[$lunas->method] ?? $lunas->method), $lunas->paid_at] : null,
        $masukDapur && $o->status !== 'cancelled' ? ['Masuk dapur', PM::mulaiAntre($o)] : null,
        in_array($o->status, ['in_progress', 'ready', 'delivered', 'cancelled'], true) ? ['Terakhir: '.PM::STATUS[$o->status], $o->updated_at] : null,
    ]);
@endphp
<div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.close()">
    <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="psn-judul" class="relative flex max-h-[92vh] w-[600px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
        <div class="flex flex-none items-start gap-3 border-b border-cream-300 px-6 py-4">
            <div class="min-w-0 flex-1">
                <div id="psn-judul" class="flex flex-wrap items-center gap-2.5">
                    <span class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $o->order_code }}</span>
                    <span class="wt-lencana {{ GayaPesanan::TAHAP[$o->status] ?? '' }}">{{ PM::STATUS[$o->status] ?? $o->status }}</span>
                    <span class="wt-lencana {{ GayaPesanan::BAYAR[$kunciBayar] }}">{{ $labelBayar }}</span>
                </div>
                <div class="mt-1 text-[13px] text-ink-500">{{ PM::jenis($o) }} · {{ $o->customer?->full_name }}@if ($o->customer?->phone) · {{ $o->customer->phone }}@endif</div>
            </div>
            <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
            <table class="w-full border-collapse text-[13px]">
                <thead><tr><th class="wt-th !px-0">Menu</th><th class="wt-th text-right">Harga</th><th class="wt-th !pr-0 text-right">Subtotal</th></tr></thead>
                <tbody>
                    @foreach ($o->items as $i)
                        <tr wire:key="dtl-{{ $i->id }}">
                            <td class="wt-td !px-0 align-top">
                                <span class="font-semibold tabular-nums">{{ (int) $i->quantity }}×</span> {{ $i->service?->name }}
                                @if ($i->note)<div class="mt-1 inline-block rounded bg-[rgba(232,172,69,.18)] px-2 py-0.5 text-[12px] font-semibold text-[#8A5E10]">{{ $i->note }}</div>@endif
                            </td>
                            <td class="wt-td whitespace-nowrap text-right align-top tabular-nums text-ink-500">{{ $rp($i->unit_price) }}</td>
                            <td class="wt-td whitespace-nowrap !pr-0 text-right align-top tabular-nums">{{ $rp($i->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td colspan="2" class="pt-3 font-semibold">Total</td><td class="pt-3 text-right font-display text-[16px] font-extrabold tabular-nums">{{ $rp(PM::total($o)) }}</td></tr></tfoot>
            </table>

            @if ($o->note)
                <div class="mt-4 rounded-md border border-cream-300 bg-cream-100 px-3.5 py-2.5 text-[13px]"><span class="wt-field-label">Catatan pesanan</span><div class="mt-1 whitespace-pre-line">{{ $o->note }}</div></div>
            @endif

            <div class="mt-5">
                <div class="wt-field-label">Riwayat waktu</div>
                <ol class="m-0 mt-2 list-none border-l-2 border-cream-300 p-0 pl-4 text-[13px]">
                    @foreach ($waktu as [$label, $kapan])
                        <li class="relative pb-2 last:pb-0">
                            <span class="absolute -left-[21px] top-1.5 h-2 w-2 rounded-full bg-clay-500"></span>
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="text-ink-500">· {{ $kapan?->translatedFormat('j M Y, H.i') }} WITA</span>
                        </li>
                    @endforeach
                </ol>
            </div>

            @if ($bernota ?? null)
                <p class="m-0 mt-4 rounded bg-cream-200 px-3 py-2 text-[13px] text-ink-500" data-bernota>{{ $bernota }}</p>
            @endif
            @error('status')<p class="m-0 mt-4 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror

            @if ($formBatal)
                <form wire:submit="batalkan" class="mt-4 flex flex-col gap-3 rounded border border-cream-300 bg-cream-100 p-4" novalidate>
                    <x-staf.isian nama="alasan" label="Alasan pembatalan">
                        <input type="text" wire:model="alasan" maxlength="150" placeholder="Contoh: tamu membatalkan" @class(['wt-input', 'is-invalid' => $errors->has('alasan')]) autofocus>
                    </x-staf.isian>
                    <div class="flex gap-2">
                        <button type="submit" class="h-10 rounded border-0 bg-status-dirty px-4 font-display text-xs font-bold text-cream-50">Batalkan pesanan</button>
                        <button type="button" wire:click="$set('formBatal', false)" class="wt-btn-sm h-10">Tidak</button>
                    </div>
                </form>
            @endif
        </div>

        @if (($aksiDapur ?? false) || $waSiap || ($bolehBatal && ! $formBatal) || $o->access_token)
            <div class="flex flex-none flex-wrap items-center gap-2 border-t border-cream-300 px-6 py-4">
                @if ($aksiDapur ?? false)
                    @if ($o->status === 'pending' && $masukDapur)
                        <button type="button" wire:click="mulai({{ $o->id }})" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Mulai masak</button>
                    @elseif ($o->status === 'in_progress')
                        <button type="button" wire:click="siap({{ $o->id }})" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Siap</button>
                    @elseif ($o->status === 'ready')
                        <button type="button" wire:click="serahkan({{ $o->id }})" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Serahkan</button>
                    @endif
                @endif
                @if ($waSiap)
                    <a href="{{ $waSiap }}" target="_blank" rel="noopener" class="wt-btn-sm h-10 hover:no-underline">Kirim ulang WhatsApp</a>
                @endif
                @if ($o->access_token)
                    <a href="{{ $o->urlStatus() }}" target="_blank" rel="noopener" class="wt-btn-sm h-10 hover:no-underline">Halaman tamu</a>
                @endif
                @if ($bolehBatal && ! $formBatal)
                    <button type="button" wire:click="bukaBatal" class="wt-btn-sm ml-auto h-10 !text-status-dirty">Batalkan</button>
                @endif
            </div>
        @endif
    </div>
</div>
