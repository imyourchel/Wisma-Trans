@php
    use App\Models\Service;
    use Illuminate\Support\Facades\Storage;

    $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    $urlFoto = fn (?string $p) => $p && str_contains($p, '/') && Storage::disk('public')->exists($p) ? asset('storage/'.$p) : null;
    $baris = $layanan->values()->map(fn ($s) => [
        'nama' => $s->name,
        'cari' => implode(' ', [$s->code, $s->name, $s->menu_group, Service::CATEGORIES[$s->category] ?? '', $s->description]),
        'kelompok' => $s->category,
        'grup' => $s->menu_group ?? '',
        'isi' => [
            'code' => $s->code, 'name' => $s->name, 'description' => $s->description ?? '', 'category' => $s->category,
            'menu_group' => $s->menu_group ?? '', 'department' => $s->department, 'unit' => $s->unit,
            'price' => $s->price === null ? '' : (int) $s->price,
            'fotoUrl' => $urlFoto($s->photo), 'fotoBaru' => null, 'hapus_foto' => false,
        ],
        'url' => route('staf.layanan.update', $s),
        'urlAktif' => route('staf.layanan.toggle', $s),
        'pesanNonaktif' => "{$s->name} tidak tampil di menu tamu dan kasir sampai diaktifkan kembali. Pesanan lama tetap tersimpan.",
    ]);
    // Kelompok menu makanan & minuman, urut seperti menu cetak
    $grupMenu = $layanan->where('category', 'fnb')->sortBy('id')->pluck('menu_group')->filter()->unique()->mapWithKeys(fn ($g) => [$g => $g])->all();
    $kosong = ['code' => '', 'name' => '', 'description' => '', 'category' => 'fnb', 'menu_group' => '', 'department' => 'fnb', 'unit' => 'porsi', 'price' => '', 'fotoUrl' => null, 'fotoBaru' => null, 'hapus_foto' => false];
@endphp

