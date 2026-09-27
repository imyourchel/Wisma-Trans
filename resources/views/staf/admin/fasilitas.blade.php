@php
    use App\Models\Facility;

    $baris = $fasilitas->values()->map(fn ($f) => [
        'nama' => $f->name,
        'cari' => $f->name.' '.(Facility::CATEGORIES[$f->category] ?? ''),
        'isi' => ['name' => $f->name, 'category' => $f->category, 'is_maintainable' => $f->is_maintainable ? '1' : '0'],
        'url' => route('staf.fasilitas.update', $f),
    ]);
    $kosong = ['name' => '', 'category' => 'room', 'is_maintainable' => '0'];
@endphp

<x-layouts.staf title="Fasilitas">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.fasilitas.store')]))">
    <x-staf.toolbar tambah="Tambah fasilitas" cari="Cari fasilitas" />

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[720px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Nama</th>
                    <th class="wt-th">Kategori</th>
                    <th class="wt-th">Perawatan berkala</th>
                    <th class="wt-th">Unit aktif</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fasilitas->values() as $i => $f)
                    <tr x-show="cocok(baris[{{ $i }}])" class="hover:bg-row-hover">
                        <td class="wt-td font-semibold">{{ $f->name }}</td>
                        <td class="wt-td">{{ Facility::CATEGORIES[$f->category] ?? $f->category }}</td>
                        <td class="wt-td">
                            <span @class(['wt-lencana', 'wt-lencana-aktif' => $f->is_maintainable, 'wt-lencana-nonaktif' => ! $f->is_maintainable])>{{ $f->is_maintainable ? 'Perlu' : 'Tidak perlu' }}</span>
                        </td>
                        <td class="wt-td tabular-nums">
                            @if ($f->is_maintainable)
                                <a href="{{ route('staf.unit-fasilitas.index') }}" class="text-olive-600">{{ $jumlahUnit[$f->id] ?? 0 }} unit</a>
                            @else
                                <span class="text-ink-500">—</span>
                            @endif
                        </td>
                        <td class="wt-td"><x-staf.aksi :i="$i" /></td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="5" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada fasilitas yang cocok dengan pencarian.</td></tr>
            </tbody>
        </table>
    </div>

    <x-staf.panel nama="fasilitas">
        <x-staf.field nama="name" label="Nama fasilitas">
            <input type="text" name="name" x-model="isi.name" @input="hapus('name')" maxlength="80" placeholder="Contoh: Kulkas mini" class="wt-input" :class="pesan('name') && 'is-invalid'">
        </x-staf.field>
        <x-staf.field nama="category" label="Kategori" tag="div" bantuan="Kamar: ada di dalam kamar. Umum: dipakai bersama, misalnya WiFi atau genset.">
            <div class="flex flex-wrap gap-2">
                @foreach (Facility::CATEGORIES as $nilai => $label)
                    <label class="cursor-pointer rounded border border-cream-300 px-3.5 py-2 text-[13px] has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-150">
                        <input type="radio" name="category" value="{{ $nilai }}" x-model="isi.category" class="sr-only">{{ $label }}
                    </label>
                @endforeach
            </div>
        </x-staf.field>
        <x-staf.field nama="is_maintainable" label="Perawatan berkala" tag="div" bantuan="Fasilitas yang perlu perawatan berkala punya unit yang dijadwalkan perawatannya, misalnya AC yang dicuci tiap 3 bulan.">
            <div class="flex flex-wrap gap-2">
                @foreach (['1' => 'Perlu', '0' => 'Tidak perlu'] as $nilai => $label)
                    <label class="cursor-pointer rounded border border-cream-300 px-3.5 py-2 text-[13px] has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-150">
                        <input type="radio" name="is_maintainable" value="{{ $nilai }}" x-model="isi.is_maintainable" class="sr-only">{{ $label }}
                    </label>
                @endforeach
            </div>
        </x-staf.field>
    </x-staf.panel>
</div>
</x-layouts.staf>
