@props(['kunci', 'judul', 'tipe', 'pilih' => [], 'layanan', 'subjudul' => null, 'kasur' => null, 'wajib' => 0])
{{--
    Pilihan Extra bed dan Sarapan tambahan untuk satu kamar (App\Services\Reservasi\TambahanKamar). Dipakai halaman Pesan
    Kamar tamu dan Reservasi Langsung FO; komponen Livewire induknya punya aksi aturTambahan(kunci, jenis, jumlah).
    Harga dari tabel services; judul dan keterangan dari TambahanKamar (extra bed belum termasuk sarapan).
    Sarapan tambahan hanya tampil bila Extra bed dipilih, bawaan tidak dicentang.
    wajib = extra bed yang harus ada karena tamu kamar ini melebihi kapasitas jenis kasurnya; tidak bisa dilepas.
--}}
@php
    use App\Services\Reservasi\TambahanKamar;

    $extra = (int) ($pilih['extra'] ?? 0);
    $sarapan = (int) ($pilih['sarapan'] ?? 0);
    $maksExtra = TambahanKamar::maksExtraBed();
    $maksSarapan = TambahanKamar::maksSarapan($tipe, $extra, $kasur);
    $wajib = (int) $wajib;
    $id = 'tambahan-'.\Illuminate\Support\Str::slug((string) $kunci);
    $aksi = fn (string $jenis, int $n) => 'aturTambahan('.\Illuminate\Support\Js::from($kunci).', \''.$jenis.'\', '.$n.')';
    $baris = array_filter([
        ['extra', TambahanKamar::NAMA['extra'], $layanan['extra'], $extra, $maksExtra, 'extra bed', $wajib],
        $extra > 0 && $layanan['sarapan'] ? ['sarapan', TambahanKamar::NAMA['sarapan'], $layanan['sarapan'], $sarapan, $maksSarapan, 'orang', 0] : null,
    ]);
@endphp
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-cream-300 bg-cream-50']) }} data-tambahan-kamar="{{ $kunci }}">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 border-b border-cream-300 bg-cream-100 px-4 py-2.5">
        <span class="text-[13px] font-semibold text-ink-900">{{ $judul }}</span>
        @if ($subjudul)<span class="text-xs text-ink-500">{{ $subjudul }}</span>@endif
    </div>
    <div class="divide-y divide-cream-300">
        @foreach ($baris as [$jenis, $nama, $s, $n, $maks, $satuan, $min])
            <div wire:key="{{ $id }}-{{ $jenis }}" class="flex flex-wrap items-start gap-x-3 gap-y-2 px-4 py-3" data-pilihan-tambahan="{{ $jenis }}">
                <input type="checkbox" id="{{ $id }}-{{ $jenis }}" @checked($n > 0) @disabled($min > 0) wire:click="{{ $aksi($jenis, $n > 0 ? 0 : 1) }}"
                       class="mt-0.5 h-4 w-4 flex-none cursor-pointer accent-brown-800 disabled:cursor-default">
                <label for="{{ $id }}-{{ $jenis }}" class="min-w-0 flex-[1_1_calc(100%-28px)] cursor-pointer sm:flex-1">
                    <span class="block text-sm font-semibold leading-snug text-ink-900">{{ $nama }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-ink-500" data-keterangan-tambahan>{{ TambahanKamar::keterangan($jenis) }}</span>
                    @if ($min > 0)<span class="mt-1 block text-xs font-semibold text-[#8A5A0B]" data-extra-wajib>Wajib: jumlah tamu kamar ini melebihi kapasitas kasurnya.</span>@endif
                </label>
                <div class="ml-7 flex flex-wrap items-center gap-x-3 gap-y-2 sm:ml-0 sm:flex-col sm:items-end">
                    <span class="whitespace-nowrap text-[13px] font-semibold tabular-nums text-clay-600">{{ TambahanKamar::hargaTeks($s) }}</span>
                    @if ($n > 0 && $maks > 1)
                        <div class="flex items-center gap-0.5 rounded-full border border-cream-300 bg-cream-50 p-0.5" role="group" aria-label="Jumlah {{ $satuan }}">
                            <button type="button" wire:click="{{ $aksi($jenis, $n - 1) }}" @disabled($n <= max(1, $min)) aria-label="Kurangi {{ $nama }}"
                                    class="flex h-7 w-7 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center rounded-full border-0 bg-transparent text-brown-800 hover:bg-cream-200 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-transparent">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg>
                            </button>
                            <span class="min-w-6 text-center text-sm font-bold tabular-nums" data-jumlah-tambahan>{{ $n }}</span>
                            <button type="button" wire:click="{{ $aksi($jenis, $n + 1) }}" @disabled($n >= $maks) aria-label="Tambah {{ $nama }}"
                                    class="flex h-7 w-7 pointer-coarse:h-11 pointer-coarse:w-11 items-center justify-center rounded-full border-0 bg-transparent text-brown-800 hover:bg-cream-200 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-transparent">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            </button>
                        </div>
                        <span class="text-[11px] text-ink-500">maks {{ $maks }} {{ $satuan }}</span>
                    @endif
                </div>
                @if ($jenis === 'sarapan' && $n === 0)
                    {{-- Petunjuk netral, bukan peringatan: extra bed belum termasuk sarapan --}}
                    <p class="m-0 ml-7 flex basis-full items-start gap-1.5 rounded bg-cream-100 px-2.5 py-1.5 text-xs leading-relaxed text-ink-900" data-petunjuk-sarapan>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="mt-px flex-none text-ink-500" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                        {{ TambahanKamar::PETUNJUK_SARAPAN }}
                    </p>
                @endif
            </div>
        @endforeach
    </div>
</div>
