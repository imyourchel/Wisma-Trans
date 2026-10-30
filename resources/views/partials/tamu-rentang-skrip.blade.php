<script>
    /*
     * Kalender rentang menginap (komponen x-tamu.rentang-tanggal), dipakai beranda dan halaman kamar.
     * wtRentangInap({ hariIni, masuk?, keluar? }) mengembalikan state Alpine; gabungkan dengan state halaman
     * lewat wtGabung() karena penyebaran objek biasa (...) akan menghitung getter sekali saja.
     * Tanggal disimpan sebagai teks Y-m-d dan dihitung dalam UTC agar tidak bergeser oleh zona waktu perangkat.
     */
    window.wtTanggal = {
        tanggal(iso) { const [y, m, d] = iso.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)); },
        selisih(a, b) { return Math.round((this.tanggal(b) - this.tanggal(a)) / 86400000); },
        tambahBulan(ym, n) {
            const [y, m] = ym.split('-').map(Number);
            const t = new Date(Date.UTC(y, m - 1 + n, 1));
            return t.getUTCFullYear() + '-' + String(t.getUTCMonth() + 1).padStart(2, '0');
        },
        tambahHari(iso, n) { const [y, m, d] = iso.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10); },
    };

    window.wtGabung = (...bagian) => Object.defineProperties({}, Object.assign({}, ...bagian.map((b) => Object.getOwnPropertyDescriptors(b))));

    // maksMalam: 30 untuk reservasi umum (ReservasiService::MAKS_MALAM); pengajuan instansi memberi batasnya sendiri dan pesanMaks
    window.wtRentangInap = ({ hariIni, masuk = null, keluar = null, maksMalam = 30, pesanMaks = null }) => {
        const T = window.wtTanggal;
        const awal = masuk && masuk >= hariIni ? masuk : hariIni;
        return {
            hariIni,
            masuk: awal,
            keluar: keluar && keluar > awal ? keluar : T.tambahHari(awal, 1),
            kalender: false,
            tahap: 'masuk',
            arah: null,
            bulanAwal: awal.slice(0, 7),
            lebar: window.innerWidth,
            get malam() { return this.keluar ? T.selisih(this.masuk, this.keluar) : 0; },
            // Kalender tetap terbuka setelah memilih; ditutup lewat tombol Selesai
            get petunjuk() {
                if (this.tahap === 'keluar') return 'Sekarang pilih tanggal check-out';
                if (!this.keluar) return 'Pilih tanggal check-in';
                return this.teksRentang + ' · ' + this.malam + ' malam';
            },
            get teksRentang() {
                if (!this.keluar) return '';
                const pendek = (iso) => T.tanggal(iso).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
                return pendek(this.masuk) + ' – ' + pendek(this.keluar);
            },
            get bisaMundur() { return this.bulanAwal > this.hariIni.slice(0, 7); },
            get bisaMaju() { return this.bulanAwal < T.tambahBulan(this.hariIni.slice(0, 7), 11); },
            get bulanTampil() {
                const jumlah = this.lebar >= 768 ? 2 : 1;
                const akhir = this.keluar || (this.tahap === 'keluar' && this.arah > this.masuk ? this.arah : null);
                return Array.from({ length: jumlah }, (_, i) => {
                    const kunci = T.tambahBulan(this.bulanAwal, i);
                    const [y, m] = kunci.split('-').map(Number);
                    const kosong = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7; // minggu mulai Senin
                    const jumlahHari = new Date(Date.UTC(y, m, 0)).getUTCDate();
                    const hari = Array.from({ length: kosong }, (_, j) => ({ kunci: kunci + '-k' + j }));
                    for (let d = 1; d <= jumlahHari; d++) {
                        const iso = kunci + '-' + String(d).padStart(2, '0');
                        const ujung = iso === this.masuk || iso === akhir;
                        const dalam = akhir && iso > this.masuk && iso < akhir;
                        // Pita krem menyambung antara check-in dan check-out
                        const pita = !akhir ? null
                            : dalam ? 'inset-x-0'
                            : iso === this.masuk && akhir > this.masuk ? 'left-1/2 right-0'
                            : iso === akhir ? 'left-0 right-1/2' : null;
                        hari.push({ kunci: iso, iso, tgl: d, lalu: iso < this.hariIni, hariIni: iso === this.hariIni, ujung, dalam, pita });
                    }
                    return { kunci, judul: new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }), hari };
                });
            },
            // Di ponsel tahun disembunyikan agar tanggal dan nama hari muat satu baris
            teksTgl(iso, ringkas = false) { return T.tanggal(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', timeZone: 'UTC', ...(this.lebar >= 640 && !ringkas && { year: 'numeric' }) }); },
            namaHari(iso) { return T.tanggal(iso).toLocaleDateString('id-ID', { weekday: 'long', timeZone: 'UTC' }); },
            hariPendek(iso) { return T.tanggal(iso).toLocaleDateString('id-ID', { weekday: 'short', timeZone: 'UTC' }); },
            labelHari(iso) { return T.tanggal(iso).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }); },
            bukaKalender(ujung) {
                this.saatBukaKalender?.();
                this.tahap = ujung === 'keluar' ? 'keluar' : 'masuk';
                this.bulanAwal = (ujung === 'keluar' && this.keluar ? this.keluar : this.masuk).slice(0, 7);
                if (this.lebar >= 768 && ujung === 'keluar' && this.bulanAwal > this.masuk.slice(0, 7)) this.bulanAwal = this.masuk.slice(0, 7);
                this.kalender = true;
            },
            tutupKalender() {
                if (!this.kalender) return;
                this.pastikanKeluar();
                this.kalender = false;
                this.arah = null;
            },
            pastikanKeluar() { if (!this.keluar) this.keluar = T.tambahHari(this.masuk, 1); },
            geser(n) {
                if ((n < 0 && !this.bisaMundur) || (n > 0 && !this.bisaMaju)) return;
                this.bulanAwal = T.tambahBulan(this.bulanAwal, n);
            },
            pilih(iso) {
                if (iso < this.hariIni) return;
                // Klik pertama = check-in; klik kedua setelahnya = check-out
                if (this.tahap === 'masuk' || iso <= this.masuk) {
                    this.masuk = iso;
                    this.keluar = null;
                    this.tahap = 'keluar';
                    return;
                }
                if (T.selisih(this.masuk, iso) > maksMalam) {
                    Alpine.store('toasts').tampil(pesanMaks ?? 'Maksimal ' + maksMalam + ' malam per pemesanan. Hubungi kami untuk menginap lebih lama.', '#E8AC45');
                    return;
                }
                this.keluar = iso;
                this.tahap = 'masuk';
            },
            setDurasi(n) {
                this.keluar = T.tambahHari(this.masuk, n);
                this.tahap = 'masuk';
            },
        };
    };
</script>
