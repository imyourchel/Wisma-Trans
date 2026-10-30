@php
    $saya = auth()->id();
    $baris = $users->values()->map(fn ($u) => [
        'nama' => $u->name,
        'cari' => implode(' ', [$u->name, $u->email, $u->phone, $u->roles->pluck('name')->join(' ')]),
        'peran' => $u->roles->pluck('id')->map(fn ($id) => (string) $id)->values(),
        'isi' => [
            'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone ?? '',
            'peran' => $u->roles->whereIn('code', \App\Models\Role::STAFF)->pluck('id')->map(fn ($id) => (string) $id)->values(),
            'password' => '',
        ],
        'url' => route('staf.pengguna.update', $u),
        'urlAktif' => route('staf.pengguna.toggle', $u),
        'pesanNonaktif' => "{$u->name} tidak dapat masuk ke panel staf sampai diaktifkan kembali. Riwayat pekerjaannya tetap tersimpan.",
    ]);
    $kosong = ['name' => '', 'email' => '', 'phone' => '', 'peran' => [], 'password' => ''];
@endphp

<x-layouts.staf title="Pengguna">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.pengguna.store')]))">
    <x-staf.toolbar tambah="Tambah pengguna" cari="Cari nama, email, atau peran">
        {{-- Saring peran: boleh lebih dari satu; tampil pengguna yang punya salah satu peran terpilih --}}
        <div class="relative" x-data="{ bukaPeran: false }" @click.outside="bukaPeran = false" @keydown.escape="bukaPeran = false">
            <button type="button" @click="bukaPeran = !bukaPeran" :aria-expanded="bukaPeran"
                    :class="saringPeran.length ? 'border-clay-500 text-clay-600' : 'border-cream-300 text-ink-900'"
                    class="inline-flex h-10 items-center gap-2 rounded border bg-cream-50 px-3.5 text-[13px] font-semibold hover:bg-cream-100">
                Peran
                <span x-show="saringPeran.length" x-cloak x-text="saringPeran.length" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-clay-500 px-1.5 text-[11px] text-cream-50"></span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="transition-transform" :class="bukaPeran && 'rotate-180'" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div x-show="bukaPeran" x-cloak x-transition.opacity.duration.150ms
                 class="absolute left-0 top-[calc(100%+6px)] z-30 w-[240px] rounded-md border border-cream-300 bg-cream-50 p-1.5 shadow-wt-lg" role="group" aria-label="Saring peran">
                @foreach ($peran as $r)
                    <label class="flex cursor-pointer items-center gap-2.5 rounded px-2.5 py-2 text-[13px] hover:bg-row-hover">
                        <input type="checkbox" value="{{ $r->id }}" x-model="saringPeran" class="h-4 w-4 accent-clay-500">
                        <span class="flex-1">{{ $r->name }}</span>
                        <span class="text-xs tabular-nums text-ink-500" x-text="baris.filter((b) => b.peran.includes('{{ $r->id }}')).length"></span>
                    </label>
                @endforeach
                <div class="mt-1 flex justify-between border-t border-cream-300 px-1 pt-1.5">
                    <button type="button" @click="saringPeran = @js($peran->pluck('id')->map(fn ($id) => (string) $id)->values())" class="rounded px-2 py-1.5 text-xs font-semibold text-brown-800 hover:bg-cream-200">Pilih semua</button>
                    <button type="button" @click="saringPeran = []" x-show="saringPeran.length" class="rounded px-2 py-1.5 text-xs font-semibold text-clay-600 hover:bg-cream-200">Hapus saringan</button>
                </div>
            </div>
        </div>
    </x-staf.toolbar>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[900px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Nama</th>
                    <th class="wt-th">Email</th>
                    <th class="wt-th">Peran</th>
                    <th class="wt-th">Status</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users->values() as $i => $u)
                    <tr x-show="cocok(baris[{{ $i }}])" @class(['hover:bg-row-hover', 'opacity-55' => ! $u->is_active])>
                        <td class="wt-td">
                            <div class="font-semibold">{{ $u->name }} @if ($u->id === $saya)<span class="wt-lencana wt-lencana-netral ml-1 align-middle">Anda</span>@endif</div>
                            <div class="text-xs tabular-nums text-ink-500">{{ $u->phone ?? 'Tanpa nomor telepon' }}</div>
                        </td>
                        <td class="wt-td">{{ $u->email }}</td>
                        <td class="wt-td">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($u->roles->whereIn('code', \App\Models\Role::STAFF) as $r)
                                    <span class="wt-lencana wt-lencana-netral">{{ $r->name }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="wt-td"><x-staf.status :aktif="$u->is_active" /></td>
                        <td class="wt-td">
                            {{-- Akun sendiri tidak dapat dinonaktifkan --}}
                            <x-staf.aksi :i="$i" :aktif="$u->id === $saya ? null : $u->is_active">
                                <button type="button" @click="ubah({{ $i }}); $nextTick(() => $refs.panel.querySelector('[name=password]').focus())" class="wt-btn-sm border-transparent !text-olive-600">Atur ulang sandi</button>
                            </x-staf.aksi>
                        </td>
                    </tr>
                @endforeach
                <tr x-show="jumlah === 0" x-cloak><td colspan="5" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada pengguna yang cocok dengan pencarian atau saringan peran.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-ink-500">Hanya akun staf yang tampil di sini. Akun tamu dibuat sendiri oleh tamu melalui halaman daftar.</p>

    <x-staf.panel nama="pengguna">
        <x-staf.field nama="name" label="Nama lengkap">
            <input type="text" name="name" x-model="isi.name" @input="hapus('name')" maxlength="100" autocomplete="off" class="wt-input" :class="pesan('name') && 'is-invalid'">
        </x-staf.field>
        <x-staf.field nama="email" label="Email" bantuan="Dipakai untuk masuk ke panel staf.">
            <input type="email" name="email" x-model="isi.email" @input="hapus('email')" maxlength="150" autocomplete="off" placeholder="nama@wismatrans.id" class="wt-input" :class="pesan('email') && 'is-invalid'">
        </x-staf.field>
        <x-staf.field nama="phone" label="Nomor telepon (opsional)">
            <input type="tel" name="phone" x-model="isi.phone" @input="hapus('phone')" autocomplete="off" placeholder="08xxxxxxxxxx" class="wt-input" :class="pesan('phone') && 'is-invalid'">
        </x-staf.field>
        <x-staf.field nama="peran" label="Peran (boleh lebih dari satu)" tag="div" bantuan="Staf yang merangkap peran melihat gabungan menu dari semua perannya.">
            <div class="flex flex-wrap gap-2">
                @foreach ($peran as $r)
                    <label class="cursor-pointer rounded border border-cream-300 px-3.5 py-2 text-[13px] has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-150">
                        <input type="checkbox" name="peran[]" value="{{ $r->id }}" x-model="isi.peran" @change="hapus('peran')" class="sr-only">{{ $r->name }}
                    </label>
                @endforeach
            </div>
        </x-staf.field>
        <div x-data="{ lihat: false }">
            <x-staf.field nama="password" label="Kata sandi">
                <span class="relative block">
                    <input :type="lihat ? 'text' : 'password'" type="password" name="password" x-model="isi.password" @input="hapus('password')" autocomplete="new-password"
                           :placeholder="sedangUbah ? 'Kosongkan bila tidak diganti' : 'Minimal 8 karakter'" class="wt-input pr-10" :class="pesan('password') && 'is-invalid'">
                    <button type="button" @click="lihat = !lihat" :title="lihat ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'" class="absolute right-2 top-1/2 flex -translate-y-1/2 border-0 bg-transparent p-1">
                        <img x-show="!lihat" src="{{ asset('ds/icons/eye-muted.svg') }}" alt="" width="16" height="16">
                        <img x-show="lihat" x-cloak src="{{ asset('ds/icons/eye-off-muted.svg') }}" alt="" width="16" height="16">
                    </button>
                </span>
            </x-staf.field>
            <p class="mb-0 mt-1.5 text-xs text-ink-500" x-text="sedangUbah ? 'Isi untuk mengatur ulang kata sandi, lalu sampaikan kata sandi baru kepada staf yang bersangkutan.' : 'Sampaikan kata sandi awal ini kepada staf yang bersangkutan.'"></p>
        </div>
    </x-staf.panel>
</div>
</x-layouts.staf>
