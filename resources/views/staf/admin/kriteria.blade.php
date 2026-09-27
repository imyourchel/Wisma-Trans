@php
    use App\Models\CleaningCriterion;

    $baris = $kriteria->values()->map(fn ($k) => [
        'nama' => $k->code.' '.$k->name,
        'cari' => implode(' ', [$k->code, $k->name, $k->attribute_type, $k->description]),
        'isi' => ['code' => $k->code, 'name' => $k->name, 'attribute_type' => $k->attribute_type, 'description' => $k->description ?? ''],
        'url' => route('staf.kriteria.update', $k),
        'urlAktif' => route('staf.kriteria.toggle', $k),
        'pesanNonaktif' => "Kriteria {$k->code} tidak dipakai pada perhitungan prioritas berikutnya. Manager perlu meninjau ulang bobot kriteria.",
    ]);
    $kosong = ['code' => 'C'.($kriteria->count() + 1), 'name' => '', 'attribute_type' => 'benefit', 'description' => ''];
@endphp

<x-layouts.staf title="Kriteria Pembersihan">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.kriteria.store')]))">
    <x-staf.toolbar tambah="Tambah kriteria" cari="Cari kode atau nama" />

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Nama</th>
                    <th class="wt-th">Sifat</th>
                    <th class="wt-th">Keterangan</th>
                    <th class="wt-th">Status</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($kriteria->values() as $i => $k)
                    <tr x-show="cocok(baris[{{ $i }}])" @class(['hover:bg-row-hover', 'opacity-55' => ! $k->is_active])>
                        <td class="wt-td font-semibold">{{ $k->code }}</td>
                        <td class="wt-td">{{ $k->name }}</td>
                        <td class="wt-td"><span class="wt-lencana wt-lencana-netral">{{ CleaningCriterion::TYPES[$k->attribute_type] ?? $k->attribute_type }}</span></td>
                        <td class="wt-td max-w-[380px] text-ink-500">{{ $k->description ?: '—' }}</td>
                        <td class="wt-td"><x-staf.status :aktif="$k->is_active" /></td>
                        <td class="wt-td"><x-staf.aksi :i="$i" :aktif="$k->is_active" /></td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="6" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada kriteria yang cocok dengan pencarian.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-ink-500">Kriteria dipakai untuk mengurutkan kamar yang perlu dibersihkan lebih dulu. Bobotnya ditetapkan Manager di menu Bobot Kriteria.</p>

    <x-staf.panel nama="kriteria">
        <div class="grid grid-cols-[110px_1fr] gap-4">
            <x-staf.field nama="code" label="Kode">
                <input type="text" name="code" x-model="isi.code" @input="hapus('code')" maxlength="10" class="wt-input uppercase" :class="pesan('code') && 'is-invalid'">
            </x-staf.field>
            <x-staf.field nama="name" label="Nama">
                <input type="text" name="name" x-model="isi.name" @input="hapus('name')" maxlength="80" data-fokus class="wt-input" :class="pesan('name') && 'is-invalid'">
            </x-staf.field>
        </div>
        <x-staf.field nama="attribute_type" label="Sifat" tag="div" bantuan="Benefit: makin besar nilainya, kamar makin didahulukan. Cost: makin kecil nilainya, kamar makin didahulukan.">
            <div class="flex flex-wrap gap-2">
                @foreach (CleaningCriterion::TYPES as $nilai => $label)
                    <label class="cursor-pointer rounded border border-cream-300 px-3.5 py-2 text-[13px] has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-150">
                        <input type="radio" name="attribute_type" value="{{ $nilai }}" x-model="isi.attribute_type" class="sr-only">{{ $label }}
                    </label>
                @endforeach
            </div>
        </x-staf.field>
        <x-staf.field nama="description" label="Keterangan">
            <textarea name="description" x-model="isi.description" @input="hapus('description')" rows="3" maxlength="255" class="wt-input" :class="pesan('description') && 'is-invalid'"></textarea>
        </x-staf.field>
    </x-staf.panel>
</div>
</x-layouts.staf>