<x-layouts.staf title="Layanan dan Menu">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.layanan.store')]))">
    <x-staf.toolbar tambah="Tambah layanan" cari="Cari kode, nama, atau kelompok">
        <x-staf.pilih model="saring" x-on:change="saringGrup = ''" label="Saring kategori" class="w-[190px]" :pilihan="['' => 'Semua kategori'] + Service::CATEGORIES" />
        {{-- Saringan kelompok hanya untuk makanan & minuman --}}
        <div x-show="saring === 'fnb'" x-cloak x-transition.opacity.duration.150ms>
            <x-staf.pilih model="saringGrup" label="Saring kelompok menu" class="w-[190px]" :pilihan="['' => 'Semua kelompok'] + $grupMenu" />
        </div>
    </x-staf.toolbar>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[980px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Nama</th>
                    <th class="wt-th">Kategori</th>
                    <th class="wt-th">Kelompok</th>
                    <th class="wt-th">Bagian</th>
                    <th class="wt-th text-right">Harga</th>
                    <th class="wt-th">Status</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($layanan->values() as $i => $s)
                    <tr x-show="cocok(baris[{{ $i }}])" @class(['hover:bg-row-hover', 'opacity-55' => ! $s->is_active])>
                        <td class="wt-td">
                            <div class="flex items-center gap-3">
                                <div class="h-9 w-9 flex-none overflow-hidden rounded bg-cream-200">
                                    @if ($u = $urlFoto($s->photo))
                                        <img src="{{ $u }}" alt="" class="h-full w-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="font-semibold">{{ $s->name }}</div>
                                    <div class="max-w-[300px] truncate text-xs text-ink-500"><span class="font-mono">{{ $s->code }}</span>@if ($s->description) · {{ $s->description }}@endif</div>
                                </div>
                            </div>
                        </td>
                        <td class="wt-td whitespace-nowrap">{{ Service::CATEGORIES[$s->category] ?? $s->category }}</td>
                        <td class="wt-td whitespace-nowrap">{{ $s->menu_group ?? '—' }}</td>
                        <td class="wt-td whitespace-nowrap">{{ Service::DEPARTMENTS[$s->department] ?? $s->department }}</td>
                        <td class="wt-td whitespace-nowrap text-right tabular-nums">
                            {{ $s->price === null ? 'Sesuai ukuran' : $rp($s->price) }}<span class="text-xs text-ink-500"> / {{ $s->unit }}</span>
                        </td>
                        <td class="wt-td"><x-staf.status :aktif="$s->is_active" /></td>
                        <td class="wt-td"><x-staf.aksi :i="$i" :aktif="$s->is_active" /></td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="7" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada layanan yang cocok dengan pencarian.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-ink-500">Harga baru hanya berlaku untuk pesanan berikutnya. Menu tanpa harga (sesuai ukuran) diisi harganya oleh staf di kasir.</p>

    <datalist id="daftar-kelompok">@foreach ($kelompok as $k)<option value="{{ $k }}">@endforeach</datalist>
    <datalist id="daftar-satuan">@foreach ($satuan as $k)<option value="{{ $k }}">@endforeach</datalist>

    <x-staf.panel nama="layanan" lebar="w-[520px]">
        <div class="grid grid-cols-[130px_1fr] gap-4">
            <x-staf.field nama="code" label="Kode">
                <input type="text" name="code" x-model="isi.code" @input="hapus('code')" maxlength="20" placeholder="FNB01" class="wt-input uppercase" :class="pesan('code') && 'is-invalid'">
            </x-staf.field>
            <x-staf.field nama="name" label="Nama">
                <input type="text" name="name" x-model="isi.name" @input="hapus('name')" maxlength="100" class="wt-input" :class="pesan('name') && 'is-invalid'">
            </x-staf.field>
        </div>
        <x-staf.field nama="description" label="Deskripsi (opsional)">
            <textarea name="description" x-model="isi.description" @input="hapus('description')" rows="2" maxlength="255" class="wt-input" :class="pesan('description') && 'is-invalid'"></textarea>
        </x-staf.field>
        <div class="grid grid-cols-2 gap-4">
            <x-staf.field nama="category" label="Kategori" tag="div">
                <x-staf.pilih name="category" model="isi.category" x-on:change="hapus('category')" galat="pesan('category')" label="Kategori" :pilihan="Service::CATEGORIES" />
            </x-staf.field>
            <x-staf.field nama="department" label="Bagian yang mengerjakan" tag="div">
                <x-staf.pilih name="department" model="isi.department" x-on:change="hapus('department')" galat="pesan('department')" label="Bagian yang mengerjakan" :pilihan="Service::DEPARTMENTS" />
            </x-staf.field>
        </div>
        <div class="grid grid-cols-2 gap-4">
            {{-- Makanan & minuman: pilih dari kelompok yang ada, atau tulis kelompok baru --}}
            <x-staf.field nama="menu_group" label="Kelompok menu" tag="div">
                <template x-if="isi.category === 'fnb' && !isi.grupBaru">
                    <div>
                        <x-staf.pilih name="menu_group" model="isi.menu_group" x-on:change="hapus('menu_group')" galat="pesan('menu_group')" label="Kelompok menu"
                                      :pilihan="['' => 'Pilih kelompok'] + $grupMenu" />
                        <button type="button" @click="isi.grupBaru = true; isi.menu_group = ''; $nextTick(() => $refs.panel.querySelector('input[name=menu_group]')?.focus())"
                                class="mt-1.5 border-0 bg-transparent p-0 text-xs font-semibold text-clay-600 hover:text-clay-500">+ Kelompok baru</button>
                    </div>
                </template>
                <template x-if="isi.category !== 'fnb' || isi.grupBaru">
                    <div>
                        <input type="text" name="menu_group" list="daftar-kelompok" x-model="isi.menu_group" @input="hapus('menu_group')" maxlength="50" placeholder="Contoh: Rekomendasi" class="wt-input" :class="pesan('menu_group') && 'is-invalid'">
                        <button type="button" x-show="isi.category === 'fnb'" @click="isi.grupBaru = false; isi.menu_group = ''"
                                class="mt-1.5 border-0 bg-transparent p-0 text-xs font-semibold text-clay-600 hover:text-clay-500">Pilih dari daftar</button>
                    </div>
                </template>
            </x-staf.field>
            <x-staf.field nama="unit" label="Satuan">
                <input type="text" name="unit" list="daftar-satuan" x-model="isi.unit" @input="hapus('unit')" maxlength="20" placeholder="porsi" class="wt-input" :class="pesan('unit') && 'is-invalid'">
            </x-staf.field>
        </div>
        <x-staf.field nama="price" label="Harga (Rp)" bantuan="Kosongkan bila harga sesuai ukuran, misalnya menu ikan. Harga diisi staf di kasir.">
            <input type="number" name="price" x-model="isi.price" @input="hapus('price')" min="0" step="500" placeholder="Sesuai ukuran" class="wt-input tabular-nums" :class="pesan('price') && 'is-invalid'">
        </x-staf.field>
        <x-staf.field nama="foto" label="Foto" tag="div" bantuan="Tampil di menu tamu. JPG, PNG, atau WEBP, maksimal 4 MB.">
            <div class="flex items-center gap-3">
                <div class="flex h-20 w-20 flex-none items-center justify-center overflow-hidden rounded border border-cream-300 bg-cream-200 text-ink-500">
                    <template x-if="isi.fotoBaru || (isi.fotoUrl && !isi.hapus_foto)"><img :src="isi.fotoBaru || isi.fotoUrl" alt="" class="h-full w-full object-cover"></template>
                    <template x-if="!(isi.fotoBaru || (isi.fotoUrl && !isi.hapus_foto))"><x-staf.ikon nama="foto" ukuran="20" /></template>
                </div>
                <div class="flex flex-col items-start gap-2">
                    <label class="wt-btn-sm cursor-pointer">
                        <span x-text="isi.fotoBaru || (isi.fotoUrl && !isi.hapus_foto) ? 'Ganti foto' : 'Pilih foto'"></span>
                        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" class="sr-only"
                               @change="const f = $event.target.files[0]; isi.fotoBaru = f ? URL.createObjectURL(f) : null; isi.hapus_foto = false; hapus('foto')">
                    </label>
                    <button type="button" x-show="isi.fotoBaru || (isi.fotoUrl && !isi.hapus_foto)"
                            @click="isi.fotoBaru = null; isi.hapus_foto = true; $refs.panel.querySelector('input[name=foto]').value = ''"
                            class="border-0 bg-transparent p-0 text-xs text-clay-600">Hapus foto</button>
                </div>
            </div>
            <input type="hidden" name="hapus_foto" :value="isi.hapus_foto ? 1 : 0">
        </x-staf.field>
    </x-staf.panel>
</div>
</x-layouts.staf>
