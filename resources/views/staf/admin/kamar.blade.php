@php
    use App\Enums\RoomStatus;
    use App\Models\Room;

    $baris = $kamar->getCollection()->values()->map(fn (Room $k) => [
        'nama' => 'Kamar '.$k->room_number,
        'cari' => $k->room_number,
        'isi' => ['room_number' => $k->room_number, 'floor' => $k->floor, 'room_type_id' => (string) $k->room_type_id, 'bed_type' => $k->bed_type],
        'url' => route('staf.kamar.update', $k),
        'urlAktif' => route('staf.kamar.toggle', $k),
        'pesanNonaktif' => "Kamar {$k->room_number} tidak tampil di Papan Status dan tidak dapat dipilih untuk reservasi baru. Riwayatnya tetap tersimpan.",
    ]);
    // lantaiManual: setelah lantai diisi sendiri, lantai tidak lagi mengikuti nomor kamar
    $kosong = ['room_number' => '', 'floor' => '', 'room_type_id' => (string) $tipe->first()?->id, 'bed_type' => 'double', 'lantaiManual' => false];
    $adaSaringan = collect($saring)->filter(fn ($v) => $v !== '')->isNotEmpty();
@endphp

<x-layouts.staf title="Kamar">
<div x-data="dataMaster(@js(['baris' => $baris, 'kosong' => $kosong, 'urlSimpan' => route('staf.kamar.store')]))">
    {{-- Saringan dikirim lewat query string supaya paginasi 15 kamar per halaman tetap benar --}}
    <form method="GET" action="{{ route('staf.kamar.index') }}" class="mb-4 flex flex-wrap items-center gap-3">
        <label class="relative block min-w-0 flex-[1_1_180px] sm:max-w-[220px]">
            <span class="sr-only">Cari nomor kamar</span>
            <x-staf.ikon nama="cari" ukuran="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-500" />
            <input type="search" name="cari" value="{{ $saring['cari'] }}" placeholder="Cari nomor kamar" class="wt-input pl-9">
        </label>
        <x-staf.pilih name="tipe" kirim label="Saring tipe kamar" class="w-[180px]" :nilai="$saring['tipe']"
                      :pilihan="['' => 'Semua tipe'] + $tipe->pluck('name', 'id')->all()" />
        <x-staf.pilih name="lantai" kirim label="Saring lantai" class="w-[150px]" :nilai="$saring['lantai']"
                      :pilihan="['' => 'Semua lantai'] + $lantai->mapWithKeys(fn ($l) => [$l => 'Lantai '.$l])->all()" />
        <x-staf.pilih name="status" kirim label="Saring status kamar" class="w-[230px]" :nilai="$saring['status']"
                      :pilihan="['' => 'Semua status'] + collect(RoomStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ['label' => $s->label(), 'warna' => $s->color()]])->all()" />
        <x-staf.pilih name="aktif" kirim label="Saring aktif atau nonaktif" class="w-[180px]" :nilai="$saring['aktif']"
                      :pilihan="['' => 'Aktif dan nonaktif', '1' => 'Aktif', '0' => 'Nonaktif']" />
        @if ($adaSaringan)
            <a href="{{ route('staf.kamar.index') }}" class="wt-btn-sm hover:no-underline">Hapus saringan</a>
        @endif
        <span class="whitespace-nowrap text-[13px] text-ink-500">{{ $kamar->total() }} kamar</span>
        <button type="button" @click="tambah()"
                class="ml-auto inline-flex h-10 items-center gap-2 whitespace-nowrap rounded border-0 bg-clay-500 px-4 font-display text-[13px] font-semibold text-cream-50 hover:bg-clay-600">
            <x-staf.ikon nama="tambah" ukuran="16" />Tambah kamar
        </button>
    </form>

    <div class="wt-card overflow-x-auto">
        <table class="w-full min-w-[820px] border-collapse">
            <thead>
                <tr>
                    <th class="wt-th">Nomor</th>
                    <th class="wt-th">Tipe</th>
                    <th class="wt-th">Lantai</th>
                    <th class="wt-th">Jenis kasur</th>
                    <th class="wt-th">Status kamar</th>
                    <th class="wt-th">Aktif</th>
                    <th class="wt-th"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($kamar as $i => $k)
                    <tr @class(['hover:bg-row-hover', 'opacity-55' => ! $k->is_active])>
                        <td class="wt-td font-semibold tabular-nums">{{ $k->room_number }}</td>
                        <td class="wt-td">{{ $k->roomType?->name }} <span class="text-ink-500">({{ $k->roomType?->code }})</span></td>
                        <td class="wt-td tabular-nums">{{ $k->floor }}</td>
                        <td class="wt-td">{{ $k->bedTypeLabel() }}</td>
                        <td class="wt-td">
                            <span class="inline-flex items-center gap-2 whitespace-nowrap">
                                <span class="h-2.5 w-2.5 flex-none rounded-full" style="background-color: {{ $k->status->color() }}"></span>{{ $k->status->label() }}
                            </span>
                        </td>
                        <td class="wt-td"><x-staf.status :aktif="$k->is_active" /></td>
                        <td class="wt-td"><x-staf.aksi :i="$i" :aktif="$k->is_active" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="wt-td py-10 text-center text-sm text-ink-500">Tidak ada kamar yang cocok dengan saringan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $kamar->links('partials.halaman') }}
    <p class="mt-3 text-xs text-ink-500">Status kamar diganti oleh Front Office dan Housekeeping di Papan Status Kamar, bukan dari halaman ini. Kamar tidak dihapus, cukup dinonaktifkan.</p>

    <x-staf.panel nama="kamar">
        <div class="grid grid-cols-2 gap-4">
            <x-staf.field nama="room_number" label="Nomor kamar">
                <input type="text" name="room_number" x-model="isi.room_number" maxlength="10" placeholder="Contoh: 101" autocapitalize="characters"
                       @input="isi.room_number = isi.room_number.toUpperCase(); hapus('room_number');
                               if (!sedangUbah && !isi.lantaiManual) { const d = isi.room_number.match(/^[1-9]/); isi.floor = d ? d[0] : ''; }"
                       class="wt-input uppercase" :class="pesan('room_number') && 'is-invalid'">
            </x-staf.field>
            <x-staf.field nama="floor" label="Lantai" x-bind:title="sedangUbah ? '' : 'Terisi otomatis dari digit pertama nomor kamar'">
                <input type="number" name="floor" x-model="isi.floor" @input="isi.lantaiManual = true; hapus('floor')" min="1" max="10" class="wt-input tabular-nums" :class="pesan('floor') && 'is-invalid'">
            </x-staf.field>
        </div>
        <x-staf.field nama="room_type_id" label="Tipe kamar" tag="div">
            <x-staf.pilih name="room_type_id" model="isi.room_type_id" x-on:change="hapus('room_type_id')" galat="pesan('room_type_id')" label="Tipe kamar"
                          :pilihan="$tipe->mapWithKeys(fn ($t) => [$t->id => $t->name.' ('.$t->code.')'])->all()" />
        </x-staf.field>
        <x-staf.field nama="bed_type" label="Jenis kasur" tag="div">
            <div class="flex flex-wrap gap-2">
                @foreach (Room::BED_TYPES as $nilai => $label)
                    <label class="cursor-pointer rounded border border-cream-300 px-3.5 py-2 text-[13px] has-[:checked]:border-brown-800 has-[:checked]:bg-brown-800 has-[:checked]:text-cream-150">
                        <input type="radio" name="bed_type" value="{{ $nilai }}" x-model="isi.bed_type" @change="hapus('bed_type')" class="sr-only">{{ $label }}
                    </label>
                @endforeach
            </div>
        </x-staf.field>
        <p x-show="!sedangUbah" class="m-0 text-xs text-ink-500">Lantai terisi otomatis dari digit pertama nomor kamar. Kamar baru berstatus <strong>Siap</strong>; status selanjutnya diganti di Papan Status Kamar.</p>
    </x-staf.panel>
</div>
</x-layouts.staf>
