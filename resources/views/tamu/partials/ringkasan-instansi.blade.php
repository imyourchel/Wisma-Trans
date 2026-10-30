{{-- Ringkasan pengajuan instansi; ikut berubah saat formulir diisi (state Alpine pengajuanInstansi di tamu/instansi) --}}
<div class="rounded-lg border border-cream-300 bg-cream-50 p-5" data-ringkasan-instansi>
    <div class="font-display text-[13px] font-bold uppercase tracking-[.08em]">Ringkasan pengajuan</div>
    <dl class="m-0 mt-3 grid gap-2.5 text-sm">
        <div class="flex items-start justify-between gap-4">
            <dt class="flex-none text-ink-500">Instansi</dt>
            <dd class="m-0 min-w-0 break-words text-right font-semibold text-ink-900" x-text="instansi.trim() || '—'">—</dd>
        </div>
        <div class="flex items-start justify-between gap-4">
            <dt class="flex-none text-ink-500">Tanggal</dt>
            <dd class="m-0 min-w-0 text-right font-semibold text-ink-900">
                <span x-text="keluar ? teksRentang : '—'">—</span>
                <span x-show="keluar" class="mt-0.5 block text-xs font-normal text-ink-500" x-text="malam + ' malam'"></span>
            </dd>
        </div>
        <div class="flex items-start justify-between gap-4">
            <dt class="flex-none text-ink-500">Jumlah kamar</dt>
            <dd class="m-0 text-right font-semibold tabular-nums text-ink-900" x-text="jumlah ? jumlah + ' kamar' : '—'">—</dd>
        </div>
        <div class="flex items-start justify-between gap-4">
            <dt class="flex-none text-ink-500">Tamu</dt>
            <dd class="m-0 text-right font-semibold tabular-nums text-ink-900" x-text="peserta ? peserta + ' orang' : '—'">—</dd>
        </div>
    </dl>
</div>
