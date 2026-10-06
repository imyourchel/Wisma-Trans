@props(['judul', 'teks' => null, 'gambar' => 'ds/img/bangunan_samping.png'])
{{-- Kepala halaman tamu bergambar, sama gayanya dengan halaman Resto. Slot: tombol aksi. --}}
<section class="relative isolate overflow-hidden bg-brown-900">
    <img src="{{ asset($gambar) }}" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover opacity-40">
    <div class="absolute inset-0 -z-10 bg-[linear-gradient(180deg,rgba(40,27,18,.55)_0%,rgba(40,27,18,.74)_55%,rgba(40,27,18,.94)_100%)]"></div>
    <div class="wt-container flex min-h-[260px] flex-col items-center justify-center py-10 text-center sm:min-h-[300px] sm:py-12">
        <div class="flex items-center gap-3 font-display text-[11px] font-semibold uppercase tracking-[.32em] text-sand-300">
            <span class="h-px w-8 bg-sand-300/60"></span>Wisma Trans · Malili<span class="h-px w-8 bg-sand-300/60"></span>
        </div>
        <h1 class="mb-0 mt-5 font-display text-[36px] font-extrabold uppercase leading-[1.05] tracking-[.04em] text-cream-50 sm:text-[52px]">{{ $judul }}</h1>
        @if ($teks)
            <p class="mb-0 mt-4 max-w-[600px] text-[15px] leading-relaxed text-[rgba(242,235,220,.82)] sm:text-base">{{ $teks }}</p>
        @endif
        @if (! $slot->isEmpty())
            <div class="mt-6 flex flex-wrap justify-center gap-3">{{ $slot }}</div>
        @endif
    </div>
</section>
