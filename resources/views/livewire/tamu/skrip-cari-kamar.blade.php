@once
@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        /*
         * Pencarian kamar di halaman daftar dan detail: kalender dari wtRentangInap ditambah susunan tamu per kamar.
         * cek() mengirim tanggal dan tamu ("2-0_3-1", lihat PencarianInap) ke komponen Livewire; hasilnya diperbarui tanpa pindah halaman.
         */
        Alpine.data('cariKamar', (cfg) => wtGabung(wtRentangInap({ hariIni: cfg.hariIni, masuk: cfg.masuk, keluar: cfg.keluar }), {
            kamar: cfg.kamar.map(k => ({ ...k })),
            maksTamu: cfg.maksTamu,
            gagal: false,
            buka: false, // panel susunan tamu
            lihatSemua: false, // tampilkan tipe yang tidak muat
            saatBukaKalender() { this.buka = false; },
            init() {
                window.addEventListener('kamar-gagal', () => { this.gagal = true; });
                window.addEventListener('offline', () => { this.gagal = true; });
            },
            get jumlah() { return this.kamar.length; },
            // Teks untuk server; susunan bawaan (1 kamar, 2 dewasa) dikirim kosong seperti di URL
            get teksTamu() {
                const t = this.kamar.map(k => k.dewasa + '-' + k.anak).join('_');
                return t === '2-0' ? '' : t;
            },
            // Versi pendek untuk tombol agar selalu satu baris; rincian lengkap di tooltip
            get ringkasTombol() {
                const dewasa = this.kamar.reduce((a, k) => a + k.dewasa, 0);
                const anak = this.kamar.reduce((a, k) => a + k.anak, 0);
                return this.jumlah + ' kamar · ' + (anak ? (dewasa + anak) + ' tamu' : dewasa + ' dewasa');
            },
            get ringkas() {
                const dewasa = this.kamar.reduce((a, k) => a + k.dewasa, 0);
                const anak = this.kamar.reduce((a, k) => a + k.anak, 0);
                return this.jumlah + ' kamar · ' + dewasa + ' dewasa' + (anak ? ', ' + anak + ' anak' : '');
            },
            // Tanggal atau tamu di kotak berbeda dengan yang sudah dicek server
            get berubah() {
                return this.masuk !== this.$wire.masuk || this.keluar !== this.$wire.keluar || this.teksTamu !== this.$wire.tamu;
            },
            ubahTamu(i, jenis, delta) {
                const k = this.kamar[i], baru = k[jenis] + delta;
                if (baru < (jenis === 'dewasa' ? 1 : 0)) return;
                if (delta > 0 && k.dewasa + k.anak >= this.maksTamu) return;
                k[jenis] = baru;
            },
            tambahKamar() {
                if (this.jumlah < {{ \App\Support\PencarianInap::MAKS_KAMAR }}) this.kamar.push({ dewasa: Math.min(2, this.maksTamu), anak: 0 });
            },
            // Tanggal sudah lengkap: langsung cek ulang dengan susunan baru
            terapkanTamu() {
                this.buka = false;
                if (this.masuk && this.keluar && this.berubah) this.cek();
            },
            cek() {
                this.buka = false;
                this.tutupKalender();
                this.pastikanKeluar();
                this.gagal = false;
                return this.$wire.cek(this.masuk, this.keluar, this.teksTamu);
            },
            // Gulir kembali ke kotak pencarian lalu buka panel tamu
            bukaTamu() {
                const kotak = this.$refs.kotakCari;
                if (kotak) window.scrollTo({ top: kotak.getBoundingClientRect().top + window.scrollY - 96, behavior: 'smooth' });
                setTimeout(() => { this.tutupKalender(); this.buka = true; }, 350);
            },
            // Gulir kembali ke kotak pencarian lalu buka kalender
            ubahTanggal() {
                const kotak = this.$refs.kotakCari;
                if (kotak) window.scrollTo({ top: kotak.getBoundingClientRect().top + window.scrollY - 96, behavior: 'smooth' });
                setTimeout(() => this.bukaKalender('masuk'), 350);
            },
        }));
    });

    // Permintaan Livewire yang gagal (jaringan atau server) ditampilkan sebagai pesan "Coba lagi"
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419) return; // sesi habis: biarkan Livewire meminta muat ulang
                preventDefault();
                window.dispatchEvent(new CustomEvent('kamar-gagal'));
            });
        });
    });
</script>
@endpush
@endonce
