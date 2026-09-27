<x-layouts.guest :title="$judul">
    <div class="wt-container animate-wt-masuk pt-12 pb-16">
        <div class="rounded-md border border-cream-300 bg-cream-50 px-6 py-12 text-center">
            <div class="font-display text-lg font-bold uppercase tracking-[.04em]">{{ $judul }}</div>
            <div class="mt-2 text-ink-500">Halaman ini dibangun pada tahap berikutnya.</div>
            <a href="{{ route('home') }}" class="wt-btn-outline mt-4 h-10 px-5 text-[13px] !normal-case">Kembali ke beranda</a>
        </div>
    </div>
</x-layouts.guest>
