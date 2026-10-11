@php
    use App\Models\Payment;
    use App\Services\FolioService;

    $rp = fn ($n) => FolioService::rp($n);
    $warnaStatus = [
        'success' => 'bg-[rgba(92,158,103,.16)] text-status-available',
        'pending' => 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]',
        'failed' => 'bg-[rgba(212,81,74,.12)] text-status-dirty',
        'expired' => 'bg-[rgba(155,139,120,.20)] text-[#6E604F]',
    ];
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Bagian pembayaran">
            @foreach (['transaksi' => 'Transaksi & rekap kas', 'refund' => 'Perlu pengembalian dana'] as $v => $l)
                <button type="button" wire:click="$set('tab', '{{ $v }}')" role="tab" aria-selected="{{ $tab === $v ? 'true' : 'false' }}"
                        @class(['inline-flex h-8 items-center gap-2 rounded-md border-0 px-3.5 text-[13px] font-semibold transition-colors',
                            'bg-brown-800 text-cream-50' => $tab === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $tab !== $v])>
                    {{ $l }}
                    @if ($v === 'refund' && $antrean->isNotEmpty())
                        <span class="rounded-full bg-status-dirty px-1.5 text-[11px] tabular-nums text-cream-50">{{ $antrean->count() }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    @if ($tab === 'refund')
        {{-- Antrean pengembalian dana --}}
        <div class="wt-card overflow-x-auto shadow-wt-xs">
            <table class="w-full min-w-[920px] border-collapse">
                <thead><tr><th class="wt-th">Nota</th><th class="wt-th">Pelanggan</th><th class="wt-th text-right">Jumlah</th><th class="wt-th">Alasan</th><th class="wt-th">Asal</th><th class="wt-th">Umur</th><th class="wt-th"></th></tr></thead>
                <tbody>
                    @forelse ($antrean as $p)
                        @php $umur = (int) $p->created_at->startOfDay()->diffInDays(today()); @endphp
                        <tr wire:key="rf-{{ $p->id }}" @class(['bg-[rgba(232,172,69,.10)]' => $dipilih?->id === $p->id])>
                            <td class="wt-td whitespace-nowrap"><a href="{{ route('staf.keuangan.nota', ['lihat' => $p->invoice_id]) }}" class="font-semibold text-clay-600">{{ $p->invoice?->invoice_number }}</a><div class="text-[11px] tabular-nums text-ink-500">{{ $p->payment_code }}</div></td>
                            <td class="wt-td"><div class="font-semibold">{{ $p->invoice?->customer?->full_name }}</div><div class="text-[11px] tabular-nums text-ink-500">{{ $p->invoice?->customer?->phone }}</div></td>
                            <td class="wt-td text-right font-semibold tabular-nums">{{ $rp($p->amount) }}</td>
                            <td class="wt-td max-w-[280px] text-[12px]">{{ $p->note }}</td>
                            <td class="wt-td whitespace-nowrap text-[12px] tabular-nums">{{ $p->kodeAsal() ?? '—' }}</td>
                            <td @class(['wt-td whitespace-nowrap text-[12px]', 'font-semibold text-status-dirty' => $umur >= 7])>{{ $umur === 0 ? 'Hari ini' : $umur.' hari' }}</td>
                            <td class="wt-td whitespace-nowrap text-right">
                                @if ($bolehCatat)
                                    <button type="button" wire:click="mulai({{ $p->id }}, 'selesai')" class="h-8 rounded border-0 bg-brown-800 px-3 text-xs font-bold text-cream-50 hover:bg-brown-900">Sudah dikembalikan</button>
                                @endif
                                @if ($bolehHangus)
                                    <button type="button" wire:click="mulai({{ $p->id }}, 'hangus')" class="wt-btn-sm ml-1 h-8 !text-status-dirty">Hanguskan</button>
                                @endif
                            </td>
                        </tr>
                        @if ($dipilih?->id === $p->id)
                            <tr wire:key="rf-form-{{ $p->id }}">
                                <td colspan="7" class="wt-td bg-cream-100">
                                    <form wire:submit="{{ $aksi === 'hangus' ? 'hanguskan' : 'selesaikan' }}" class="flex flex-wrap items-end gap-3" novalidate>
                                        @if ($aksi === 'selesai')
                                            <x-staf.isian nama="metodeRefund" label="Dikembalikan lewat" class="w-[180px]">
                                                <select wire:model="metodeRefund" class="wt-input">@foreach ($metodeRefundPilihan as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                                            </x-staf.isian>
                                        @endif
                                        <x-staf.isian nama="catatan" :label="$aksi === 'hangus' ? 'Alasan menghanguskan' : 'Catatan (opsional)'" class="min-w-[240px] flex-1">
                                            <input type="text" wire:model="catatan" maxlength="100" placeholder="{{ $aksi === 'hangus' ? 'Contoh: tamu tidak bisa dihubungi 30 hari' : 'Contoh: transfer ke BCA 1234' }}" @class(['wt-input', 'is-invalid' => $errors->has('catatan')])>
                                        </x-staf.isian>
                                        <button @class(['h-10 rounded border-0 px-4 text-xs font-bold text-cream-50', 'bg-status-dirty' => $aksi === 'hangus', 'bg-brown-800 hover:bg-brown-900' => $aksi !== 'hangus'])>{{ $aksi === 'hangus' ? 'Hanguskan' : 'Simpan' }}</button>
                                        <button type="button" wire:click="tutup" class="wt-btn-sm h-10">Batal</button>
                                    </form>
                                    @error('refund')<p class="m-0 mt-2 text-[13px] text-status-dirty">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="7" class="wt-td py-16 text-center text-sm text-ink-500">Tidak ada dana yang perlu dikembalikan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        {{-- Penyaring --}}
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <input type="date" wire:model.live="tanggal" class="wt-input w-[170px]" aria-label="Tanggal transaksi">
            <select wire:model.live="metode" class="wt-input w-[170px]" aria-label="Metode">
                <option value="">Semua metode</option>
                @foreach (Payment::METHODS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
            <select wire:model.live="kanal" class="wt-input w-[130px]" aria-label="Kanal">
                <option value="">Semua kanal</option><option value="online">Online</option><option value="manual">Manual</option>
            </select>
            <select wire:model.live="status" class="wt-input w-[140px]" aria-label="Status">
                <option value="">Semua status</option>
                @foreach (Payment::STATUS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
            <select wire:model.live="tujuan" class="wt-input w-[150px]" aria-label="Tujuan">
                <option value="">Semua tujuan</option>
                @foreach (Payment::TUJUAN as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                <option value="refund">Pengembalian</option>
            </select>
            <a href="{{ route('staf.keuangan.pembayaran.cetak', ['tanggal' => $tanggal]) }}" target="_blank" rel="noopener" class="wt-btn-sm ml-auto h-10 hover:no-underline">Cetak rekap</a>
        </div>

        {{-- Rekap kas --}}
        <section class="wt-card mb-5 p-5 shadow-wt-xs" aria-labelledby="rekap-judul" data-rekap>
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="rekap-judul" class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Rekap kas {{ $rekap['tanggal']->translatedFormat('l, j F Y') }}</h2>
                <span class="text-[12px] text-ink-500">{{ $rekap['jumlah'] }} transaksi berhasil</span>
            </div>
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                <table class="w-full border-collapse text-[13px]">
                    <thead><tr><th class="wt-th">Metode</th><th class="wt-th text-right">Masuk</th><th class="wt-th text-right">Dikembalikan</th><th class="wt-th text-right">Bersih</th></tr></thead>
                    <tbody>
                        @forelse ($rekap['metode'] as $m => $x)
                            <tr><td class="wt-td">{{ Payment::METHODS[$m] ?? $m }}</td><td class="wt-td text-right tabular-nums">{{ $rp($x['masuk']) }}</td>
                                <td class="wt-td text-right tabular-nums">{{ $x['keluar'] > 0 ? '−'.$rp($x['keluar']) : '—' }}</td><td class="wt-td text-right font-semibold tabular-nums" data-metode="{{ $m }}">{{ $rp($x['bersih']) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="wt-td py-6 text-center text-ink-500">Belum ada transaksi berhasil.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <dl class="m-0 flex flex-col gap-2 rounded bg-cream-100 px-4 py-3 text-[13px]">
                    <div class="flex justify-between"><dt class="text-ink-500">Online</dt><dd class="m-0 tabular-nums">{{ $rp($rekap['kanal']['online']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Manual</dt><dd class="m-0 tabular-nums">{{ $rp($rekap['kanal']['manual']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Total masuk</dt><dd class="m-0 tabular-nums">{{ $rp($rekap['masuk']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Pengembalian dana</dt><dd class="m-0 tabular-nums">−{{ $rp($rekap['refund']) }}</dd></div>
                    <div class="flex justify-between border-t border-cream-300 pt-2 font-semibold"><dt>Total bersih</dt><dd class="m-0 font-display text-[17px] font-extrabold tabular-nums" data-bersih>{{ $rp($rekap['bersih']) }}</dd></div>
                </dl>
            </div>
        </section>

        <div class="wt-card overflow-x-auto shadow-wt-xs">
            <table class="w-full min-w-[1000px] border-collapse">
                <thead><tr><th class="wt-th">Kode</th><th class="wt-th">Waktu</th><th class="wt-th">Nota</th><th class="wt-th">Tujuan</th><th class="wt-th">Metode</th><th class="wt-th">Kanal</th><th class="wt-th">Status</th><th class="wt-th text-right">Jumlah</th><th class="wt-th">Penerima</th></tr></thead>
                <tbody>
                    @forelse ($transaksi as $p)
                        <tr wire:key="pay-{{ $p->id }}">
                            <td class="wt-td whitespace-nowrap font-semibold tabular-nums">{{ $p->payment_code }}</td>
                            <td class="wt-td whitespace-nowrap text-[13px] tabular-nums">{{ ($p->paid_at ?? $p->created_at)?->format('H.i') }}</td>
                            <td class="wt-td whitespace-nowrap">
                                @if ($p->invoice)
                                    <a href="{{ route('staf.keuangan.nota', ['lihat' => $p->invoice_id]) }}" class="font-semibold text-clay-600">{{ $p->invoice->invoice_number }}</a>
                                    <div class="text-[11px] text-ink-500">{{ $p->invoice->customer?->full_name }}</div>
                                @elseif ($p->billingStatement)
                                    Tagihan bulanan {{ $p->billingStatement->statement_number }}
                                @endif
                            </td>
                            <td class="wt-td text-[13px]">{{ $p->transaction_type === 'refund' ? 'Pengembalian' : (Payment::TUJUAN[$p->payment_purpose] ?? $p->payment_purpose) }}</td>
                            <td class="wt-td text-[13px]">{{ Payment::METHODS[$p->method] ?? $p->method }}</td>
                            <td class="wt-td text-[13px]">{{ $p->channel === 'online' ? 'Online' : 'Manual' }}</td>
                            <td class="wt-td"><span class="wt-lencana {{ $warnaStatus[$p->status] ?? 'wt-lencana-netral' }}">{{ $p->transaction_type === 'refund' && $p->status === 'pending' ? 'Menunggu dikembalikan' : (Payment::STATUS[$p->status] ?? $p->status) }}</span></td>
                            <td @class(['wt-td text-right font-semibold tabular-nums', 'text-status-dirty' => $p->transaction_type === 'refund'])>{{ $p->transaction_type === 'refund' ? '−' : '' }}{{ $rp($p->amount) }}</td>
                            <td class="wt-td text-[13px]">{{ $p->receivedBy?->name ?? ($p->channel === 'online' ? 'Midtrans' : '—') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="wt-td py-16 text-center text-sm text-ink-500">Tidak ada transaksi pada tanggal ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $transaksi->links('partials.halaman') }}
    @endif
</div>
