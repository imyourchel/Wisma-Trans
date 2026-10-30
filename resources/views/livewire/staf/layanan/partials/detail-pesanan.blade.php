{{-- Detail pesanan makanan (Papan Pesanan, Riwayat Pesanan). Variabel: $o, $waSiap, $bolehBatal, $formBatal, $aksiDapur --}}
@php
    use App\Models\Payment;
    use App\Models\ServiceOrder;
    use App\Services\Layanan\PesananMakananService as PM;
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
<div class="wt-modal fixed inset-0 z-[340] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.close()">
    <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="psn-judul" class="wt-dialog relative flex max-h-[92dvh] w-[600px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-xl border border-cream-300 bg-cream-50 shadow-wt-lg">
        <div class="flex flex-none items-start gap-3 border-b border-cream-300 px-6 pb-4 pt-5">
            <div class="min-w-0 flex-1">
                <div class="text-[11px] font-semibold uppercase tracking-[.14em] text-clay-600">Pesanan makanan</div>
                <div id="psn-judul" class="mt-1 flex flex-wrap items-center gap-2.5">
                    <span class="font-display text-[19px] font-extrabold uppercase tracking-[.03em] tabular-nums">{{ $o->order_code }}</span>
                    <span class="wt-lencana {{ GayaPesanan::TAHAP[$o->status] ?? '' }}">{{ PM::STATUS[$o->status] ?? $o->status }}</span>
                    <span class="wt-lencana {{ GayaPesanan::BAYAR[$kunciBayar] }}">{{ $labelBayar }}</span>
                </div>
            </div>
            <button type="button" wire:click="close" class="flex h-9 w-9 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded-full border border-cream-300 bg-transparent text-brown-800 transition hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
            {{-- Ringkasan: pemesan, penyajian (dan asal pesanan), pembayaran --}}
            <dl class="m-0 grid grid-cols-1 gap-px overflow-hidden rounded-lg border border-cream-300 bg-cream-300 text-[13px] sm:grid-cols-3" data-ringkasan-pesanan>
                <div class="bg-cream-100 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-[.1em] text-ink-500">Pemesan</dt>
                    <dd class="m-0 mt-1 truncate font-semibold text-ink-900">{{ $o->customer?->full_name ?? '—' }}</dd>
                    @if ($o->customer?->phone)<dd class="m-0 mt-0.5 tabular-nums text-ink-500">{{ $o->customer->phone }}</dd>@endif
                </div>
                <div class="bg-cream-100 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-[.1em] text-ink-500">Penyajian</dt>
                    <dd class="m-0 mt-1 font-semibold text-ink-900">{{ ServiceOrder::SERVING[$o->serving_type] ?? $o->serving_type }}</dd>
                    <dd class="m-0 mt-0.5 text-ink-500">{{ $o->reservation_room_id ? 'Kamar '.($o->reservationRoom?->room?->room_number ?? '—') : 'Tamu luar' }}</dd>
                </div>
                <div class="bg-cream-100 px-4 py-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-[.1em] text-ink-500">Pembayaran</dt>
                    <dd class="m-0 mt-1 font-semibold text-ink-900">{{ $labelBayar }}</dd>
                    @if ($lunas)<dd class="m-0 mt-0.5 text-ink-500">{{ Payment::METHODS[$lunas->method] ?? $lunas->method }}</dd>@endif
                </div>
            </dl>

            {{-- Menu --}}
            <div class="mt-5 flex items-baseline justify-between gap-3">
                <span class="wt-field-label">Menu</span>
                <span class="text-xs tabular-nums text-ink-500">{{ (int) $o->items->sum('quantity') }} porsi</span>
            </div>
            <div class="mt-2 overflow-hidden rounded-lg border border-cream-300">
                <ul class="m-0 list-none divide-y divide-cream-300 p-0">
                    @foreach ($o->items as $i)
                        <li wire:key="dtl-{{ $i->id }}" class="flex items-start gap-3 px-4 py-3">
                            <span class="flex h-8 min-w-8 flex-none items-center justify-center rounded-md bg-cream-200 px-1.5 text-[13px] font-bold tabular-nums text-brown-800">{{ (int) $i->quantity }}×</span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold leading-snug text-ink-900">{{ $i->service?->name }}</div>
                                <div class="mt-0.5 text-xs tabular-nums text-ink-500">{{ $rp($i->unit_price) }} per porsi</div>
                                @if ($i->note)<div class="mt-1.5 inline-block rounded bg-[rgba(232,172,69,.18)] px-2 py-0.5 text-[12px] font-semibold text-[#8A5E10]">{{ $i->note }}</div>@endif
                            </div>
                            <span class="whitespace-nowrap pt-0.5 text-sm font-semibold tabular-nums text-ink-900">{{ $rp($i->subtotal) }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="flex items-center justify-between gap-3 border-t border-cream-300 bg-cream-100 px-4 py-3">
                    <span class="text-[13px] font-semibold text-ink-500">Total</span>
                    <span class="font-display text-[18px] font-extrabold tabular-nums text-ink-900">{{ $rp(PM::total($o)) }}</span>
                </div>
            </div>

            @if ($o->note)
                <div class="mt-4 rounded-md border-l-[3px] border-status-maintenance bg-[rgba(232,172,69,.1)] px-3.5 py-2.5 text-[13px]">
                    <span class="text-[11px] font-semibold uppercase tracking-[.1em] text-[#8A5E10]">Catatan pesanan</span>
                    <div class="mt-1 whitespace-pre-line text-ink-900">{{ $o->note }}</div>
                </div>
            @endif

            {{-- Riwayat waktu: penanda berurutan, waktu rata kanan --}}
            <div class="mt-5">
                <div class="wt-field-label">Riwayat waktu</div>
                <ol class="m-0 mt-3 list-none p-0 text-[13px]">
                    @foreach ($waktu as [$label, $kapan])
                        <li class="relative flex gap-3 pb-3.5 last:pb-0">
                            @if (! $loop->last)<span class="absolute bottom-0 left-[5px] top-4 w-px bg-cream-300" aria-hidden="true"></span>@endif
                            <span @class(['relative mt-1 h-[11px] w-[11px] flex-none rounded-full border-2 border-clay-500',
                                'bg-clay-500' => $loop->last, 'bg-cream-50' => ! $loop->last]) aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 font-semibold text-ink-900">{{ $label }}</span>
                            <span class="whitespace-nowrap text-right tabular-nums text-ink-500">{{ $kapan?->translatedFormat('j M Y') }} · <span class="font-semibold text-ink-900">{{ $kapan?->format('H.i') }}</span> WITA</span>
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
            <div class="flex flex-none flex-wrap items-center gap-2 border-t border-cream-300 bg-cream-100 px-6 py-3.5">
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
                    <a href="{{ $o->urlStatus() }}" target="_blank" rel="noopener" class="wt-btn-sm inline-flex h-10 items-center gap-1.5 hover:no-underline">
                        Halaman tamu
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M10 14 20 4"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>
                    </a>
                @endif
                @if ($bolehBatal && ! $formBatal)
                    <button type="button" wire:click="bukaBatal" class="wt-btn-sm ml-auto h-10 !text-status-dirty">Batalkan</button>
                @endif
            </div>
        @endif
    </div>
</div>
