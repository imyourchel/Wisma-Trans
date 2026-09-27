@php
    $baris = $roles->values()->map(fn ($r) => [
        'nama' => $r->name,
        'cari' => $r->code.' '.$r->name,
        'isi' => ['code' => $r->code, 'name' => $r->name],
        'url' => route('staf.peran.update', $r),
    ]);
@endphp

<x-layouts.staf title="Peran">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => ['code' => '', 'name' => ''], 'urlSimpan' => '']))">
    <x-staf.toolbar cari="Cari peran" />

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Kode</th>
                    <th class="wt-th">Nama</th>
                    <th class="wt-th">Pengguna aktif</th>
                    <th class="wt-th">Akses menu</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles->values() as $i => $r)
                    <tr x-show="cocok(baris[{{ $i }}])" class="hover:bg-row-hover">
                        <td class="wt-td font-mono text-xs text-ink-500">{{ $r->code }}</td>
                        <td class="wt-td font-semibold">{{ $r->name }}</td>
                        <td class="wt-td tabular-nums">{{ $r->users_count }} orang</td>
                        <td class="wt-td text-ink-500">
                            {{ $r->code === \App\Models\Role::CUSTOMER ? 'Halaman tamu: pesanan saya dan profil' : implode(' · ', $akses[$r->code] ?? []) }}
                        </td>
                        <td class="wt-td"><x-staf.aksi :i="$i" /></td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="5" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada peran yang cocok dengan pencarian.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-ink-500">Peran bersifat tetap karena kodenya dipakai sistem untuk mengatur hak akses. Nama tampilannya dapat diubah. Peran tiap staf diatur di menu Pengguna.</p>

    <x-staf.panel nama="peran">
        <x-staf.field nama="code" label="Kode">
            <input type="text" x-model="isi.code" readonly class="wt-input font-mono text-[13px]">
        </x-staf.field>
        <x-staf.field nama="name" label="Nama tampilan">
            <input type="text" name="name" x-model="isi.name" @input="hapus('name')" maxlength="50" data-fokus class="wt-input" :class="pesan('name') && 'is-invalid'">
        </x-staf.field>
    </x-staf.panel>
</div>
</x-layouts.staf>
