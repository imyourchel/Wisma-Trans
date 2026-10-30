@php
    use App\Models\Payment;
    use App\Services\Reservasi\FolioService;
    use App\Services\Keuangan\PiutangService;

    $rp = fn ($n) => FolioService::rp($n);
    $warnaUmur = ['belum' => '#5C9E67', '1-30' => '#D69A2E', '31-60' => '#C9793A', '60+' => '#D4514A'];
@endphp

<div>
    @include('livewire.staf.keuangan.partials.kartu-piutang', ['ringkasan' => $ringkasan])

    <div class="wt-card overflow-x-auto shadow-wt-xs">
        <table class="w-full min-w-[980px] border-collapse">
            <thead><tr>
                <th class="wt-th">Instansi</th><th class="wt-th text-right">Total sisa</th><th class="wt-th text-right">Tagihan</th>
                @foreach (PiutangService::UMUR as $k => $l)<th class="wt-th text-right">{{ $l }}</th>@endforeach
                <th class="wt-th">Pembayaran terakhir</th>
            </tr></thead>
            <tbody>
                @forelse ($daftar as $x)
                    <tr wire:key="pi-{{ $x['instansi']->id }}" wire:click="open({{ $x['instansi']->id }})" class="cursor-pointer hover:bg-row-hover">
                        <td class="wt-td font-semibold">{{ $x['instansi']->name }}<div class="text-[11px] font-normal text-ink-500">{{ $x['instansi']->ringkasSkema() }}</div></td>
                        <td class="wt-td text-right font-semibold tabular-nums">{{ $rp($x['sisa']) }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $x['jumlah'] }}</td>
                        @foreach (PiutangService::UMUR as $k => $l)
                            <td class="wt-td text-right tabular-nums" @if ($x['umur'][$k] > 0) style="color: {{ $warnaUmur[$k] }}" @endif>{{ $x['umur'][$k] > 0 ? $rp($x['umur'][$k]) : '—' }}</td>
                        @endforeach
                        <td class="wt-td whitespace-nowrap text-[13px]">{{ $x['terakhir'] ? $x['terakhir']->paid_at?->translatedFormat('j M Y').' · '.$rp($x['terakhir']->amount) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="wt-td py-16 text-center text-sm text-ink-500">Tidak ada piutang instansi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($i)
        <div class="wt-modal fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="pi-judul" class="wt-dialog relative flex max-h-[94dvh] w-[860px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="pi-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $i->name }}</div>
                        <div class="mt-0.5 truncate text-[13px] text-ink-500">
                            {{ $i->ringkasSkema() }} · Narahubung: {{ $i->primaryContact?->full_name ?? 'belum diatur' }}{{ $i->primaryContact?->phone ? ', '.$i->primaryContact->phone : '' }}{{ ($i->primaryContact?->email ?: $i->email) ? ', '.($i->primaryContact?->email ?: $i->email) : '' }}
                        </div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup"><x-staf.ikon nama="tutup" ukuran="16" /></button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    <section>
                        <div class="wt-field-label mb-2">Tagihan terbuka</div>
                        <div class="overflow-x-auto rounded border border-cream-300">
                            <table class="w-full min-w-[720px] border-collapse text-[13px]">
                                <thead><tr><th class="wt-th">Tagihan</th><th class="wt-th text-right">Total</th><th class="wt-th text-right">Terbayar</th><th class="wt-th text-right">Sisa</th><th class="wt-th">Jatuh tempo</th><th class="wt-th"></th></tr></thead>
                                <tbody>
                                    @forelse ($terbuka as $t)
                                        <tr wire:key="tb-{{ $t['jenis'] }}-{{ $t['model']->id }}" @class(['bg-[rgba(232,172,69,.10)]' => $bayarJenis === $t['jenis'] && $bayarId === $t['model']->id])>
                                            <td class="wt-td"><div class="font-semibold tabular-nums">{{ $t['nomor'] }}</div><div class="text-[11px] text-ink-500">{{ $t['jenis'] === 'bulanan' ? 'Tagihan bulanan '.$t['model']->rentang() : $t['model']->jenis() }}</div></td>
                                            <td class="wt-td text-right tabular-nums">{{ $rp($t['total']) }}</td>
                                            <td class="wt-td text-right tabular-nums">{{ $rp($t['terbayar']) }}</td>
                                            <td class="wt-td text-right font-semibold tabular-nums">{{ $rp($t['sisa']) }}</td>
                                            <td @class(['wt-td whitespace-nowrap', 'font-semibold text-status-dirty' => $t['umur'] > 0])>{{ $t['jatuh_tempo']?->translatedFormat('j M Y') ?? 'Tagihan bulanan' }}{{ $t['umur'] > 0 ? ' · lewat '.$t['umur'].' hari' : '' }}</td>
                                            <td class="wt-td whitespace-nowrap text-right">
                                                <button type="button" wire:click="mulaiBayar('{{ $t['jenis'] }}', {{ $t['model']->id }})" class="h-8 rounded border-0 bg-brown-800 px-3 text-xs font-bold text-cream-50 hover:bg-brown-900">Catat pembayaran</button>
                                                @if ($t['jatuh_tempo'])
                                                    <button type="button" wire:click="kirimPengingat('{{ $t['jenis'] }}', {{ $t['model']->id }})" class="wt-btn-sm ml-1 h-8">Kirim pengingat sekarang</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="wt-td py-6 text-center text-ink-500">Semua tagihan instansi ini sudah lunas.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    @if ($bayarId)
                        <form wire:submit="bayar" class="grid gap-3 rounded border border-cream-300 bg-cream-100 p-4 sm:grid-cols-3 sm:items-end" novalidate>
                            <x-staf.isian nama="jumlah" label="Jumlah diterima"><input type="text" wire:model="jumlah" inputmode="numeric" @class(['wt-input tabular-nums', 'is-invalid' => $errors->has('jumlah')])></x-staf.isian>
                            <x-staf.isian nama="metode" label="Metode">
                                <select wire:model="metode" class="wt-input">@foreach ($metodeBayar as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                            </x-staf.isian>
                            <x-staf.isian nama="referensi" label="Nomor referensi (opsional)"><input type="text" wire:model="referensi" maxlength="100" placeholder="Contoh: TRF 0912 BRI" class="wt-input"></x-staf.isian>
                            <div class="flex gap-2 sm:col-span-3">
                                <button class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Simpan pembayaran</button>
                                <button type="button" wire:click="tutupBayar" class="wt-btn-sm h-10">Batal</button>
                            </div>
                        </form>
                    @endif

                    <section>
                        <div class="wt-field-label mb-2">Riwayat pembayaran</div>
                        @if ($riwayat->isEmpty())
                            <p class="m-0 text-[13px] text-ink-500">Belum ada pembayaran.</p>
                        @else
                            <ul class="m-0 flex list-none flex-col divide-y divide-cream-300 rounded border border-cream-300 p-0 text-[13px]">
                                @foreach ($riwayat as $p)
                                    <li class="flex justify-between gap-3 px-3 py-2">
                                        <span>
                                            <span class="font-semibold tabular-nums">{{ $p->payment_code }}</span> · {{ $p->invoice?->invoice_number ?? $p->billingStatement?->statement_number }} · {{ Payment::METHODS[$p->method] ?? $p->method }}
                                            <div class="text-[11px] text-ink-500">{{ $p->paid_at?->translatedFormat('j M Y H.i') }}{{ $p->receivedBy ? ' · '.$p->receivedBy->name : '' }}{{ $p->note ? ' · '.$p->note : '' }}</div>
                                        </span>
                                        <span @class(['font-semibold tabular-nums', 'text-status-dirty' => $p->transaction_type === 'refund'])>{{ $p->transaction_type === 'refund' ? '−' : '' }}{{ $rp($p->amount) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </div>
        </div>
    @endif
</div>
