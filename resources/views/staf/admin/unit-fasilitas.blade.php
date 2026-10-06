@php
    $baris = $unit->values()->map(fn ($u) => [
        'nama' => $u->unit_label,
        'cari' => implode(' ', [$u->unit_label, $u->facility?->name, $u->area_label, $u->room?->room_number]),
        'kelompok' => (string) $u->facility_id,
        'isi' => [
            'facility_id' => (string) $u->facility_id, 'unit_label' => $u->unit_label, 'area_label' => $u->area_label,
            'room_id' => $u->room_id ? (string) $u->room_id : '', 'installed_at' => $u->installed_at?->toDateString() ?? '',
        ],
        'url' => route('staf.unit-fasilitas.update', $u),
        'urlAktif' => route('staf.unit-fasilitas.toggle', $u),
        'pesanNonaktif' => "Unit {$u->unit_label} tidak dijadwalkan perawatannya lagi. Riwayat perawatannya tetap tersimpan.",
    ]);
    $lantaiKamar = $kamar->mapWithKeys(fn ($k) => [$k->id => 'Lantai '.$k->floor]);
    $kosong = ['facility_id' => (string) $fasilitas->firstWhere('is_maintainable', true)?->id, 'unit_label' => '', 'area_label' => '', 'room_id' => '', 'installed_at' => ''];
@endphp

<x-layouts.staf title="Unit Fasilitas">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.unit-fasilitas.store')]))">
    <x-staf.toolbar tambah="Tambah unit" cari="Cari label unit, area, atau kamar">
        <x-staf.pilih model="saring" label="Saring fasilitas" class="w-[190px]" :pilihan="['' => 'Semua fasilitas'] + $fasilitas->pluck('name', 'id')->all()" />
    </x-staf.toolbar>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[900px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Label unit</th>
                    <th class="wt-th">Fasilitas</th>
                    <th class="wt-th">Area</th>
                    <th class="wt-th">Kamar</th>
                    <th class="wt-th">Tanggal pasang</th>
                    <th class="wt-th">Status</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($unit->values() as $i => $u)
                    <tr x-show="cocok(baris[{{ $i }}])" @class(['hover:bg-row-hover', 'opacity-55' => ! $u->is_active])>
                        <td class="wt-td font-semibold">{{ $u->unit_label }}</td>
                        <td class="wt-td">{{ $u->facility?->name }}</td>
                        <td class="wt-td">{{ $u->area_label }}</td>
                        <td class="wt-td tabular-nums">{{ $u->room?->room_number ?? '—' }}</td>
                        <td class="wt-td tabular-nums">{{ $u->installed_at?->translatedFormat('j M Y') ?? '—' }}</td>
                        <td class="wt-td"><x-staf.status :aktif="$u->is_active" /></td>
                        <td class="wt-td"><x-staf.aksi :i="$i" :aktif="$u->is_active" /></td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="7" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada unit yang cocok dengan pencarian.</td></tr>
            </tbody>
        </table>
    </div>

    <x-staf.panel nama="unit fasilitas">
        <x-staf.field nama="facility_id" label="Fasilitas" tag="div">
            <x-staf.pilih name="facility_id" model="isi.facility_id" x-on:change="hapus('facility_id')" galat="pesan('facility_id')" label="Fasilitas"
                          :pilihan="$fasilitas->pluck('name', 'id')->all()" />
        </x-staf.field>
        <div class="grid grid-cols-2 gap-4">
            <x-staf.field nama="unit_label" label="Label unit">
                <input type="text" name="unit_label" x-model="isi.unit_label" @input="hapus('unit_label')" maxlength="50" placeholder="Contoh: AC-101" class="wt-input" :class="pesan('unit_label') && 'is-invalid'">
            </x-staf.field>
            {{-- Unit di kamar: area mengikuti lantai kamar dan tidak bisa diketik --}}
            <div x-data="{ lantai: @js($lantaiKamar) }" x-effect="if (isi.room_id) isi.area_label = lantai[isi.room_id] ?? isi.area_label">
                <x-staf.field nama="area_label" label="Area">
                    <input type="text" name="area_label" x-model="isi.area_label" @input="hapus('area_label')" maxlength="50" placeholder="Contoh: Aula" class="wt-input read-only:bg-cream-100 read-only:text-ink-500"
                           :readonly="!!isi.room_id" :title="isi.room_id ? 'Mengikuti lantai kamar' : ''" :class="pesan('area_label') && 'is-invalid'">
                </x-staf.field>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <x-staf.field nama="room_id" label="Kamar" tag="div">
                <x-staf.pilih name="room_id" model="isi.room_id" x-on:change="hapus('room_id')" galat="pesan('room_id')" label="Kamar"
                              :pilihan="['' => 'Bukan di kamar'] + $kamar->mapWithKeys(fn ($k) => [$k->id => 'Kamar '.$k->room_number])->all()" />
            </x-staf.field>
            <x-staf.field nama="installed_at" label="Tanggal pasang">
                <input type="date" name="installed_at" x-model="isi.installed_at" @input="hapus('installed_at')" max="{{ now()->toDateString() }}" class="wt-input" :class="pesan('installed_at') && 'is-invalid'">
            </x-staf.field>
        </div>
        <p class="m-0 text-xs text-ink-500">Label unit harus unik, misalnya AC-101 untuk AC di kamar 101. Unit di kamar otomatis masuk area lantai kamarnya (kamar 201 → Lantai 2), sehingga tercakup jadwal perawatan rutin lantai itu.</p>
    </x-staf.panel>
</div>
</x-layouts.staf>
