@php
    $baris = $jadwal->values()->map(fn ($j) => [
        'nama' => $j->facility->name.' '.$j->area_label,
        'cari' => $j->facility->name.' '.$j->area_label.' '.$j->vendor_name,
        'isi' => [
            'facility_id' => (string) $j->facility_id, 'area_label' => $j->area_label, 'interval_months' => (string) $j->interval_months,
            'next_due_date' => $j->next_due_date->toDateString(), 'vendor_name' => $j->vendor_name ?? '', 'note' => $j->note ?? '',
        ],
        'url' => route('staf.maintenance.jadwal.update', $j),
        'urlAktif' => route('staf.maintenance.jadwal.toggle', $j),
        'pesanNonaktif' => "Jadwal {$j->facility->name} {$j->area_label} tidak lagi diingatkan dan tidak ditandai terlambat. Riwayat perawatannya tetap tersimpan.",
    ]);
    $kosong = ['facility_id' => '', 'area_label' => '', 'interval_months' => '6', 'next_due_date' => today()->addMonths(6)->toDateString(), 'vendor_name' => '', 'note' => ''];
    $lewat = $jadwal->filter(fn ($j) => $j->is_active && $j->penanda() === 'lewat')->count();
    $segera = $jadwal->filter(fn ($j) => $j->is_active && $j->penanda() === 'segera')->count();
@endphp

