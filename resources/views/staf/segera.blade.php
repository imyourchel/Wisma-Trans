<x-layouts.staf :title="$judul" :grup="$grup">
    <div class="wt-card mx-auto max-w-[640px] px-6 py-12 text-center">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-cream-200 text-clay-500">
            <x-staf.ikon nama="dashboard" ukuran="24" />
        </div>
        <div class="mt-5 font-display text-lg font-bold uppercase tracking-[.04em]">{{ $judul }}</div>
        <p class="mx-auto mb-0 mt-2 max-w-[400px] text-sm text-ink-500">Halaman ini dibangun pada tahap berikutnya. Menunya sudah disiapkan sesuai hak akses peran Anda.</p>
        <a href="{{ route('staf.dashboard') }}" class="wt-btn-outline mt-6 inline-flex h-10 items-center px-5 text-[13px] !normal-case">Kembali ke dashboard</a>
    </div>
</x-layouts.staf>
