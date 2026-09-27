@props(['maks' => 10, 'ukuranMb' => 4])
{{-- Galeri foto di panel formulir: seret untuk mengurutkan, bintang untuk jadikan utama (alternatif tanpa seret). maks null = tanpa batas --}}
<x-staf.field nama="foto" label="Galeri" tag="div" bantuan="Seret untuk mengatur urutan, atau tekan ★. Foto pertama menjadi foto utama. JPG, PNG, atau WEBP{{ $maks ? ', maksimal '.$maks.' foto dan '.$ukuranMb.' MB per foto' : '' }}.">
    <div class="grid grid-cols-4 gap-2">
        <template x-for="(f, i) in isi.foto" :key="f.jenis + (f.path || f.url)">
            <div draggable="true" @dragstart="seret = i" @dragover.prevent @drop.prevent="lepas(i)" @dragend="seret = null"
                 class="group relative aspect-square cursor-grab overflow-hidden rounded border border-cream-300 bg-cream-200"
                 :class="seret === i && 'opacity-40'">
                <template x-if="f.url"><img :src="f.url" alt="" class="h-full w-full object-cover" draggable="false"></template>
                <template x-if="!f.url">
                    <div class="flex h-full w-full flex-col items-center justify-center gap-1 p-1 text-center text-[10px] leading-tight text-ink-500">
                        <x-staf.ikon nama="foto" ukuran="16" />
                        <span class="w-full truncate" x-text="f.path" :title="f.path + ' (berkas belum diunggah)'"></span>
                    </div>
                </template>
                <span x-show="i === 0" class="absolute bottom-1 left-1 rounded-full bg-[rgba(78,52,35,.82)] px-1.5 py-0.5 text-[9px] font-bold text-cream-150">Utama</span>
                <button type="button" x-show="i !== 0" @click="jadikanUtama(i)" title="Jadikan foto utama"
                        class="absolute left-1 top-1 flex h-5 w-5 items-center justify-center rounded-full border-0 bg-[rgba(78,52,35,.82)] p-0 text-cream-150">
                    <x-staf.ikon nama="bintang" ukuran="11" />
                </button>
                <button type="button" @click="hapusFoto(i)" title="Hapus foto"
                        class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full border-0 bg-[rgba(78,52,35,.82)] p-0 text-cream-150">
                    <x-staf.ikon nama="tutup" ukuran="11" />
                </button>
            </div>
        </template>
        <label @if ($maks) x-show="isi.foto.length < {{ $maks }}" @endif class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded border border-dashed border-cream-400 text-[11px] text-ink-500 hover:bg-cream-200">
            <x-staf.ikon nama="tambah" ukuran="18" />
            Tambah foto
            <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="tambahFoto($event)">
        </label>
    </div>
</x-staf.field>
