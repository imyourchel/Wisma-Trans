{{-- Notifikasi singkat --}}
<div x-data class="fixed right-5 top-5 z-[300] flex flex-col items-end gap-2" aria-live="polite">
    <template x-for="t in $store.toasts.items" :key="t.id">
        <div class="max-w-[360px] animate-wt-fade rounded border border-l-4 border-cream-300 bg-cream-50 px-4 py-3 text-sm shadow-wt-lg"
             :style="`border-left-color:${t.warna}`" x-text="t.teks"></div>
    </template>
</div>

{{-- Pesan kilat dari server; dibaca skrip di bawah --}}
<script type="application/json" id="pesan-kilat">@json(array_filter(['status' => session('status'), 'error' => session('error')]))</script>
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.store('toasts', {
            items: [],
            tampil(teks, warna = '#5C9E67', lama = 4000) {
                const id = Math.random().toString(36).slice(2);
                this.items.push({ id, teks, warna });
                setTimeout(() => { this.items = this.items.filter(t => t.id !== id); }, lama);
            },
        });

        // Dari komponen Livewire: $this->dispatch('toast', teks: '...', galat: true) atau peringatan: true (kuning, lebih lama)
        window.addEventListener('toast', (e) => e.detail.peringatan
            ? Alpine.store('toasts').tampil(e.detail.teks, '#E8AC45', 9000)
            : Alpine.store('toasts').tampil(e.detail.teks, e.detail.galat ? '#D4514A' : undefined));

        const kilat = JSON.parse(document.getElementById('pesan-kilat').textContent || '{}');
        Alpine.nextTick(() => {
            if (kilat.status) Alpine.store('toasts').tampil(kilat.status);
            if (kilat.error) Alpine.store('toasts').tampil(kilat.error, '#D4514A');
        });
    });
</script>
