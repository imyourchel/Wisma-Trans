@php
    use App\Http\Controllers\Staf\Admin\MasterController;

    $baris = $aula->values()->map(fn ($a) => [
        'nama' => $a->name,
        'cari' => $a->name.' '.$a->description,
        'isi' => [
            'name' => $a->name, 'capacity' => $a->capacity, 'description' => $a->description ?? '',
            'foto' => array_map(fn ($f) => $f + ['jenis' => 'lama'], MasterController::daftarFoto($a->photos)),
        ],
        'url' => route('staf.aula.update', $a),
        'urlAktif' => route('staf.aula.toggle', $a),
        'pesanNonaktif' => "{$a->name} tidak dapat dipesan lagi sampai diaktifkan kembali. Pemesanan lama tetap tersimpan.",
    ]);
    $kosong = ['name' => '', 'capacity' => '', 'description' => '', 'foto' => []];
@endphp

<x-layouts.staf title="Aula">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.aula.store')]))">
    <x-staf.toolbar tambah="Tambah aula" cari="Cari aula" />

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Foto</th>
                    <th class="wt-th">Nama</th>
                    <th class="wt-th">Kapasitas</th>
                    <th class="wt-th">Deskripsi</th>
                    <th class="wt-th">Status</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($aula->values() as $i => $a)
                    @php $foto = MasterController::daftarFoto($a->photos); $utama = collect($foto)->firstWhere('url', '!=', null); @endphp
                    <tr x-show="cocok(baris[{{ $i }}])" @class(['hover:bg-row-hover', 'opacity-55' => ! $a->is_active])>
                        <td class="wt-td">
                            <div class="h-11 w-16 overflow-hidden rounded bg-cream-200">
                                @if ($utama)
                                    <img src="{{ $utama['url'] }}" alt="" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full items-center justify-center text-ink-500"><x-staf.ikon nama="foto" ukuran="16" /></div>
                                @endif
                            </div>
                            <div class="mt-1 text-[11px] text-ink-500">{{ count($foto) }} foto</div>
                        </td>
                        <td class="wt-td font-semibold">{{ $a->name }}</td>
                        <td class="wt-td tabular-nums">{{ $a->capacity }} orang</td>
                        <td class="wt-td max-w-[420px] text-ink-500">{{ $a->description ?: '—' }}</td>
                        <td class="wt-td"><x-staf.status :aktif="$a->is_active" /></td>
                        <td class="wt-td"><x-staf.aksi :i="$i" :aktif="$a->is_active" /></td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="6" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada aula yang cocok dengan pencarian.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-ink-500">Tarif aula tidak ditampilkan kepada tamu. Harga disepakati saat pengajuan sewa diproses.</p>

    <x-staf.panel nama="aula" lebar="w-[520px]">
        <div class="grid grid-cols-[1fr_140px] gap-4">
            <x-staf.field nama="name" label="Nama">
                <input type="text" name="name" x-model="isi.name" @input="hapus('name')" maxlength="80" class="wt-input" :class="pesan('name') && 'is-invalid'">
            </x-staf.field>
            <x-staf.field nama="capacity" label="Kapasitas (orang)">
                <input type="number" name="capacity" x-model="isi.capacity" @input="hapus('capacity')" min="1" max="5000" class="wt-input tabular-nums" :class="pesan('capacity') && 'is-invalid'">
            </x-staf.field>
        </div>
        <x-staf.field nama="description" label="Deskripsi">
            <textarea name="description" x-model="isi.description" @input="hapus('description')" rows="3" maxlength="2000" placeholder="Contoh: Ruang serbaguna untuk rapat, pelatihan, dan acara keluarga." class="wt-input" :class="pesan('description') && 'is-invalid'"></textarea>
        </x-staf.field>
        <x-staf.galeri />
    </x-staf.panel>
</div>
</x-layouts.staf>
