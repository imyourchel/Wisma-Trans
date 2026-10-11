import './bootstrap';

/*
 * Indikator proses. Bilah tipis di atas layar tampil saat pindah halaman, saat formulir dikirim, dan saat
 * permintaan Livewire berjalan lebih dari 250 ms. Tombol yang sedang memproses diberi aria-busy (pemutar di CSS);
 * tombol Livewire otomatis mendapat data-loading.
 */
const akar = document.documentElement;
let berjalan = 0;
let tunda;

function mulaiMuat(segera = false) {
    berjalan++;
    clearTimeout(tunda);
    tunda = setTimeout(() => akar.classList.add('wt-memuat'), segera ? 0 : 250);
}

function selesaiMuat() {
    berjalan = Math.max(0, berjalan - 1);
    if (berjalan === 0) {
        clearTimeout(tunda);
        akar.classList.remove('wt-memuat');
    }
}

document.addEventListener('click', (e) => {
    const a = e.target.closest?.('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if ((a.target && a.target !== '_self') || a.hasAttribute('download')) return;
    const tujuan = new URL(a.href, location.href);
    if (tujuan.origin !== location.origin || !/^https?:$/.test(tujuan.protocol)) return;
    if (tujuan.pathname === location.pathname && tujuan.search === location.search) return; // jangkar di halaman yang sama
    mulaiMuat(true);
    setTimeout(selesaiMuat, 15000); // berkas unduhan tidak memindah halaman
});

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || (form.target && form.target !== '_self')) return;
    // Ditunda sedikit agar nilai tombol tetap ikut terkirim
    setTimeout(() => e.submitter?.setAttribute('aria-busy', 'true'));
    mulaiMuat(true);
});

// Kembali lewat tombol Back: halaman dari cache tidak boleh tertinggal dalam keadaan memuat
window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    berjalan = 0;
    akar.classList.remove('wt-memuat');
    document.querySelectorAll('[aria-busy="true"]:not([\:aria-busy])').forEach((el) => el.removeAttribute('aria-busy'));
});

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ respond, fail }) => {
        let tuntas = false;
        const akhiri = () => { if (!tuntas) { tuntas = true; selesaiMuat(); } };
        mulaiMuat();
        respond(akhiri);
        fail(akhiri);
    });

    // Sesi habis (419): ganti dialog confirm() bawaan Livewire yang berbahasa Inggris dengan toast, lalu muat ulang
    let sesiHabis = false;
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status !== 419) return;
            preventDefault();
            if (sesiHabis) return;
            sesiHabis = true;
            window.Alpine?.store('toasts')?.tampil('Sesi Anda berakhir. Halaman dimuat ulang…', '#E8AC45');
            setTimeout(() => window.location.reload(), 2000);
        });
    });
});