<x-layouts.staf title="Jadwal Perawatan Rutin">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.maintenance.jadwal.store')]))">
    <div class="mb-4 grid grid-cols-3 gap-3 sm:max-w-[600px]">
        <div class="wt-card px-4 py-3"><div class="font-display text-2xl font-extrabold tabular-nums {{ $lewat ? 'text-status-dirty' : '' }}">{{ $lewat }}</div><div class="text-[12px] text-ink-500">Terlambat dirawat</div></div>
        <div class="wt-card px-4 py-3"><div class="font-display text-2xl font-extrabold tabular-nums {{ $segera ? 'text-[#8A5E10]' : '' }}">{{ $segera }}</div><div class="text-[12px] text-ink-500">Perlu dirawat minggu ini</div></div>
        <div class="wt-card px-4 py-3"><div class="font-display text-2xl font-extrabold tabular-nums">{{ $jadwal->where('is_active', true)->count() }}</div><div class="text-[12px] text-ink-500">Jadwal aktif</div></div>
    </div>
    <p class="-mt-1 mb-4 text-[12px] text-ink-500">Setiap pagi pukul 07.00 WITA, Manager menerima pengingat lewat WhatsApp dan email untuk perawatan yang sudah terlambat atau harus dilakukan dalam {{ \App\Models\MaintenanceSchedule::HARI_SEGERA }} hari ke depan. Pengingat berhenti setelah perawatannya dicatat atau jadwalnya dinonaktifkan.</p>

    <x-staf.toolbar tambah="Tambah jadwal" cari="Cari fasilitas, area, atau vendor" />

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[980px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Fasilitas</th>
                    <th class="wt-th">Area</th>
                    <th class="wt-th">Interval</th>
                    <th class="wt-th">Terakhir dirawat</th>
                    <th class="wt-th">Perawatan berikutnya</th>
                    <th class="wt-th">Vendor</th>
                    <th class="wt-th">Status</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($jadwal->values() as $i => $j)
                    @php $penanda = $j->penanda(); $unit = $jumlahUnit[$j->facility_id.'|'.$j->area_label] ?? 0; @endphp
                    <tr x-show="cocok(baris[{{ $i }}])" @class(['hover:bg-row-hover', 'opacity-55' => ! $j->is_active, 'bg-[rgba(212,81,74,.04)]' => $j->is_active && $penanda === 'lewat'])>
                        <td class="wt-td font-semibold"><a href="{{ route('staf.maintenance.riwayat', ['area' => $j->area_label, 'jenis' => 'preventive']) }}" class="text-ink-900 hover:text-clay-600" title="Riwayat perawatan rutin {{ $j->facility->name }} {{ $j->area_label }}">{{ $j->facility->name }}</a><div class="text-[11px] font-normal text-ink-500">{{ $unit }} unit tercakup</div></td>
                        <td class="wt-td whitespace-nowrap">{{ $j->area_label }}</td>
                        <td class="wt-td whitespace-nowrap">Tiap {{ $j->interval_months }} bulan</td>
                        <td class="wt-td whitespace-nowrap text-ink-500">{{ $j->last_maintenance_date?->translatedFormat('d M Y') ?? 'Belum pernah' }}</td>
                        <td class="wt-td whitespace-nowrap">
                            <div class="tabular-nums">{{ $j->next_due_date->translatedFormat('d M Y') }}</div>
                            @if ($j->is_active && $penanda === 'lewat')
                                <span class="wt-lencana mt-1 bg-[rgba(212,81,74,.12)] text-status-dirty">Terlambat {{ $j->hariLewat() }} hari</span>
                            @elseif ($j->is_active && $penanda === 'segera')
                                <span class="wt-lencana mt-1 bg-[rgba(232,172,69,.18)] text-[#7A520C]">{{ ($sisa = (int) today()->diffInDays($j->next_due_date)) === 0 ? 'Hari ini' : "{$sisa} hari lagi" }}</span>
                            @endif
                        </td>
                        <td class="wt-td max-w-[160px] text-ink-500">{{ $j->vendor_name ?: '—' }}</td>
                        <td class="wt-td"><x-staf.status :aktif="$j->is_active" /></td>
                        <td class="wt-td">
                            <div class="flex items-center justify-end gap-2">
                                @if ($j->is_active)
                                    <button type="button" class="wt-btn-sm whitespace-nowrap !border-clay-500 !text-clay-600"
                                            title="Catat perawatan yang sudah dilakukan" @click="$dispatch('catat-pelaksanaan', @js(['url' => route('staf.maintenance.jadwal.laksanakan', $j), 'nama' => $j->facility->name.' '.$j->area_label, 'interval' => $j->interval_months, 'vendor' => $j->vendor_name ?? '']))">Catat perawatan</button>
                                @endif
                                <x-staf.aksi :i="$i" :aktif="$j->is_active" />
                            </div>
                        </td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="8" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada jadwal yang cocok.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-ink-500">Perawatan yang terlambat tampil paling atas. Klik nama fasilitas untuk melihat riwayat perawatannya. Jadwal tidak dihapus, cukup dinonaktifkan.</p>

    <x-staf.panel nama="jadwal">
        <x-staf.field nama="facility_id" label="Fasilitas" tag="div">
            <x-staf.pilih name="facility_id" model="isi.facility_id" label="Fasilitas" galat="pesan('facility_id')" :pilihan="['' => 'Pilih fasilitas'] + $fasilitas->all()" />
        </x-staf.field>
        <x-staf.field nama="area_label" label="Area" tag="div">
            <x-staf.pilih name="area_label" model="isi.area_label" label="Area" galat="pesan('area_label')" :pilihan="['' => 'Pilih area'] + collect($area)->mapWithKeys(fn ($a) => [$a => $a])->all()" />
        </x-staf.field>
        <div class="grid grid-cols-2 gap-4">
            <x-staf.field nama="interval_months" label="Interval (bulan)">
                <input type="number" name="interval_months" x-model="isi.interval_months" @input="hapus('interval_months')" min="1" max="24" class="wt-input" :class="pesan('interval_months') && 'is-invalid'">
            </x-staf.field>
            <x-staf.field nama="next_due_date" label="Tanggal perawatan pertama" label-ubah="Perawatan berikutnya" bantuan="Tanggal perawatan berikutnya dihitung otomatis dari tanggal perawatan terakhir ditambah interval.">
                <input type="date" name="next_due_date" x-model="isi.next_due_date" @input="hapus('next_due_date')" class="wt-input" :class="pesan('next_due_date') && 'is-invalid'">
            </x-staf.field>
        </div>
        <x-staf.field nama="vendor_name" label="Vendor langganan (opsional)">
            <input type="text" name="vendor_name" x-model="isi.vendor_name" @input="hapus('vendor_name')" maxlength="100" class="wt-input" :class="pesan('vendor_name') && 'is-invalid'">
        </x-staf.field>
        <x-staf.field nama="note" label="Catatan (opsional)">
            <textarea name="note" x-model="isi.note" @input="hapus('note')" rows="2" maxlength="255" class="wt-input" :class="pesan('note') && 'is-invalid'"></textarea>
        </x-staf.field>
    </x-staf.panel>

    {{-- Catat perawatan rutin yang sudah dilakukan --}}
    <div x-data="formStaf({ j: null, pengerja: 'vendor' })" @catat-pelaksanaan.window="j = $event.detail; pengerja = j.vendor ? 'vendor' : 'staf'; galat = {}"
         @keydown.escape.window="j && !proses && (j = null)">
        <template x-if="j">
            <div class="fixed inset-0 z-[350] flex items-center justify-center p-4">
                <div @click="!proses && (j = null)" class="absolute inset-0 bg-[rgba(62,42,28,.44)]"></div>
                <form :action="j.url" @submit.prevent="kirim($el)" novalidate role="dialog" aria-modal="true" aria-labelledby="laksana-judul"
                      class="relative flex max-h-[92vh] w-[500px] max-w-full animate-wt-fade flex-col overflow-hidden rounded-md border border-cream-300 bg-cream-50 shadow-wt-lg">
                    @csrf
                    <div class="flex-none border-b border-cream-300 px-6 py-4">
                        <div id="laksana-judul" class="font-display text-[17px] font-extrabold uppercase tracking-[.04em]" x-text="'Catat perawatan ' + j.nama"></div>
                        <div class="mt-0.5 text-[13px] text-ink-500" x-text="'Perawatan berikutnya dijadwalkan ' + j.interval + ' bulan setelah tanggal perawatan ini'"></div>
                    </div>
                    <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                        <x-staf.field nama="tanggal" label="Tanggal perawatan">
                            <input type="date" name="tanggal" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="wt-input" :class="pesan('tanggal') && 'is-invalid'" @input="hapus('tanggal')">
                        </x-staf.field>
                        <x-staf.field nama="pengerja" label="Dikerjakan oleh" tag="div">
                            <input type="hidden" name="pengerja" :value="pengerja">
                            <div class="inline-flex rounded-md border border-cream-300 bg-cream-100 p-0.5">
                                @foreach (['vendor' => 'Vendor luar', 'staf' => 'Staf internal'] as $v => $l)
                                    <button type="button" @click="pengerja = '{{ $v }}'" :class="pengerja === '{{ $v }}' ? 'bg-brown-800 text-cream-50' : 'bg-transparent text-ink-500'" class="h-8 rounded border-0 px-3.5 text-[13px] font-semibold">{{ $l }}</button>
                                @endforeach
                            </div>
                        </x-staf.field>
                        <x-staf.field nama="vendor_name" label="Nama vendor" x-show="pengerja === 'vendor'">
                            <input type="text" name="vendor_name" :value="j.vendor" maxlength="100" class="wt-input" :class="pesan('vendor_name') && 'is-invalid'" @input="hapus('vendor_name')">
                        </x-staf.field>
                        <x-staf.field nama="handled_by" label="Staf" tag="div" x-show="pengerja === 'staf'">
                            <select name="handled_by" class="wt-input" :class="pesan('handled_by') && 'is-invalid'" @change="hapus('handled_by')">
                                <option value="">Pilih staf</option>
                                @foreach ($staf as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </select>
                        </x-staf.field>
                        <x-staf.field nama="cost" label="Biaya (opsional)">
                            <span class="relative block">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-ink-500">Rp</span>
                                <input type="text" name="cost" inputmode="numeric" placeholder="1.200.000" class="wt-input pl-9 tabular-nums" :class="pesan('cost') && 'is-invalid'" @input="hapus('cost')">
                            </span>
                        </x-staf.field>
                        <x-staf.field nama="catatan" label="Catatan (opsional)">
                            <textarea name="catatan" rows="2" maxlength="1000" class="wt-input" placeholder="Contoh: 12 unit AC dicuci, 1 unit perlu ganti kapasitor."></textarea>
                        </x-staf.field>
                    </div>
                    <div class="flex flex-none gap-2 border-t border-cream-300 px-6 py-4">
                        <button type="submit" :disabled="proses" :aria-busy="proses" class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:opacity-70">Simpan perawatan</button>
                        <button type="button" @click="j = null" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
                    </div>
                </form>
            </div>
        </template>
    </div>
</div>
</x-layouts.staf>
