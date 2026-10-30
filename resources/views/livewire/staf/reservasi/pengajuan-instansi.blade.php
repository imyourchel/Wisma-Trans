@php
    use App\Models\Institution;
    use App\Models\Reservation;
    use App\Models\Room;
    use App\Services\Reservasi\ReservasiService;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $galat = fn ($f) => $errors->has($f) ? 'is-invalid' : '';
@endphp

<div>
    {{-- Saat meninjau untuk disetujui, daftar disembunyikan dan tinjauan tampil sebagai panel di halaman (bukan modal) --}}
    @unless ($r && $mode === 'setuju')
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="wt-geser inline-flex max-w-full rounded-lg border border-cream-300 bg-cream-50 p-0.5" role="tablist" aria-label="Saring pengajuan">
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
                            {{-- Belum tertaut: nama tulisan pemohon (Manager mencocokkannya saat menyetujui), bukan instansi narahubungnya --}}
                            <div class="mt-0.5 text-[13px] font-semibold">{{ $x->institution?->name ?? $instansiTulis ?? $x->customer?->institution?->name ?? 'Instansi belum ditetapkan' }}
                                @if (! $x->institution && $instansiTulis)<span class="wt-lencana wt-lencana-netral ml-1">belum tertaut</span>@endif
                            </div>
                            <div class="text-[12px] text-ink-500">{{ $x->customer?->full_name }} · {{ $x->customer?->phone }}</div>
                        </div>
                        <div class="text-right text-[12px] text-ink-500">Diajukan<br>{{ $x->created_at->translatedFormat('j M, H.i') }}</div>
                    </div>
                    @php $peserta = $x->pesertaInstansi(); @endphp
                    <dl @class(['m-0 mt-3 grid gap-3 rounded bg-cream-100 px-3 py-2.5 text-[13px]', 'grid-cols-3' => ! $peserta, 'grid-cols-2 sm:grid-cols-4' => $peserta])>
                        <div><dt class="text-[11px] text-ink-500">Tanggal</dt><dd class="m-0 font-semibold">{{ ReservasiService::rentang($q->check_in_date, $q->check_out_date) }}</dd></div>
                        <div><dt class="text-[11px] text-ink-500">Malam</dt><dd class="m-0 font-semibold">{{ $q->malam() }}</dd></div>
                        <div><dt class="text-[11px] text-ink-500">Kamar diajukan</dt><dd class="m-0 font-semibold">{{ $q->room_count }}</dd></div>
                        @if ($peserta)<div data-peserta-instansi><dt class="text-[11px] text-ink-500">Jumlah tamu</dt><dd class="m-0 font-semibold">{{ $peserta }} orang</dd></div>@endif
                    </dl>
                    @if ($q->event_name)<div class="mt-2 text-[13px]"><span class="text-ink-500">Kegiatan:</span> {{ $q->event_name }}</div>@endif
                    @php $cat = $x->catatanTamu(); @endphp
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
    @endunless

    {{-- Keputusan Manager: setujui sebagai panel halaman, tolak sebagai dialog kecil --}}
    @if ($r && $mode !== '')
        @php
            $q = $r->request;
            $labelPotongan = ['' => 'Tanpa potongan', 'percent' => 'Persen (%)', 'amount' => 'Nominal (Rp)', 'free' => 'Gratis'];
            $selisih = count($kamar) - $q->room_count;
            $ringkasPotongan = fn (array $h) => match ($h['jenis'] ?? '') {
                'percent' => (float) ($h['nilai'] ?: 0) > 0 ? '−'.rtrim(rtrim(number_format((float) $h['nilai'], 1, ',', '.'), '0'), ',').'%' : 'Persen belum diisi',
                'amount' => (float) ($h['nilai'] ?: 0) > 0 ? '−'.$rp($h['nilai']).'/malam' : 'Nominal belum diisi',
                'free' => 'Gratis',
                default => 'Tanpa potongan',
            };
        @endphp
        @if ($mode === 'tolak')
        <div class="wt-modal fixed inset-0 z-[340] flex items-center justify-center p-4" x-on:keydown.escape.window="$wire.close()">
            <div wire:click="close" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
        @else
        <div wire:key="tinjau-{{ $r->id }}" x-init="$el.scrollIntoView({ block: 'start' })" class="scroll-mt-20" data-tinjau-pengajuan>
            <button type="button" wire:click="close" class="mb-3 inline-flex items-center gap-1.5 border-0 bg-transparent p-0 text-[13px] font-semibold text-clay-600 hover:underline" data-kembali-daftar>
                <span aria-hidden="true">←</span> Kembali ke daftar pengajuan
            </button>
        @endif
            <form wire:submit="{{ $mode === 'tolak' ? 'tolak' : 'setujui' }}" @if ($mode === 'tolak') role="dialog" aria-modal="true" @endif aria-labelledby="aju-judul" novalidate
                  @class(['relative flex max-w-full flex-col rounded-md border border-cream-300 bg-cream-50',
                      'wt-dialog max-h-[94dvh] w-[520px] animate-wt-fade overflow-hidden shadow-wt-lg' => $mode === 'tolak',
                      'w-full shadow-wt-xs' => $mode === 'setuju'])>
                <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-4 py-4 sm:px-6">
                    <div class="min-w-0 flex-1">
                        <div id="aju-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]">{{ $mode === 'tolak' ? 'Tolak' : 'Setujui' }} {{ $r->reservation_code }}</div>
                        <div class="mt-0.5 text-[13px] text-ink-500">{{ ReservasiService::rentang($q->check_in_date, $q->check_out_date) }} · {{ $malam }} malam · {{ $q->room_count }} kamar diajukan{{ $r->pesertaInstansi() ? ' · '.$r->pesertaInstansi().' tamu' : '' }}{{ $q->event_name ? ' · '.$q->event_name : '' }}</div>
                    </div>
                    @if ($mode === 'tolak')
                        <button type="button" wire:click="close" class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup">
                            <x-staf.ikon nama="tutup" ukuran="16" />
                        </button>
                    @endif
                </div>

                <div @class(['flex flex-col gap-5 px-4 py-5 sm:px-6', 'min-h-0 flex-1 overflow-y-auto' => $mode === 'tolak'])>
                    @if ($mode === 'tolak')
                        <x-staf.isian nama="alasan" label="Alasan penolakan" bantuan="Dikirim ke narahubung lewat WhatsApp dan tampil di halaman cek pesanan.">
                            <textarea wire:model="alasan" rows="3" maxlength="200" placeholder="Tuliskan alasan penolakan secara singkat dan jelas" class="wt-input {{ $galat('alasan') }}"></textarea>
                        </x-staf.isian>
                    @else
                        @if ($pola)
                            @include('livewire.staf.partials.kartu-pola-rombongan', $pola)
                        @endif

                        {{-- Instansi: pakai yang terdaftar atau buat baru; tanpa pilihan bawaan bila pengajuan belum tertaut --}}
                        <section data-bagian-instansi>
                            <h3 class="mb-1 mt-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Instansi</h3>
                            @if ($namaTulis)
                                <p class="mb-2 mt-0 text-[13px] text-ink-500">Nama yang ditulis pemohon: <strong class="text-ink-900" data-nama-tulis>“{{ $namaTulis }}”</strong></p>
                            @endif
                            @error('instansi')<p class="mb-2 mt-0 rounded bg-[rgba(212,81,74,.08)] px-3 py-2 text-[13px] text-status-dirty" role="alert">{{ $message }}</p>@enderror

                            <div class="overflow-hidden rounded border border-cream-300">
                                <div class="p-3.5">
                                    <label class="flex cursor-pointer items-center gap-2 text-[13px] font-semibold">
                                        <input type="radio" wire:model.live="pilihan" value="terdaftar" class="accent-brown-800">Pakai instansi yang sudah terdaftar
                                    </label>
                                    @if ($pilihan === 'terdaftar')
                                        <div class="mt-2.5 pl-6">
                                            @if ($instansiTerpilih)
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded bg-cream-100 px-3 py-2 text-[13px]" data-instansi-terpilih>
                                                    <span class="font-semibold">{{ $instansiTerpilih->name }}</span>
                                                    <span class="text-ink-500">{{ $instansiTerpilih->primaryContact ? 'narahubung utama '.$instansiTerpilih->primaryContact->full_name : 'belum ada narahubung utama' }}</span>
                                                    <button type="button" wire:click="gantiInstansi" class="ml-auto border-0 bg-transparent p-0 text-[13px] font-semibold text-clay-600 hover:underline">Ganti</button>
                                                </div>
                                            @else
                                                <input type="search" wire:model.live.debounce.250ms="cariInstansi" placeholder="Ketik nama atau kode instansi" aria-label="Cari instansi terdaftar" class="wt-input h-10">
                                                <div class="mt-1.5 flex max-h-[200px] flex-col overflow-y-auto rounded border border-cream-300" data-hasil-cari-instansi>
                                                    @forelse ($hasilCari as $c)
                                                        <button type="button" wire:key="ci-{{ $c->id }}" wire:click="pakaiInstansi({{ $c->id }})" class="flex flex-wrap items-baseline gap-x-2 border-0 border-t border-cream-300 bg-cream-50 px-3 py-2 text-left text-[13px] first:border-t-0 hover:bg-cream-200">
                                                            <span class="font-semibold">{{ $c->name }}</span>
                                                            <span class="text-[12px] text-ink-500">{{ $c->code ? $c->code.' · ' : '' }}{{ (int) ($menginap[$c->id] ?? 0) }} kali menginap</span>
                                                        </button>
                                                    @empty
                                                        <p class="m-0 px-3 py-2 text-[13px] text-ink-500">Tidak ada instansi yang cocok.</p>
                                                    @endforelse
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                    {{-- Saran hanya petunjuk; tidak dipilih otomatis --}}
                                    @if ($saran->isNotEmpty())
                                        <ul class="m-0 mt-2.5 flex list-none flex-col gap-1.5 p-0 pl-6" data-saran-instansi>
                                            @foreach ($saran as $s)
                                                <li wire:key="sr-{{ $s->id }}" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]">
                                                    <span class="text-ink-500">Mungkin:</span>
                                                    <span class="font-semibold">{{ $s->name }}</span>
                                                    <span class="text-ink-500">· {{ (int) ($menginap[$s->id] ?? 0) }} kali menginap{{ $s->primaryContact ? ' · narahubung utama '.$s->primaryContact->full_name : '' }}</span>
                                                    @if ($instansiTerpilih?->id === $s->id)
                                                        <span class="wt-lencana wt-lencana-aktif">Dipilih</span>
                                                    @else
                                                        <button type="button" wire:click="pakaiInstansi({{ $s->id }})" class="wt-btn-sm h-7 px-2.5 text-xs">Pakai</button>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                                <div class="border-t border-cream-300 p-3.5">
                                    <label class="flex cursor-pointer items-center gap-2 text-[13px] font-semibold">
                                        <input type="radio" wire:model.live="pilihan" value="baru" class="accent-brown-800">Buat instansi baru
                                    </label>
                                    @if ($pilihan === 'baru')
                                        <div class="mt-2.5 grid gap-3 pl-6 sm:grid-cols-2" data-instansi-baru>
                                            <x-staf.isian nama="namaBaru" label="Nama instansi baru" class="sm:col-span-2"><input type="text" wire:model="namaBaru" maxlength="150" class="wt-input {{ $galat('namaBaru') }}"></x-staf.isian>
                                            <x-staf.isian nama="alamatBaru" label="Alamat instansi" class="sm:col-span-2"><textarea wire:model="alamatBaru" rows="2" maxlength="500" class="wt-input {{ $galat('alamatBaru') }}"></textarea></x-staf.isian>
                                            <x-staf.isian nama="teleponBaru" label="Telepon kantor (opsional)"><input type="tel" wire:model="teleponBaru" maxlength="20" inputmode="tel" placeholder="08xxxxxxxxxx" class="wt-input {{ $galat('teleponBaru') }}"></x-staf.isian>
                                            <x-staf.isian nama="emailBaru" label="Email kantor untuk tagihan (opsional)" bantuan="Kosongkan bila tagihan cukup dikirim ke email penanggung jawab."><input type="email" wire:model="emailBaru" maxlength="150" class="wt-input {{ $galat('emailBaru') }}"></x-staf.isian>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Narahubung pemohon: tidak dipindah dari instansi lain atau dijadikan utama tanpa pilihan Manager --}}
                            @if ($narahubung['pemohon'] && $instansi !== '')
                                @php
                                    $b = $narahubung['pemohon'];
                                    $namaTujuan = $instansiTerpilih?->name ?? ($namaBaru ?: 'instansi baru');
                                @endphp
                                <div class="mt-3 flex flex-col gap-2.5 text-[13px]" data-narahubung-pemohon>
                                    @if ($narahubung['lain'])
                                        <div class="rounded border border-[rgba(232,172,69,.5)] bg-[rgba(232,172,69,.12)] px-3.5 py-3" role="status" data-peringatan-narahubung>
                                            <div class="font-semibold">Nomor ini tercatat sebagai narahubung {{ $narahubung['lain']->name }}</div>
                                            <div class="mt-0.5 text-[12px] text-ink-500">{{ $b->full_name }} · {{ $b->phone }}. Reservasi tetap atas nama {{ $b->full_name }}.</div>
                                            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1.5">
                                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="pindahNarahubung" value="pindah" class="accent-brown-800">Pindahkan ke {{ $namaTujuan }}</label>
                                                <label class="flex items-center gap-2"><input type="radio" wire:model.live="pindahNarahubung" value="biarkan" class="accent-brown-800">Biarkan</label>
                                            </div>
                                            @error('pindahNarahubung')<div class="mt-1.5 text-xs font-medium text-status-dirty" role="alert">{{ $message }}</div>@enderror
                                        </div>
                                    @endif
                                    @if ($narahubung['terkait'] && $instansi === 'baru')
                                        <p class="m-0 text-ink-500">{{ $b->full_name }} menjadi narahubung utama instansi baru (tujuan tagihan).</p>
                                    @elseif ($narahubung['terkait'] && $instansiTerpilih?->primary_contact_id === $b->id)
                                        <p class="m-0 text-ink-500">{{ $b->full_name }} sudah narahubung utama {{ $instansiTerpilih->name }}.</p>
                                    @elseif ($narahubung['terkait'] && $instansiTerpilih)
                                        <label class="flex items-start gap-2">
                                            <input type="checkbox" wire:model.live="jadikanUtama" class="mt-0.5 h-4 w-4 flex-none accent-brown-800" data-jadikan-utama>
                                            <span>Jadikan {{ $b->full_name }} narahubung utama (tujuan tagihan)
                                                <span class="block text-[12px] text-ink-500">
                                                    @if (! $instansiTerpilih->primaryContact)
                                                        {{ $instansiTerpilih->name }} belum punya narahubung utama.
                                                    @else
                                                        Narahubung utama sekarang {{ $instansiTerpilih->primaryContact->full_name }}{{ $instansiTerpilih->primaryContact->is_active ? '' : ' (nonaktif)' }}.
                                                    @endif
                                                </span>
                                            </span>
                                        </label>
                                    @endif
                                </div>
                            @endif
                        </section>

                        {{-- Penagihan --}}
                        <section class="grid gap-4 sm:grid-cols-2">
                            <x-staf.isian nama="skema" label="Skema penagihan kamar" tag="div">
                                <x-staf.pilih model="$wire.skema" x-on:change="$wire.$commit()" label="Skema penagihan" :nilai="$skema" :pilihan="Institution::SKEMA" />
                            </x-staf.isian>
                            <x-staf.isian nama="skemaLayanan" label="Tagihan layanan (makanan, laundry)" tag="div">
                                <x-staf.pilih model="$wire.skemaLayanan" label="Skema layanan" :nilai="$skemaLayanan" :pilihan="Reservation::SKEMA_LAYANAN" />
                            </x-staf.isian>
                            {{-- Instansi terdaftar: terisi dari data instansi; perubahan ikut disimpan ke instansi itu --}}
                            @php $bantuanTagih = $instansiTerpilih ? 'Berlaku juga untuk tagihan instansi ini berikutnya.' : null; @endphp
                            @if ($instansi !== '' && $skema === 'monthly_fixed_date')
                                <x-staf.isian nama="tanggalTagih" label="Tanggal tagih tiap bulan" :bantuan="$bantuanTagih"><input type="number" min="1" max="31" wire:model="tanggalTagih" class="wt-input {{ $galat('tanggalTagih') }}"></x-staf.isian>
                            @elseif ($instansi !== '' && $skema === 'due_after_checkout')
                                <x-staf.isian nama="jatuhTempo" label="Jatuh tempo (hari setelah check-out)" :bantuan="$bantuanTagih"><input type="number" min="1" max="365" wire:model="jatuhTempo" class="wt-input {{ $galat('jatuhTempo') }}"></x-staf.isian>
                            @endif
                        </section>

                        {{-- Kamar: hanya kamar bebas di tanggal pengajuan, per tipe lalu per jenis kasur --}}
                        <section data-tetapkan-kamar>
                            <div class="mb-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <h3 class="m-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Tetapkan kamar</h3>
                                <span @class(['text-[13px] font-semibold tabular-nums',
                                    'text-status-available' => $selisih === 0,
                                    'text-[#A87412]' => $selisih < 0,
                                    'text-[#C2410C]' => $selisih > 0]) data-penghitung-kamar>
                                    · {{ count($kamar) }} dari {{ $q->room_count }} diajukan{{ $selisih < 0 ? ' · kurang '.abs($selisih).' kamar' : ($selisih > 0 ? ' · lebih '.$selisih.' dari pengajuan' : '') }}
                                </span>
                                @error('kamar')<span class="w-full text-xs font-medium text-status-dirty" role="alert">{{ $message }}</span>@enderror
                            </div>

                            @if ($kasurAda->count() > 1)
                                <div class="mb-2.5 flex flex-wrap gap-1.5" role="group" aria-label="Saring jenis kasur" data-saring-kasur>
                                    @foreach (['' => 'Semua'] + $kasurAda->mapWithKeys(fn ($b) => [$b => Room::labelKasur($b)])->all() as $kode => $label)
                                        <button type="button" wire:click="$set('saringKasur', '{{ $kode }}')" aria-pressed="{{ $saringKasur === $kode ? 'true' : 'false' }}"
                                                @class(['h-8 rounded-full border px-3 text-xs font-semibold transition',
                                                    'border-brown-800 bg-brown-800 text-cream-50' => $saringKasur === $kode,
                                                    'border-cream-300 bg-cream-50 text-brown-800 hover:bg-cream-200' => $saringKasur !== $kode])>{{ $label }}</button>
                                    @endforeach
                                </div>
                            @endif

                            <div class="flex flex-col gap-3 rounded border border-cream-300 p-3">
                                @foreach ($tipe as $t)
                                    @php
                                        $semua = $kamarPerTipe->get($t->id, collect());
                                        $bebas = $semua->where('terpakai', false)->count();
                                        $tersedia = min($bebas, $sisa[$t->id] ?? 0);
                                        $tampil = $saringKasur === '' ? $semua : $semua->where('bed_type', $saringKasur);
                                    @endphp
                                    @continue($saringKasur !== '' && $tampil->isEmpty())
                                    <div wire:key="t-{{ $t->id }}" @class(['opacity-55' => $semua->isEmpty()]) data-tipe-kamar="{{ $t->code }}">
                                        <div class="mb-1.5 text-[12px]"><span class="font-semibold">{{ $t->name }}</span>
                                            <span class="text-ink-500">· {{ $rp($t->base_price) }} · {{ $semua->isEmpty() ? 'Penuh di tanggal ini' : $tersedia.' tersedia di tanggal ini' }}</span>
                                        </div>
                                        @foreach ($tampil->groupBy('bed_type') as $kasur => $daftarKamar)
                                            <div class="mb-1.5 flex flex-wrap gap-1.5 last:mb-0" data-kelompok-kasur="{{ $kasur }}">
                                                @foreach ($daftarKamar as $k)
                                                    <label wire:key="k-{{ $k->id }}" title="{{ $k->terpakai ? 'Kamar ini sudah terpakai reservasi lain' : '' }}"
                                                           @class(['flex min-h-[46px] min-w-[84px] cursor-pointer flex-col items-center justify-center rounded border px-2.5 py-1 text-center leading-tight hover:border-brown-800 has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-50',
                                                               'border-cream-300 bg-cream-50' => ! $k->terpakai, 'border-status-dirty bg-[rgba(212,81,74,.08)]' => $k->terpakai])
                                                           data-kamar-bebas="{{ $k->room_number }}">
                                                        <input type="checkbox" wire:model.live="kamar" value="{{ $k->id }}" class="sr-only">
                                                        <span class="text-[13px] font-semibold tabular-nums">{{ $k->room_number }}</span>
                                                        <span class="text-[10.5px] opacity-75">{{ $k->terpakai ? 'terpakai' : Room::labelKasur($k->bed_type) }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- Potongan: bersama untuk semua kamar, lalu per kamar (ringkas, dibuka saat diubah) --}}
                        @if ($kamar)
                            <section class="rounded-md border border-cream-300 bg-cream-100 p-3.5" data-potongan-bersama>
                                <h3 class="mb-2 mt-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Potongan untuk semua kamar</h3>
                                <div class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center">
                                    <div class="inline-flex flex-wrap self-start rounded-md border border-cream-300 bg-cream-50 p-0.5" role="radiogroup" aria-label="Jenis potongan untuk semua kamar">
                                        @foreach ($labelPotongan as $nilaiJenis => $labelJenis)
                                            <button type="button" wire:click="jenisBersama('{{ $nilaiJenis }}')" role="radio" aria-checked="{{ $potonganBersama['jenis'] === $nilaiJenis ? 'true' : 'false' }}"
                                                    @class(['h-8 rounded border-0 px-3 text-xs font-semibold transition',
                                                        'bg-brown-800 text-cream-50 shadow-wt-xs' => $potonganBersama['jenis'] === $nilaiJenis,
                                                        'bg-transparent text-ink-500 hover:text-ink-900' => $potonganBersama['jenis'] !== $nilaiJenis])>{{ $labelJenis }}</button>
                                        @endforeach
                                    </div>
                                    @if (in_array($potonganBersama['jenis'], ['percent', 'amount'], true))
                                        <label class="flex items-center gap-2 text-xs text-ink-500">
                                            <span class="relative">
                                                @if ($potonganBersama['jenis'] === 'amount')<span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">Rp</span>@endif
                                                <input type="number" min="0" @if ($potonganBersama['jenis'] === 'percent') max="100" step="0.5" @else step="1000" @endif wire:model.live.debounce.400ms="potonganBersama.nilai"
                                                       placeholder="{{ $potonganBersama['jenis'] === 'percent' ? '10' : '50000' }}" aria-label="Besar potongan untuk semua kamar"
                                                       class="wt-input h-9 w-[140px] tabular-nums {{ $potonganBersama['jenis'] === 'amount' ? 'pl-9' : 'pr-8' }} {{ $galat('potonganBersama.nilai') }}">
                                                @if ($potonganBersama['jenis'] === 'percent')<span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">%</span>@endif
                                            </span>
                                            {{ $potonganBersama['jenis'] === 'percent' ? 'dari harga kamar' : 'per kamar per malam' }}
                                        </label>
                                    @endif
                                    <button type="button" wire:click="terapkanBersama" @if ($nSendiri > 0) wire:confirm="Timpa potongan {{ $nSendiri }} kamar yang diatur sendiri?" @endif
                                            class="h-9 self-start rounded border-0 bg-brown-800 px-4 text-xs font-semibold text-cream-50 hover:bg-brown-900 sm:ml-auto" data-terapkan-semua>Terapkan ke semua kamar</button>
                                </div>
                                @error('potonganBersama.nilai')<div class="mt-1.5 text-xs font-medium text-status-dirty" role="alert">{{ $message }}</div>@enderror
                                @if ($bersamaAktif)
                                    <p class="mb-0 mt-2 text-[12px] text-ink-500">Aktif: kamar yang dipilih berikutnya ikut memakai potongan ini.{{ $nSendiri > 0 ? ' '.$nSendiri.' kamar diatur sendiri.' : '' }}</p>
                                @endif
                            </section>

                            <section>
                                <h3 class="mb-1 mt-0 font-display text-[13px] font-bold uppercase tracking-[.06em]">Harga dan potongan</h3>
                                <p class="mb-2.5 mt-0 text-xs text-ink-500">Harga per malam mengikuti harga kamar dan tidak bisa diubah. Klik baris kamar untuk mengubah potongannya sendiri.</p>
                                <div class="flex flex-col gap-3">
                                    @foreach (collect($kamar)->groupBy(fn ($id) => $infoKamar->get((int) $id)?->room_type_id)->sortBy(fn ($g, $tid) => $tipe[$tid]?->base_price) as $tid => $idTipe)
                                        <div wire:key="ht-{{ $tid }}">
                                            <div class="mb-1 text-[12px] font-semibold text-ink-500">{{ $tipe[$tid]?->name }}</div>
                                            <div class="overflow-hidden rounded-md border border-cream-300">
                                                @foreach ($idTipe->sortBy(fn ($id) => [strlen((string) $infoKamar->get((int) $id)?->room_number), $infoKamar->get((int) $id)?->room_number]) as $id)
                                                    @php
                                                        $room = $infoKamar->get((int) $id);
                                                        $jenis = $harga[$id]['jenis'] ?? '';
                                                        $h = $hitung[$id] ?? ['kotor' => 0, 'potong' => 0, 'bersih' => 0];
                                                        $buka = $bukaPotongan === (string) $id;
                                                    @endphp
                                                    <div wire:key="h-{{ $id }}" class="border-t border-cream-300 first:border-t-0" data-baris-kamar="{{ $room?->room_number }}">
                                                        <button type="button" wire:click="ubahPotongan('{{ $id }}')" aria-expanded="{{ $buka ? 'true' : 'false' }}"
                                                                class="flex w-full flex-wrap items-center gap-x-3 gap-y-0.5 border-0 bg-cream-50 px-3 py-2.5 text-left text-[13px] hover:bg-cream-100">
                                                            <span class="font-display font-extrabold tabular-nums">{{ $room?->room_number }}</span>
                                                            <span class="text-ink-500">{{ Room::labelKasur($room?->bed_type) }}</span>
                                                            <span class="tabular-nums text-ink-500">{{ $rp($harga[$id]['harga'] ?? 0) }} × {{ $malam }}</span>
                                                            <span @class(['text-xs', 'text-status-available' => $h['potong'] > 0, 'text-ink-500' => $h['potong'] <= 0])>{{ $ringkasPotongan($harga[$id] ?? []) }}</span>
                                                            @if ($harga[$id]['sendiri'] ?? false)<span class="wt-lencana wt-lencana-netral text-[10.5px]" data-diatur-sendiri>diatur sendiri</span>@endif
                                                            <span class="ml-auto flex items-baseline gap-2 tabular-nums">
                                                                @if ($h['potong'] > 0)<span class="text-xs text-ink-500 line-through">{{ $rp($h['kotor']) }}</span>@endif
                                                                <span class="font-semibold">{{ $jenis === 'free' ? 'Gratis' : $rp($h['bersih']) }}</span>
                                                            </span>
                                                            <span class="w-full text-[11px] font-semibold text-clay-600 sm:w-auto">{{ $buka ? 'Tutup' : 'Ubah potongan' }}</span>
                                                        </button>
                                                        @if ($buka)
                                                            <div class="flex flex-wrap items-center gap-3 bg-cream-100 px-3 py-3">
                                                                <div class="inline-flex flex-wrap rounded-md border border-cream-300 bg-cream-50 p-0.5" role="radiogroup" aria-label="Jenis potongan kamar {{ $room?->room_number }}">
                                                                    @foreach ($labelPotongan as $nilaiJenis => $labelJenis)
                                                                        <button type="button" wire:click="jenisPotongan('{{ $id }}', '{{ $nilaiJenis }}')" role="radio" aria-checked="{{ $jenis === $nilaiJenis ? 'true' : 'false' }}"
                                                                                @class(['h-8 rounded border-0 px-3 text-xs font-semibold transition',
                                                                                    'bg-brown-800 text-cream-50 shadow-wt-xs' => $jenis === $nilaiJenis,
                                                                                    'bg-transparent text-ink-500 hover:text-ink-900' => $jenis !== $nilaiJenis])>{{ $labelJenis }}</button>
                                                                    @endforeach
                                                                </div>
                                                                @if (in_array($jenis, ['percent', 'amount'], true))
                                                                    <label class="flex flex-wrap items-center gap-2 text-xs text-ink-500">
                                                                        <span class="relative">
                                                                            @if ($jenis === 'amount')<span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">Rp</span>@endif
                                                                            <input type="number" min="0" @if ($jenis === 'percent') max="100" step="0.5" @else step="1000" @endif wire:model.live.debounce.400ms="harga.{{ $id }}.nilai"
                                                                                   placeholder="{{ $jenis === 'percent' ? '15' : '50000' }}"
                                                                                   class="wt-input h-9 w-[140px] tabular-nums {{ $jenis === 'amount' ? 'pl-9' : 'pr-8' }} {{ $galat('harga.'.$id.'.nilai') }}" aria-label="Besar potongan kamar {{ $room?->room_number }}">
                                                                            @if ($jenis === 'percent')<span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">%</span>@endif
                                                                        </span>
                                                                        <span>{{ $jenis === 'percent' ? 'dari total '.$malam.' malam' : 'per malam' }}</span>
                                                                    </label>
                                                                @elseif ($jenis === 'free')
                                                                    <span class="text-xs text-ink-500">Kamar ini tidak ditagihkan (harga Rp0).</span>
                                                                @endif
                                                            </div>
                                                        @endif
                                                        @error('harga.'.$id.'.nilai')<div class="bg-cream-50 px-3 pb-2 text-xs text-status-dirty" role="alert">Kamar {{ $room?->room_number }}: {{ $message }}</div>@enderror
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        <x-staf.isian nama="catatan" label="Catatan persetujuan (opsional)">
                            <input type="text" wire:model="catatan" maxlength="200" placeholder="Catatan tambahan untuk pengajuan ini" class="wt-input">
                        </x-staf.isian>
                    @endif
                    @error('status')<p class="m-0 text-[13px] text-status-dirty">{{ $message }}</p>@enderror
                </div>

                {{-- Panel setujui: ringkasan dan tombol menempel di bawah layar saat halaman digulir --}}
                <div @class(['flex-none border-t border-cream-300 px-4 py-3 sm:px-6 sm:py-4',
                    'sticky bottom-0 z-10 rounded-b-[inherit] bg-cream-50 shadow-[0_-8px_18px_-12px_rgba(62,42,28,.28)]' => $mode === 'setuju'])>
                    {{-- Ringkasan tagihan kamar, ikut berubah langsung --}}
                    @if ($mode === 'setuju' && $kamar)
                        <dl class="m-0 mb-3 grid grid-cols-2 gap-x-4 gap-y-1 text-[13px] sm:grid-cols-4" data-ringkasan-tagihan>
                            <div><dt class="text-[11px] text-ink-500">Kamar</dt><dd class="m-0 font-semibold tabular-nums">{{ count($kamar) }} kamar · {{ $malam }} malam</dd></div>
                            <div><dt class="text-[11px] text-ink-500">Subtotal</dt><dd class="m-0 font-semibold tabular-nums">{{ $rp($hitung->sum('kotor')) }}</dd></div>
                            <div><dt class="text-[11px] text-ink-500">Total potongan</dt><dd class="m-0 font-semibold tabular-nums text-status-available">{{ $hitung->sum('potong') > 0 ? '−'.$rp($hitung->sum('potong')) : $rp(0) }}</dd></div>
                            <div><dt class="text-[11px] text-ink-500">Total tagihan kamar</dt><dd class="m-0 font-display text-base font-extrabold tabular-nums" data-total-tagihan>{{ $rp($total) }}</dd></div>
                        </dl>
                    @endif
                    <div class="flex gap-2">
                        <button type="submit" wire:loading.attr="disabled" @class(['h-11 flex-1 rounded border-0 font-display text-[13px] font-bold text-cream-50 disabled:opacity-70',
                            'bg-status-dirty hover:opacity-90' => $mode === 'tolak', 'bg-clay-500 hover:bg-clay-600' => $mode !== 'tolak'])>
                            {{ $mode === 'tolak' ? 'Tolak pengajuan' : 'Setujui dan tetapkan kamar' }}
                        </button>
                        <button type="button" wire:click="close" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                    </div>
                </div>
            </form>
        </div>
    @endif
</div>
