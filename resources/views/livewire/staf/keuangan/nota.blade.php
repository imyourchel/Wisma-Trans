@php
    use App\Models\InvoiceItem;
    use App\Models\Payment;
    use App\Services\FolioService;

    $rp = fn ($n) => FolioService::rp($n);
    $lencana = fn ($x) => match (true) {
        $x->status === 'cancelled' => ['Dibatalkan', 'bg-[rgba(155,139,120,.20)] text-[#6E604F]'],
        $x->status === 'paid' => ['Lunas', 'bg-[rgba(92,158,103,.16)] text-status-available'],
        $x->due_date && $x->due_date->lt(today()) => ['Lewat jatuh tempo', 'bg-[rgba(212,81,74,.12)] text-status-dirty'],
        $x->status === 'partially_paid' => ['Dibayar sebagian', 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]'],
        default => ['Belum dibayar', 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]'],
    };
    $aksen = ['belum' => '#5B86B8', 'lewat' => '#D4514A', 'lunas' => '#5C9E67'];
@endphp

<div>
    {{-- Ringkasan --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($kartu as $k => [$label, $nilai, $ket, $saring])
            <button type="button" wire:click="$set('status', '{{ $saring }}')" class="wt-card relative overflow-hidden px-5 py-4 text-left shadow-wt-xs hover:border-cream-400">
                <span class="absolute inset-y-0 left-0 w-1" style="background-color: {{ $aksen[$k] }}"></span>
                <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">{{ $label }}</div>
                <div class="mt-1.5 font-display text-[28px] font-extrabold leading-none tabular-nums">{{ $nilai }}</div>
                <div class="mt-2 truncate text-[12px] text-ink-500">{{ $ket ? 'Sisa '.$ket : ' ' }}</div>
            </button>
        @endforeach
        <a href="{{ route('staf.keuangan.pembayaran', ['tab' => 'refund']) }}" class="wt-card relative overflow-hidden px-5 py-4 text-ink-900 shadow-wt-xs hover:border-cream-400 hover:text-ink-900 hover:no-underline">
            <span class="absolute inset-y-0 left-0 w-1 bg-[#D69A2E]"></span>
            <div class="text-[11px] font-semibold uppercase tracking-widest text-ink-500">Perlu pengembalian dana</div>
            <div @class(['mt-1.5 font-display text-[28px] font-extrabold leading-none tabular-nums', 'text-status-dirty' => $perluRefund > 0])>{{ $perluRefund }}</div>
            <div class="mt-2 text-[12px] text-ink-500">Buka antrean</div>
        </a>
    </div>

    {{-- Penyaring --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <label class="relative block min-w-0 flex-[1_1_240px] sm:max-w-[300px]">
            <span class="sr-only">Cari nota</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Nomor nota, kode, nama, atau HP" class="wt-input pl-9">
        </label>
        <select wire:model.live="jenis" class="wt-input w-[140px]" aria-label="Jenis nota">
            <option value="">Semua jenis</option><option value="stay">Menginap</option><option value="service">Layanan</option><option value="hall">Aula</option>
        </select>
        <select wire:model.live="status" class="wt-input w-[160px]" aria-label="Status nota">
            <option value="">Semua status</option><option value="belum">Belum lunas</option><option value="lewat">Lewat jatuh tempo</option>
            <option value="lunas">Lunas</option><option value="batal">Dibatalkan</option>
        </select>
        <select wire:model.live="termin" class="wt-input w-[140px]" aria-label="Termin">
            <option value="">Semua termin</option><option value="immediate">Langsung</option><option value="credit">Kredit</option>
        </select>
        @if ($daftarInstansi->isNotEmpty())
            <select wire:model.live="instansi" class="wt-input w-[180px]" aria-label="Instansi">
                <option value="">Semua instansi</option>
                @foreach ($daftarInstansi as $id => $nama)<option value="{{ $id }}">{{ $nama }}</option>@endforeach
            </select>
        @endif
        <div class="flex items-center gap-1.5">
            <input type="date" wire:model.live="dari" class="wt-input w-[150px]" aria-label="Terbit dari">
            <span class="text-ink-500">–</span>
            <input type="date" wire:model.live="sampai" class="wt-input w-[150px]" aria-label="Terbit sampai">
        </div>
        @if ($jenis.$status.$termin.$instansi.$dari.$sampai.$cari !== '')
            <button type="button" wire:click="resetSaringan" class="wt-btn-sm h-10">Hapus saringan</button>
        @endif
    </div>

    <div class="wt-card overflow-x-auto shadow-wt-xs">
        <table class="w-full min-w-[1040px] border-collapse">
            <thead><tr>
                <th class="wt-th">Nomor</th><th class="wt-th">Jenis</th><th class="wt-th">Pelanggan</th><th class="wt-th">Terbit</th>
                <th class="wt-th text-right">Total</th><th class="wt-th text-right">Terbayar</th><th class="wt-th text-right">Sisa</th>
                <th class="wt-th">Status</th><th class="wt-th">Jatuh tempo</th>
            </tr></thead>
            <tbody>
                @forelse ($daftar as $x)
                    @php [$l, $w] = $lencana($x); @endphp
                    <tr wire:key="nota-{{ $x->id }}" wire:click="open({{ $x->id }})" class="cursor-pointer hover:bg-row-hover">
                        <td class="wt-td whitespace-nowrap font-semibold tabular-nums">{{ $x->invoice_number }}</td>
                        <td class="wt-td text-[13px]">{{ $x->jenis() }}{{ $x->payment_term === 'credit' ? ' · kredit' : '' }}</td>
                        <td class="wt-td"><div class="font-semibold">{{ $x->institution?->name ?? $x->customer?->full_name }}</div>
                            @if ($x->institution)<div class="text-[11px] text-ink-500">{{ $x->customer?->full_name }}</div>@endif</td>
                        <td class="wt-td whitespace-nowrap text-[13px]">{{ $x->issue_date?->translatedFormat('j M Y') }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $rp($x->total_amount) }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $rp($x->paid_amount) }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $x->status === 'cancelled' ? '—' : $rp($x->sisa()) }}</td>
                        <td class="wt-td"><span class="wt-lencana {{ $w }}">{{ $l }}</span></td>
                        <td class="wt-td whitespace-nowrap text-[13px]">{{ $x->due_date?->translatedFormat('j M Y') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="wt-td py-16 text-center text-sm text-ink-500">Tidak ada nota yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $daftar->links('partials.halaman') }}

    {{-- Detail nota --}}
    @if ($n)
        @php [$l, $w] = $lencana($n); @endphp
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="nota-judul" class="relative flex max-h-[94vh] w-[780px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="nota-judul" class="flex flex-wrap items-center gap-2 font-display text-[17px] font-extrabold tracking-[.02em]">
                            {{ $n->invoice_number }} <span class="wt-lencana {{ $w }} font-sans text-[11px]">{{ $l }}</span>
                        </div>
                        <div class="mt-0.5 truncate text-[13px] text-ink-500">
                            {{ $n->jenis() }} · {{ $n->institution?->name ? $n->institution->name.' · ' : '' }}{{ $n->customer?->full_name }}{{ $n->customer?->phone ? ' · '.$n->customer->phone : '' }}
                        </div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup">
                        <x-staf.ikon nama="tutup" ukuran="16" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    @if ($penghalang && $n->status !== 'cancelled')
                        <p class="m-0 rounded bg-cream-200 px-4 py-2.5 text-[13px] text-ink-500" data-penghalang>{{ $penghalang }}</p>
                    @endif

                    {{-- Sumber --}}
                    <dl class="m-0 grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-3">
                        <div><dt class="text-[11px] text-ink-500">Terbit</dt><dd class="m-0">{{ $n->issue_date?->translatedFormat('j M Y') ?? '—' }}</dd></div>
                        <div><dt class="text-[11px] text-ink-500">Termin</dt><dd class="m-0">{{ $n->payment_term === 'credit' ? 'Kredit' : 'Langsung' }}{{ $n->due_date ? ', jatuh tempo '.$n->due_date->translatedFormat('j M Y') : '' }}</dd></div>
                        <div><dt class="text-[11px] text-ink-500">Sumber</dt><dd class="m-0">
                            @if ($n->reservation)
                                <a href="{{ route('staf.reservasi', ['cari' => $n->reservation->reservation_code]) }}" class="font-semibold text-clay-600">Reservasi {{ $n->reservation->reservation_code }}</a>
                            @elseif ($n->hallBooking)
                                <a href="{{ route('staf.aula-pemesanan') }}" class="font-semibold text-clay-600">Sewa aula {{ $n->hallBooking->booking_code }}</a>
                            @elseif ($n->serviceOrders->isNotEmpty())
                                Pesanan {{ $n->serviceOrders->pluck('order_code')->join(', ') }}
                            @else
                                —
                            @endif
                        </dd></div>
                    </dl>

                    {{-- Baris nota --}}
                    <div class="overflow-hidden rounded border border-cream-300">
                        <table class="w-full border-collapse text-[13px]">
                            <thead><tr><th class="wt-th">Uraian</th><th class="wt-th text-right">Jumlah</th><th class="wt-th text-right">Harga</th><th class="wt-th text-right">Subtotal</th>@if ($bolehUbah && ! $penghalang)<th class="wt-th"></th>@endif</tr></thead>
                            <tbody>
                                @foreach ($n->items as $i)
                                    <tr wire:key="baris-{{ $i->id }}" @class(['bg-[rgba(232,172,69,.10)]' => $barisId === $i->id])>
                                        <td class="wt-td"><div>{{ $i->description }}</div><div class="text-[11px] text-ink-500">{{ InvoiceItem::JENIS[$i->item_type] ?? $i->item_type }}{{ $i->service_date ? ' · '.$i->service_date->translatedFormat('j M') : '' }}</div></td>
                                        <td class="wt-td text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $i->quantity, 2, ',', '.'), '0'), ',') }}</td>
                                        <td class="wt-td text-right tabular-nums">{{ $rp($i->unit_price) }}</td>
                                        <td class="wt-td text-right tabular-nums">
                                            {{ $rp($i->subtotal) }}
                                            @if ((float) $i->discount_amount > 0)<div class="text-[11px] text-status-available">potongan {{ $rp($i->discount_amount) }}</div>@endif
                                        </td>
                                        @if ($bolehUbah && ! $penghalang)
                                            <td class="wt-td whitespace-nowrap text-right">
                                                <button type="button" wire:click="mulai('koreksi', {{ $i->id }})" class="border-0 bg-transparent p-0 text-[12px] font-semibold text-clay-600 hover:underline">Koreksi</button>
                                                @if ($i->item_type !== 'room')
                                                    <button type="button" wire:click="mulai('hapus', {{ $i->id }})" class="ml-2 border-0 bg-transparent p-0 text-[12px] font-semibold text-status-dirty hover:underline">Hapus</button>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <dl class="m-0 grid gap-1.5 border-t border-cream-300 bg-cream-100 px-4 py-3 text-[13px]">
                            <div class="flex justify-between"><dt class="text-ink-500">Subtotal</dt><dd class="m-0 tabular-nums">{{ $rp($n->subtotal) }}</dd></div>
                            @if ((float) $n->discount_amount > 0)
                                <div class="flex justify-between"><dt class="text-ink-500">Diskon nota{{ $n->discount_type === 'percent' ? ' '.rtrim(rtrim(number_format((float) $n->discount_value, 2, ',', ''), '0'), ',').'%' : '' }}</dt><dd class="m-0 tabular-nums">−{{ $rp($n->discount_amount) }}</dd></div>
                            @endif
                            <div class="flex justify-between font-semibold"><dt>Total</dt><dd class="m-0 tabular-nums" data-total>{{ $rp($n->total_amount) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-ink-500">Terbayar</dt><dd class="m-0 tabular-nums">{{ $rp($n->paid_amount) }}</dd></div>
                            @if ($lebih > 0)
                                <div class="flex justify-between border-t border-cream-300 pt-1.5 font-semibold text-[#8A5E10]" data-lebih><dt>Lebih bayar</dt><dd class="m-0 tabular-nums">{{ $rp($lebih) }}</dd></div>
                            @elseif ($n->status !== 'cancelled')
                                <div class="flex justify-between border-t border-cream-300 pt-1.5"><dt class="font-semibold">Sisa</dt><dd class="m-0 font-display text-[16px] font-extrabold tabular-nums" data-sisa>{{ $rp($sisa) }}</dd></div>
                            @endif
                        </dl>
                    </div>
                    @if ($refundTertunda)
                        <p class="m-0 -mt-2 text-[12px] text-[#8A5E10]">{{ $rp($refundTertunda->amount) }} menunggu dikembalikan ke tamu ({{ $refundTertunda->payment_code }}).</p>
                    @endif

                    {{-- Pesanan yang digabung --}}
                    @if ($n->invoice_type === 'stay' && $n->serviceOrders->isNotEmpty())
                        <div>
                            <div class="wt-field-label mb-1.5">Pesanan yang digabung ke nota ini</div>
                            <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[13px]">
                                @foreach ($n->serviceOrders as $o)
                                    <li class="flex justify-between gap-3">
                                        <span>{{ $o->order_code }} · {{ $o->jenisLayanan() === 'laundry' ? 'Laundry' : 'Makanan' }}{{ $o->status === 'cancelled' ? ' (dibatalkan)' : '' }}</span>
                                        <span class="tabular-nums">{{ $rp($o->items->sum('subtotal')) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Pembayaran --}}
                    <div>
                        <div class="wt-field-label mb-1.5">Pembayaran</div>
                        @if ($n->payments->isEmpty())
                            <p class="m-0 text-[13px] text-ink-500">Belum ada pembayaran.</p>
                        @else
                            <ul class="m-0 flex list-none flex-col divide-y divide-cream-300 rounded border border-cream-300 p-0 text-[13px]">
                                @foreach ($n->payments as $p)
                                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 px-3 py-2">
                                        <span class="min-w-0">
                                            <span class="font-semibold tabular-nums">{{ $p->payment_code }}</span>
                                            · {{ $p->transaction_type === 'refund' ? 'Pengembalian' : (Payment::TUJUAN[$p->payment_purpose] ?? $p->payment_purpose) }}
                                            · {{ Payment::METHODS[$p->method] ?? $p->method }}
                                            @if ($p->midtrans_order_id)<span class="text-[11px] text-ink-500">· {{ $p->midtrans_order_id }}</span>@endif
                                            @if ($p->transaction_type === 'refund' && $p->status === 'pending')
                                                <span class="wt-lencana ml-1 bg-[rgba(232,172,69,.18)] text-[#8A5E10]">Menunggu dikembalikan</span>
                                            @elseif ($p->status !== 'success')
                                                <span class="wt-lencana wt-lencana-netral ml-1">{{ Payment::STATUS[$p->status] ?? $p->status }}</span>
                                            @endif
                                            <div class="text-[11px] text-ink-500">{{ ($p->paid_at ?? $p->created_at)?->translatedFormat('j M Y H.i') }}{{ $p->receivedBy ? ' · '.$p->receivedBy->name : '' }}{{ $p->note ? ' · '.$p->note : '' }}</div>
                                        </span>
                                        <span @class(['tabular-nums font-semibold', 'text-status-dirty' => $p->transaction_type === 'refund', 'line-through text-ink-500' => ! in_array($p->status, ['success', 'pending'], true)])>{{ $p->transaction_type === 'refund' ? '−' : '' }}{{ $rp($p->amount) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    @if ($n->note)
                        <p class="m-0 whitespace-pre-line text-[12px] text-ink-500"><span class="font-semibold">Catatan:</span> {{ $n->note }}</p>
                    @endif

                    {{-- Formulir aksi --}}
                    @if ($qris)
                        @include('livewire.staf.partials.qris-meja')
                    @elseif ($aksi !== '')
                        <div class="rounded border border-cream-300 bg-cream-100 p-4">
                            @if ($aksi === 'bayar')
                                <form wire:submit="bayar" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" novalidate>
                                    <x-staf.isian nama="jumlah" label="Jumlah" :bantuan="'Kosongkan untuk melunasi '.$rp($sisa)">
                                        <input type="text" wire:model="jumlah" inputmode="numeric" placeholder="{{ number_format($sisa, 0, ',', '.') }}" @class(['wt-input tabular-nums', 'is-invalid' => $errors->has('jumlah')])>
                                    </x-staf.isian>
                                    <x-staf.isian nama="metode" label="Metode">
                                        <select wire:model="metode" class="wt-input">@foreach ($metodeBayar as $v => $m)<option value="{{ $v }}">{{ $m }}</option>@endforeach</select>
                                    </x-staf.isian>
                                    <div class="flex gap-2 sm:pb-[22px]">
                                        <button class="h-10 rounded border-0 bg-brown-800 px-4 text-xs font-bold text-cream-50 hover:bg-brown-900">Catat</button>
                                        <button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button>
                                    </div>
                                </form>
                            @elseif ($aksi === 'diskon')
                                <form wire:submit="simpanDiskon" class="grid gap-3 sm:grid-cols-[140px_1fr] sm:items-end" novalidate>
                                    <x-staf.isian nama="diskonJenis" label="Bentuk">
                                        <select wire:model="diskonJenis" class="wt-input"><option value="percent">Persen</option><option value="amount">Nominal</option></select>
                                    </x-staf.isian>
                                    <x-staf.isian nama="diskonNilai" label="Nilai">
                                        <input type="text" wire:model="diskonNilai" inputmode="decimal" placeholder="Contoh: 10 atau 50000" @class(['wt-input', 'is-invalid' => $errors->has('diskonNilai')])>
                                    </x-staf.isian>
                                    <x-staf.isian nama="alasan" label="Alasan" class="sm:col-span-2">
                                        <input type="text" wire:model="alasan" maxlength="150" placeholder="Contoh: tamu langganan, disetujui pemilik" @class(['wt-input', 'is-invalid' => $errors->has('alasan')])>
                                    </x-staf.isian>
                                    <div class="flex gap-2 sm:col-span-2">
                                        <button class="h-10 rounded border-0 bg-brown-800 px-4 text-xs font-bold text-cream-50 hover:bg-brown-900">Simpan diskon</button>
                                        <button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button>
                                    </div>
                                </form>
                            @elseif ($aksi === 'koreksi')
                                <form wire:submit="simpanBaris" class="grid gap-3 sm:grid-cols-2 sm:items-end" novalidate>
                                    <x-staf.isian nama="barisJumlah" label="Jumlah"><input type="text" wire:model="barisJumlah" inputmode="decimal" @class(['wt-input tabular-nums', 'is-invalid' => $errors->has('barisJumlah')])></x-staf.isian>
                                    <x-staf.isian nama="barisHarga" label="Harga satuan"><input type="text" wire:model="barisHarga" inputmode="numeric" @class(['wt-input tabular-nums', 'is-invalid' => $errors->has('barisHarga')])></x-staf.isian>
                                    <x-staf.isian nama="alasan" label="Alasan koreksi" class="sm:col-span-2">
                                        <input type="text" wire:model="alasan" maxlength="150" @class(['wt-input', 'is-invalid' => $errors->has('alasan')])>
                                    </x-staf.isian>
                                    <div class="flex gap-2 sm:col-span-2">
                                        <button class="h-10 rounded border-0 bg-brown-800 px-4 text-xs font-bold text-cream-50 hover:bg-brown-900">Simpan koreksi</button>
                                        <button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Batal</button>
                                    </div>
                                </form>
                            @elseif ($aksi === 'hapus' || $aksi === 'batal')
                                <form wire:submit="{{ $aksi === 'hapus' ? 'hapusBaris' : 'batalkanNota' }}" class="flex flex-col gap-3" novalidate>
                                    <p class="m-0 text-[13px]">{{ $aksi === 'hapus' ? 'Baris ini dihapus dari nota. Bila baris itu pesanan, pesanannya ikut dibatalkan.' : 'Nota ini dibatalkan dan tidak bisa dibayar lagi.' }}</p>
                                    <x-staf.isian nama="alasan" label="Alasan">
                                        <input type="text" wire:model="alasan" maxlength="150" @class(['wt-input', 'is-invalid' => $errors->has('alasan')])>
                                    </x-staf.isian>
                                    <div class="flex gap-2">
                                        <button class="h-10 rounded border-0 bg-status-dirty px-4 text-xs font-bold text-cream-50">{{ $aksi === 'hapus' ? 'Hapus baris' : 'Batalkan nota' }}</button>
                                        <button type="button" wire:click="tutupAksi" class="wt-btn-sm h-10">Tidak</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endif
                    @foreach (['nota', 'diskon', 'baris', 'batal'] as $kunci)
                        @error($kunci)<p class="m-0 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror
                    @endforeach
                    @if ($aksi !== 'bayar')
                        @error('jumlah')<p class="m-0 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div class="flex flex-none flex-wrap gap-2 border-t border-cream-300 px-6 py-4">
                    @if ($bolehCatat && ! $penghalangBayar && $sisa > 0 && ! $qris)
                        <button type="button" wire:click="mulai('bayar')" class="h-10 rounded border-0 bg-clay-500 px-4 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Catat pembayaran</button>
                        @if ($midtransSiap)
                            <button type="button" wire:click="mulaiQris" class="wt-btn-sm h-10">QRIS (Midtrans)</button>
                        @endif
                    @endif
                    <a href="{{ $urlPdf }}" target="_blank" rel="noopener" class="wt-btn-sm h-10 hover:no-underline">Unduh PDF</a>
                    @if ($bolehCatat && $n->status !== 'cancelled')
                        <button type="button" wire:click="kirimUlang" wire:loading.attr="disabled" wire:target="kirimUlang" class="wt-btn-sm h-10">Kirim ulang ke tamu</button>
                    @endif
                    @if ($bolehUbah && ! $penghalang)
                        @if ($n->status !== 'paid')
                            <button type="button" wire:click="mulai('diskon')" class="wt-btn-sm h-10">Diskon nota</button>
                        @endif
                        <button type="button" wire:click="mulai('batal')" class="wt-btn-sm ml-auto h-10 !text-status-dirty">Batalkan nota</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
