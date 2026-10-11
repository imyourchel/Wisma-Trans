{{-- QRIS Midtrans di meja (trait QrisMeja). Variabel: $qris --}}
<div wire:poll.5s="cekQris" class="flex flex-col items-center gap-3 text-center" data-qris>
    @if ($qris['qr'])
        <img src="{{ $qris['qr'] }}" alt="Kode QRIS untuk dipindai tamu" width="232" height="232" class="h-[232px] w-[232px] rounded border border-cream-300 bg-white p-2">
    @endif
    <div class="text-[13px]">Minta tamu memindai QRIS senilai <strong class="tabular-nums">{{ \App\Services\FolioService::rp($qris['jumlah']) }}</strong>. Status diperiksa otomatis.</div>
    <button type="button" wire:click="batalQris" class="wt-btn-sm">Batalkan QRIS</button>
</div>
