@props(['nama', 'lebar' => 'w-[480px]', 'bisaTambah' => true])
{{-- Panel formulir yang muncul dari kanan. $nama dipakai di judul: "Tambah kamar" / "Ubah kamar". --}}
<div x-show="buka" x-cloak @click="tutup()" x-transition.opacity class="fixed inset-0 z-[340] bg-[rgba(62,42,28,.30)]"></div>
<aside x-ref="panel" x-show="buka" x-cloak @keydown.escape.window="buka && tutup()"
       x-transition:enter="transition duration-200 ease-wt" x-transition:enter-start="translate-x-8 opacity-0" x-transition:enter-end="translate-x-0 opacity-100"
       x-transition:leave="transition duration-150 ease-wt" x-transition:leave-start="opacity-100" x-transition:leave-end="translate-x-8 opacity-0"
       role="dialog" aria-modal="true" aria-labelledby="panel-judul"
       class="fixed inset-y-0 right-0 z-[345] flex {{ $lebar }} max-w-full flex-col border-l pb-[env(safe-area-inset-bottom)] sm:max-w-[94vw] border-cream-300 bg-cream-50 shadow-wt-lg">
    <form @submit.prevent="kirim($el)" novalidate class="flex min-h-0 flex-1 flex-col">
        @csrf
        <div class="flex flex-none items-center gap-3 border-b border-cream-300 px-6 py-4">
            <div id="panel-judul" class="min-w-0 flex-1 font-display text-[17px] font-extrabold uppercase leading-tight tracking-[.04em]"
                 x-text="(sedangUbah ? 'Ubah ' : 'Tambah ') + @js($nama)"></div>
            <button type="button" @click="tutup()" class="flex h-8 w-8 pointer-coarse:h-11 pointer-coarse:w-11 flex-none items-center justify-center rounded border border-cream-300 bg-transparent text-brown-800 hover:bg-cream-200" aria-label="Tutup">
                <x-staf.ikon nama="tutup" ukuran="16" />
            </button>
        </div>
        <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
            {{ $slot }}
        </div>
        <div class="flex flex-none gap-2 border-t border-cream-300 px-6 py-4">
            <button type="submit" :disabled="proses" x-text="proses ? 'Menyimpan…' : 'Simpan'"
                    class="h-11 flex-1 rounded border-0 bg-clay-500 font-display text-[13px] font-bold text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70">Simpan</button>
            <button type="button" @click="tutup()" class="h-11 rounded border border-cream-300 bg-transparent px-5 text-[13px] text-brown-800 hover:bg-cream-200">Batal</button>
        </div>
    </form>
</aside>
