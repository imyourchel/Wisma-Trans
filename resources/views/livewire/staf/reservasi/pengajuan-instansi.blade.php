@php
    use App\Models\Institution;
    use App\Models\Reservation;
    use App\Services\Reservasi\ReservasiService;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
@endphp

<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Saring pengajuan">
            @foreach (['menunggu' => 'Menunggu ('.$jumlahMenunggu.')', 'diputuskan' => 'Sudah diputuskan'] as $v => $l)
                <button type="button" wire:click="$set('tab', '{{ $v }}')" role="tab" aria-selected="{{ $tab === $v ? 'true' : 'false' }}"
                        @class(['h-8 rounded-md border-0 px-3.5 text-[13px] font-semibold transition-colors',
                            'bg-brown-800 text-cream-50' => $tab === $v, 'bg-transparent text-ink-500 hover:text-ink-900' => $tab !== $v])>{{ $l }}</button>
            @endforeach
        </div>
        <a href="{{ route('instansi') }}" target="_blank" rel="noopener" class="ml-auto wt-btn-sm hover:no-underline">Isi pengajuan untuk instansi</a>
    </div>
    @unless ($bolehPutuskan)
        <p class="mb-4 rounded bg-cream-200 px-4 py-3 text-[13px]">Pengajuan disetujui atau ditolak oleh Manager.</p>
    @endunless

    @if ($daftar->isEmpty())
        <div class="wt-card px-6 py-12 text-center text-sm text-ink-500">{{ $tab === 'menunggu' ? 'Tidak ada pengajuan yang menunggu.' : 'Belum ada pengajuan yang diputuskan.' }}</div>
    @else
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($daftar as $x)
                @php $q = $x->request; $instansiTulis = preg_match('/^Instansi: (.+)$/m', (string) $x->special_request, $mm) ? trim($mm[1]) : null; @endphp
                <div wire:key="aju-{{ $x->id }}" class="wt-card flex flex-col p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-display text-[15px] font-extrabold tabular-nums">{{ $x->reservation_code }}</span>
                                <x-staf.status-reservasi :status="$x->status" />
                            </div>
                            <div class="mt-0.5 text-[13px] font-semibold">{{ $x->institution?->name ?? $x->customer?->institution?->name ?? $instansiTulis ?? 'Instansi belum ditetapkan' }}
                                @if (! $x->institution && ! $x->customer?->institution && $instansiTulis)<span class="wt-lencana wt-lencana-netral ml-1">baru</span>@endif
                            </div>
                            <div class="text-[12px] text-ink-500">{{ $x->customer?->full_name }} · {{ $x->customer?->phone }}</div>
                        </div>
                        <div class="text-right text-[12px] text-ink-500">Diajukan<br>{{ $x->created_at->translatedFormat('j M, H.i') }}</div>
                    </div>
                    <dl class="m-0 mt-3 grid grid-cols-3 gap-3 rounded bg-cream-100 px-3 py-2.5 text-[13px]">
                        <div><dt class="text-[11px] text-ink-500">Tanggal</dt><dd class="m-0 font-semibold">{{ ReservasiService::rentang($q->check_in_date, $q->check_out_date) }}</dd></div>
                        <div><dt class="text-[11px] text-ink-500">Malam</dt><dd class="m-0 font-semibold">{{ $q->malam() }}</dd></div>
                        <div><dt class="text-[11px] text-ink-500">Kamar diajukan</dt><dd class="m-0 font-semibold">{{ $q->room_count }}</dd></div>
                    </dl>
                    @if ($q->event_name)<div class="mt-2 text-[13px]"><span class="text-ink-500">Kegiatan:</span> {{ $q->event_name }}</div>@endif
                    @php $cat = trim(preg_replace('/^Instansi: .+$/m', '', (string) $x->special_request)); @endphp
                    @if ($cat !== '')<div class="mt-1 whitespace-pre-line text-[13px] italic">“{{ $cat }}”</div>@endif
                    @if ($x->status !== 'waiting_approval')
                        <div class="mt-2 text-[12px] text-ink-500">
                            {{ $x->status === 'rejected' ? 'Alasan' : 'Catatan' }}: {{ $x->logs->sortByDesc('id')->firstWhere('event_type', 'status_change')?->note ?? '—' }}
                            @if ($x->rooms->isNotEmpty()) · Kamar {{ $x->rooms->where('status', '!=', 'moved')->map(fn ($b) => $b->room?->room_number)->filter()->join(', ') }} @endif
                        </div>
                    @elseif ($bolehPutuskan)
                        <div class="mt-auto flex gap-2 pt-4">
                            <button type="button" wire:click="open({{ $x->id }}, 'setuju')" class="h-9 flex-1 rounded border-0 bg-clay-500 font-display text-xs font-bold text-cream-50 hover:bg-clay-600">Tinjau dan setujui</button>
                            <button type="button" wire:click="open({{ $x->id }}, 'tolak')" class="wt-btn-sm h-9 !text-status-dirty">Tolak</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Keputusan Manager --}}
    @if ($r && $mode !== '')
        @php $q = $r->request; @endphp
        <div class="fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
            <form wire:submit="{{ $mode === 'tolak' ? 'tolak' : 'setujui' }}" role="dialog" aria-modal="true" aria-labelledby="aju-judul" novalidate
                  @class(['relative flex max-h-[94vh] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg', 'w-[880px]' => $mode === 'setuju', 'w-[520px]' => $mode === 'tolak'])>
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div id="aju-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $mode === 'tolak' ? 'Tolak' : 'Setujui' }} {{ $r->reservation_code }}</div>
                        <div class="mt-0.5 text-[13px] text-ink-500">{{ ReservasiService::rentang($q->check_in_date, $q->check_out_date) }} · {{ $malam }} malam · {{ $q->room_count }} kamar diajukan{{ $q->event_name ? ' · '.$q->event_name : '' }}</div>
                    </div>
                    <button type="button" wire:click="close" class="flex h-8 w-8 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup">
                        <x-staf.ikon nama="tutup" ukuran="16" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    @if ($mode === 'tolak')
                        <x-staf.isian nama="alasan" label="Alasan penolakan" bantuan="Dikirim ke narahubung lewat WhatsApp dan tampil di halaman cek pesanan.">
                            <textarea wire:model="alasan" rows="3" maxlength="200" placeholder="Contoh: hanya tersisa 7 kamar pada tanggal itu; silakan ajukan ulang dengan jumlah lebih sedikit" class="wt-input {{ $galat('alasan') }}"></textarea>
                        </x-staf.isian>
                    @else
                        @if ($pola)
                            @include('livewire.staf.partials.kartu-pola-rombongan', $pola)
                        @endif

                        {{-- Instansi dan penagihan --}}
                        <section class="grid gap-4 sm:grid-cols-2">
                            <x-staf.isian nama="instansi" label="Instansi" tag="div">
                                <x-staf.pilih model="$wire.instansi" x-on:change="$wire.$commit()" label="Instansi" :nilai="$instansi" :galat="$errors->has('instansi') ? 'true' : null"
                                              :pilihan="['' => 'Pilih instansi', 'baru' => '+ Instansi baru'] + $daftarInstansi->all()" />
                            </x-staf.isian>
                            @if ($instansi === 'baru')
                                <x-staf.isian nama="namaBaru" label="Nama instansi baru"><input type="text" wire:model="namaBaru" maxlength="150" class="wt-input {{ $galat('namaBaru') }}"></x-staf.isian>
                            @endif
                            <x-staf.isian nama="skema" label="Skema penagihan kamar" tag="div">
                                <x-staf.pilih model="$wire.skema" x-on:change="$wire.$commit()" label="Skema penagihan" :nilai="$skema" :pilihan="Institution::SKEMA" />
                            </x-staf.isian>
                            @if ($instansi === 'baru' && $skema === 'monthly_fixed_date')
                                <x-staf.isian nama="tanggalTagih" label="Tanggal tagih tiap bulan"><input type="number" min="1" max="28" wire:model="tanggalTagih" class="wt-input {{ $galat('tanggalTagih') }}"></x-staf.isian>
                            @elseif ($instansi === 'baru' && $skema === 'due_after_checkout')
                                <x-staf.isian nama="jatuhTempo" label="Jatuh tempo (hari setelah check-out)"><input type="number" min="1" max="90" wire:model="jatuhTempo" class="wt-input {{ $galat('jatuhTempo') }}"></x-staf.isian>
                            @endif
                            <x-staf.isian nama="skemaLayanan" label="Tagihan layanan (makanan, laundry)" tag="div">
                                <x-staf.pilih model="$wire.skemaLayanan" label="Skema layanan" :nilai="$skemaLayanan" :pilihan="Reservation::SKEMA_LAYANAN" />
                            </x-staf.isian>
                        </section>

                        {{-- Kamar --}}
                        <section>
                            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Tetapkan kamar <span class="font-sans text-[12px] font-normal normal-case tracking-normal text-ink-500">· {{ count($kamar) }} dari {{ $q->room_count }} diajukan</span></h3>
                                @error('kamar')<span class="text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                            </div>
                            <div class="flex flex-col gap-3 rounded border border-cream-300 p-3">
                                @foreach ($tipe as $t)
                                    @php $kosong = $kamarBebas->get($t->id, collect()); @endphp
                                    <div wire:key="t-{{ $t->id }}">
                                        <div class="mb-1.5 text-[12px]"><span class="font-semibold">{{ $t->name }}</span> <span class="text-ink-500">· {{ $rp($t->base_price) }} · {{ $sisa[$t->id] ?? 0 }} bisa dijual</span></div>
                                        @if ($kosong->isEmpty())
                                            <p class="m-0 text-[12px] text-ink-500">Tidak ada kamar kosong pada tanggal itu.</p>
                                        @else
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach ($kosong as $k)
                                                    <label wire:key="k-{{ $k->id }}" class="flex h-9 min-w-[60px] cursor-pointer items-center justify-center rounded border border-cream-300 bg-cream-50 px-2.5 text-[13px] font-semibold tabular-nums hover:border-brown-800 has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-50">
                                                        <input type="checkbox" wire:model.live="kamar" value="{{ $k->id }}" class="sr-only">{{ $k->room_number }}
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- Harga kesepakatan --}}
                        @if ($kamar)
                            <section>
                                <h3 class="mb-1 mt-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Harga dan potongan</h3>
                                <p class="mb-3 mt-0 text-xs text-ink-500">Harga per malam mengikuti harga kamar dan tidak bisa diubah. Bila ada kesepakatan khusus, pilih jenis potongan untuk kamar itu.</p>
                                <div class="flex flex-col gap-2.5">
                                    @foreach ($kamar as $id)
                                        @php
                                            $room = $kamarBebas->flatten(1)->firstWhere('id', (int) $id);
                                            $jenis = $harga[$id]['jenis'] ?? '';
                                            $h = $hitung[$id] ?? ['kotor' => 0, 'potong' => 0, 'bersih' => 0];
                                        @endphp
                                        <div wire:key="h-{{ $id }}" class="rounded-md border border-cream-300 bg-cream-50 p-3.5">
                                            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                                <div>
                                                    <span class="font-display text-[15px] font-extrabold tabular-nums">{{ $room?->room_number }}</span>
                                                    <span class="ml-1 text-[13px] text-ink-500">{{ $tipe[$room?->room_type_id]?->name }}</span>
                                                    <div class="mt-0.5 text-xs tabular-nums text-ink-500">{{ $rp($harga[$id]['harga'] ?? 0) }} / malam × {{ $malam }} malam</div>
                                                </div>
                                                <div class="text-right tabular-nums">
                                                    @if ($h['potong'] > 0)
                                                        <div class="text-xs text-ink-500 line-through">{{ $rp($h['kotor']) }}</div>
                                                    @endif
                                                    <div class="font-display text-[15px] font-extrabold {{ $h['potong'] > 0 ? 'text-status-available' : '' }}">{{ $jenis === 'free' ? 'Gratis' : $rp($h['bersih']) }}</div>
                                                </div>
                                            </div>

                                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                                <div class="inline-flex flex-wrap rounded-md border border-cream-300 bg-cream-100 p-0.5" role="radiogroup" aria-label="Jenis potongan kamar {{ $room?->room_number }}">
                                                    @foreach (['' => 'Tanpa potongan', 'percent' => 'Persen (%)', 'amount' => 'Nominal (Rp)', 'free' => 'Gratis'] as $nilaiJenis => $labelJenis)
                                                        <button type="button" wire:click="jenisPotongan('{{ $id }}', '{{ $nilaiJenis }}')" role="radio" aria-checked="{{ $jenis === $nilaiJenis ? 'true' : 'false' }}"
                                                                @class(['h-8 rounded border-0 px-3 text-xs font-semibold transition',
                                                                    'bg-brown-800 text-cream-50 shadow-wt-xs' => $jenis === $nilaiJenis,
                                                                    'bg-transparent text-ink-500 hover:text-ink-900' => $jenis !== $nilaiJenis])>{{ $labelJenis }}</button>
                                                    @endforeach
                                                </div>

                                                @if (in_array($jenis, ['percent', 'amount'], true))
                                                    <label class="flex flex-wrap items-center gap-2 text-xs text-ink-500">
                                                        <span class="font-semibold text-ink-900">Besar potongan</span>
                                                        <span class="relative">
                                                            @if ($jenis === 'amount')<span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">Rp</span>@endif
                                                            <input type="number" min="0" @if ($jenis === 'percent') max="100" step="0.5" @else step="1000" @endif wire:model.live.debounce.400ms="harga.{{ $id }}.nilai"
                                                                   placeholder="{{ $jenis === 'percent' ? '15' : '100000' }}"
                                                                   class="wt-input h-9 w-[140px] tabular-nums {{ $jenis === 'amount' ? 'pl-9' : 'pr-8' }} {{ $galat('harga.'.$id.'.nilai') }}" aria-label="Besar potongan kamar {{ $room?->room_number }}">
                                                            @if ($jenis === 'percent')<span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">%</span>@endif
                                                        </span>
                                                        <span>{{ $jenis === 'percent' ? 'dari total '.$malam.' malam' : 'total untuk '.$malam.' malam' }}</span>
                                                    </label>
                                                @elseif ($jenis === 'free')
                                                    <span class="text-xs text-ink-500">Kamar ini tidak ditagihkan (harga Rp0).</span>
                                                @endif
                                            </div>
                                            @if ($h['potong'] > 0 && $jenis !== 'free')
                                                <div class="mt-2 text-xs text-status-available">Hemat {{ $rp($h['potong']) }}</div>
                                            @endif
                                            @error('harga.'.$id.'.nilai')<div class="mt-1.5 text-xs text-status-dirty">{{ $message }}</div>@enderror
                                        </div>
                                    @endforeach
                                </div>
                                <div class="mt-3 flex items-baseline justify-end gap-2 border-t border-cream-300 pt-3 text-[13px]">
                                    Total kamar {{ $malam }} malam
                                    @if ($hitung->sum('potong') > 0)<span class="text-xs tabular-nums text-ink-500 line-through">{{ $rp($hitung->sum('kotor')) }}</span>@endif
                                    <span class="font-display text-base font-extrabold tabular-nums">{{ $rp($total) }}</span>
                                </div>
                            </section>
                        @endif

                        <x-staf.isian nama="catatan" label="Catatan persetujuan (opsional)">
                            <input type="text" wire:model="catatan" maxlength="200" placeholder="Contoh: potongan 15% sesuai kontrak" class="wt-input">
                        </x-staf.isian>
                    @endif
                    @error('status')<p class="m-0 text-[13px] text-status-dirty">{{ $message }}</p>@enderror
                </div>

                <div class="flex flex-none gap-2 border-t border-cream-300 px-6 py-4">
                    <button type="submit" wire:loading.attr="disabled" @class(['h-11 flex-1 rounded border-0 font-display text-[13px] font-bold text-cream-50 disabled:opacity-70',
                        'bg-status-dirty hover:opacity-90' => $mode === 'tolak', 'bg-clay-500 hover:bg-clay-600' => $mode !== 'tolak'])>
                        {{ $mode === 'tolak' ? 'Tolak pengajuan' : 'Setujui dan tetapkan kamar' }}
                    </button>
                    <button type="button" wire:click="close" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
