@props(['i', 'aktif' => null, 'hapus' => false])
{{-- Tombol per baris: Ubah, Nonaktifkan/Aktifkan bila datanya punya status aktif, dan Hapus bila diizinkan --}}
<div class="flex items-center justify-end gap-1.5">
    {{ $slot }}
    <button type="button" @click="ubah({{ $i }})" class="wt-btn-sm">Ubah</button>
    @if ($aktif === true)
        <button type="button" @click="nonaktifkan({{ $i }})" class="wt-btn-sm !text-clay-600">Nonaktifkan</button>
    @elseif ($aktif === false)
        <button type="button" @click="aktifkan({{ $i }})" class="wt-btn-sm !text-status-available">Aktifkan</button>
    @endif
    @if ($hapus)
        <button type="button" @click="hapusData({{ $i }})" class="wt-btn-sm !text-status-dirty">Hapus</button>
    @endif
</div>
