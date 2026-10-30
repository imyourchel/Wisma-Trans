@once
@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        /*
         * Pencarian kamar di halaman daftar dan detail: kalender dari wtRentangInap ditambah susunan tamu per kamar (Tambah kamar).
         * cek() mengirim tanggal dan tamu ("2-2_2-0", lihat PencarianInap) ke komponen Livewire; hasilnya diperbarui tanpa pindah
         * halaman. Susunan itu menjadi pembagian tamu awal di rincian dan dasar "Isi otomatis termurah".
         */
        Alpine.data('cariKamar', (cfg) => wtGabung(wtRentangInap({ hariIni: cfg.hariIni, masuk: cfg.masuk, keluar: cfg.keluar }), {
            kamar: cfg.kamar.map(k => ({ ...k })),
            batasTamu: cfg.batasTamu, // { dewasa, anak } per kamar
            gagal: false,
            buka: false, // panel susunan tamu
            cariTerbuka: cfg.cariTerbuka ?? false, // form pencarian lengkap di halaman daftar
            saatBukaKalender() { this.buka = false; },
            init() {
                window.addEventListener('kamar-gagal', () => { this.gagal = true; });
                window.addEventListener('offline', () => { this.gagal = true; });
            },
            // Teks untuk server; susunan bawaan (1 kamar, 2 dewasa) dikirim kosong seperti di URL
            get teksTamu() {
                const t = this.kamar.map(k => k.dewasa + '-' + k.anak).join('_');
                return t === '2-0' ? '' : t;
            },
            get ringkasTombol() {
                const dewasa = this.kamar.reduce((a, k) => a + k.dewasa, 0);
                const anak = this.kamar.reduce((a, k) => a + k.anak, 0);
                return this.kamar.length + ' kamar · ' + dewasa + ' dewasa' + (anak ? ' · ' + anak + ' anak' : '');
            },
            // Total tamu saja (halaman detail: jumlah kamar mengikuti pilihan kasur)
            get ringkasTamu() {
                const dewasa = this.kamar.reduce((a, k) => a + k.dewasa, 0);
                const anak = this.kamar.reduce((a, k) => a + k.anak, 0);
                return dewasa + ' dewasa' + (anak ? ' · ' + anak + ' anak' : '');
            },
            // Tanggal atau tamu di kotak berbeda dengan yang sudah dicek server
            get berubah() {
                return this.masuk !== this.$wire.masuk || this.keluar !== this.$wire.keluar || this.teksTamu !== this.$wire.tamu;
            },
            ubahTamu(i, jenis, delta) {
                const k = this.kamar[i], baru = k[jenis] + delta;
                if (baru < (jenis === 'dewasa' ? 1 : 0)) return;
                // Dewasa dan anak dibatasi terpisah (RoomType::ATURAN_KAPASITAS); anak tidak dihitung ke kapasitas dewasa
                if (delta > 0 && baru > this.batasTamu[jenis]) return;
                k[jenis] = baru;
            },
            tambahKamar() {
                if (this.kamar.length < {{ \App\Support\PencarianInap::MAKS_KAMAR }}) this.kamar.push({ dewasa: Math.min(2, this.batasTamu.dewasa), anak: 0 });
            },
            // Tanggal sudah lengkap: langsung cek ulang dengan susunan baru
            terapkanTamu() {
                this.buka = false;
                if (this.masuk && this.keluar && this.berubah) this.cek();
            },
            cek() {
                this.buka = false;
                if (this.masuk && this.keluar) this.cariTerbuka = false;
                this.tutupKalender();
                this.pastikanKeluar();
                this.gagal = false;
                return this.$wire.cek(this.masuk, this.keluar, this.teksTamu);
            },
            // Gulir kembali ke kotak pencarian lalu buka panel tamu
            bukaTamu() {
                this.cariTerbuka = true;
                const kotak = this.$refs.kotakCari;
                if (kotak) window.scrollTo({ top: kotak.getBoundingClientRect().top + window.scrollY - 96, behavior: 'smooth' });
                setTimeout(() => { this.tutupKalender(); this.buka = true; }, 350);
            },
            // Gulir kembali ke kotak pencarian lalu buka kalender
            ubahTanggal() {
                this.cariTerbuka = true;
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
