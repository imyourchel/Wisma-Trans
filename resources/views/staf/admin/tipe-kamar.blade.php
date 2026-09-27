@php
    use App\Http\Controllers\Staf\Admin\MasterController;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $baris = $tipe->values()->map(fn ($t) => [
        'nama' => $t->name,
        'cari' => implode(' ', [$t->code, $t->name, $t->facilities->pluck('name')->join(' ')]),
        'isi' => [
            'code' => $t->code, 'name' => $t->name, 'description' => $t->description ?? '',
            'base_price' => (int) $t->base_price, 'max_capacity' => $t->max_capacity,
            'fasilitas' => $t->facilities->pluck('id')->map(fn ($id) => (string) $id)->values(),
            'foto' => array_map(fn ($f) => $f + ['jenis' => 'lama'], MasterController::daftarFoto($t->photos)),
        ],
        'url' => route('staf.tipe-kamar.update', $t),
        'urlHapus' => route('staf.tipe-kamar.destroy', $t),
        'pesanHapus' => "Tipe kamar {$t->name} beserta fotonya akan dihapus permanen.",
        'tolakHapus' => match (true) {
            $t->kamar_aktif_count > 0 => "Tipe kamar ini masih dipakai oleh {$t->kamar_aktif_count} kamar. Pindahkan atau nonaktifkan kamarnya dulu.",
            $t->rooms_count > 0 => "Tipe kamar ini masih tercatat pada {$t->rooms_count} kamar nonaktif. Pindahkan kamarnya ke tipe lain dulu.",
            default => null,
        },
    ]);
    $kosong = ['code' => '', 'name' => '', 'description' => '', 'base_price' => '', 'max_capacity' => 2, 'fasilitas' => [], 'foto' => []];
@endphp

<x-layouts.staf title="Tipe Kamar">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.tipe-kamar.store')]))">
    <x-staf.toolbar tambah="Tambah tipe kamar" cari="Cari kode, nama, atau fasilitas" />

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[960px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Foto</th>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Nama</th>
                    <th class="wt-th text-right">Harga per malam</th>
                    <th class="wt-th">Kapasitas</th>
                    <th class="wt-th">Kamar aktif</th>
                    <th class="wt-th">Fasilitas</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tipe->values() as $i => $t)
                    @php $jumlahFoto = count($t->photos ?? []); @endphp
                    <tr x-show="cocok(baris[{{ $i }}])" class="hover:bg-row-hover">
                        <td class="wt-td">
                            <div class="relative h-11 w-16 overflow-hidden rounded bg-cream-200">
                                <img src="{{ $t->main_photo_url }}" alt="Foto utama {{ $t->name }}" class="h-full w-full object-cover" loading="lazy">
                            </div>
                            <div class="mt-1 text-[11px] text-ink-500">{{ $jumlahFoto }} foto</div>
                        </td>
                        <td class="wt-td font-semibold">{{ $t->code }}</td>
                        <td class="wt-td">{{ $t->name }}</td>
                        <td class="wt-td text-right tabular-nums">{{ $rp($t->base_price) }}</td>
                        <td class="wt-td">{{ $t->max_capacity }} orang</td>
                        <td class="wt-td tabular-nums">
                            {{ $t->kamar_aktif_count }}
                            @if ($t->rooms_count > $t->kamar_aktif_count)<span class="text-[11px] text-ink-500">(+{{ $t->rooms_count - $t->kamar_aktif_count }} nonaktif)</span>@endif
                        </td>
                        <td class="wt-td">
                            <div class="flex max-w-[260px] flex-wrap gap-1">
                                @forelse ($t->facilities->sortBy('name') as $f)
                                    <span class="wt-lencana wt-lencana-netral">{{ $f->name }}</span>
                                @empty
                                    <span class="text-ink-500">—</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="wt-td"><x-staf.aksi :i="$i" hapus /></td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="8" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada tipe kamar yang cocok dengan pencarian.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-ink-500">Harga baru hanya berlaku untuk reservasi berikutnya. Reservasi yang sudah dibuat tetap memakai harga saat dipesan.</p>

    <x-staf.panel nama="tipe kamar" lebar="w-[520px]">
        <div class="grid grid-cols-[120px_1fr] gap-4">
            <x-staf.field nama="code" label="Kode">
                <input type="text" name="code" x-model="isi.code" @input="isi.code = isi.code.toUpperCase(); hapus('code')" maxlength="20" placeholder="DLX" autocapitalize="characters" class="wt-input uppercase" :class="pesan('code') && 'is-invalid'">
            </x-staf.field>
            <x-staf.field nama="name" label="Nama">
                <input type="text" name="name" x-model="isi.name" @input="hapus('name')" maxlength="50" placeholder="Deluxe Room" class="wt-input" :class="pesan('name') && 'is-invalid'">
            </x-staf.field>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <x-staf.field nama="base_price" label="Harga per malam (Rp)">
                {{-- Tampil dengan pemisah ribuan; yang dikirim hanya angkanya --}}
                <input type="hidden" name="base_price" :value="isi.base_price">
                <input type="text" inputmode="numeric" maxlength="10" placeholder="360.000" class="wt-input tabular-nums" :class="pesan('base_price') && 'is-invalid'"
                       :value="isi.base_price === '' ? '' : Number(isi.base_price).toLocaleString('id-ID')"
                       @input="isi.base_price = $event.target.value.replace(/\D/g, '').replace(/^0+(?=\d)/, ''); $event.target.value = isi.base_price === '' ? '' : Number(isi.base_price).toLocaleString('id-ID'); hapus('base_price')">
            </x-staf.field>
            <x-staf.field nama="max_capacity" label="Kapasitas (orang)">
                <input type="number" name="max_capacity" x-model="isi.max_capacity" @input="hapus('max_capacity')" min="1" max="10" class="wt-input" :class="pesan('max_capacity') && 'is-invalid'">
            </x-staf.field>
        </div>
        <x-staf.field nama="description" label="Deskripsi">
            <textarea name="description" x-model="isi.description" @input="hapus('description')" rows="3" maxlength="2000" placeholder="Tampil di halaman detail kamar untuk tamu" class="wt-input" :class="pesan('description') && 'is-invalid'"></textarea>
        </x-staf.field>
        <x-staf.field nama="fasilitas" label="Fasilitas kamar" tag="div" bantuan="Hanya fasilitas kategori kamar. Fasilitas umum (misalnya WiFi) yang sudah tercatat tetap dipertahankan. Fasilitas baru ditambahkan di menu Fasilitas.">
            <div class="flex flex-wrap gap-2">
                @foreach ($fasilitas as $f)
                    <label class="cursor-pointer rounded border border-cream-300 px-3.5 py-2 text-[13px] has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-150">
                        <input type="checkbox" name="fasilitas[]" value="{{ $f->id }}" x-model="isi.fasilitas" class="sr-only">{{ $f->name }}
                    </label>
                @endforeach
            </div>
        </x-staf.field>
        <x-staf.galeri :maks="null" />
    </x-staf.panel>
</div>
</x-layouts.staf>
