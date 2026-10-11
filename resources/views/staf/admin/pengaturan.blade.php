@php
    $nilai = $settings->map(fn ($s) => $s->value)->all();
@endphp

<x-layouts.staf title="Pengaturan">
{{-- Memakai dataMaster tanpa tabel: formulir langsung dikirim dengan PUT --}}
<div x-data="dataMaster(@js(['baris' => [], 'kosong' => $nilai, 'urlSimpan' => route('staf.pengaturan.update'), 'metode' => 'PUT']))" class="max-w-[960px]">
    <form @submit.prevent="kirim($el)" novalidate class="wt-card">
        @csrf
        <div class="border-b border-cream-300 px-6 py-4 text-sm text-ink-500">
            Perubahan langsung dipakai oleh seluruh halaman, termasuk perhitungan uang muka dan tagihan berikutnya.
            @if ($terakhir)
                <span class="block text-xs">Terakhir diubah {{ $terakhir->updated_at?->translatedFormat('j F Y, H.i') }}{{ $pengubah ? ' oleh '.$pengubah : '' }}.</span>
            @endif
        </div>

        @foreach ($kelompok as $judul => $isi)
            <section class="border-b border-cream-300 px-6 py-5">
                <h2 class="mb-4 mt-0 font-display text-[13px] font-bold uppercase tracking-[.08em]">{{ $judul }}</h2>
                <div class="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-x-6 gap-y-4">
                    @foreach ($isi as $kunci => [$label, $satuan])
                        @php $s = $settings[$kunci] ?? null; @endphp
                        @continue(! $s)
                        <x-staf.field :nama="$kunci" :label="$label">
                            <span class="flex items-center gap-2.5">
                                @if ($s->type === 'string')
                                    <input type="text" name="{{ $kunci }}" x-model="isi.{{ $kunci }}" @input="hapus('{{ $kunci }}')" maxlength="{{ $isi[$kunci][3] }}"
                                           class="wt-input" :class="pesan('{{ $kunci }}') && 'is-invalid'">
                                @else
                                <span class="relative block w-[150px] flex-none">
                                    @if ($satuan === 'Rp')
                                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-ink-500">Rp</span>
                                    @endif
                                    <input type="{{ $s->type === 'time' ? 'time' : 'number' }}" name="{{ $kunci }}" x-model="isi.{{ $kunci }}" @input="hapus('{{ $kunci }}')"
                                           @if ($s->type !== 'time') step="{{ $s->type === 'integer' ? 1 : 'any' }}" min="0" @endif
                                           @class(['wt-input tabular-nums', 'pl-10' => $satuan === 'Rp'])
                                           :class="pesan('{{ $kunci }}') && 'is-invalid'">
                                </span>
                                @if ($satuan !== 'Rp')
                                    <span class="text-[13px] leading-tight text-ink-500">{{ $satuan }}</span>
                                @endif
                                @endif
                            </span>
                        </x-staf.field>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="flex items-center gap-3 px-6 py-4">
            <button type="submit" :disabled="proses" x-text="proses ? 'Menyimpan…' : 'Simpan pengaturan'"
                    class="h-11 rounded border-0 bg-clay-500 px-6 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70">Simpan pengaturan</button>
            <span class="text-xs text-ink-500">Jam memakai zona waktu WITA.</span>
        </div>
    </form>
    {{-- Tidak dipakai di halaman ini, tetapi dibutuhkan dataMaster --}}
    <div x-ref="panel" class="hidden"></div>
</div>
</x-layouts.staf>
