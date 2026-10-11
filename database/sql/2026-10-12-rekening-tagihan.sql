-- Rekening tujuan pembayaran yang tercetak di PDF tagihan bulanan dan surat tagihan instansi.
-- Hanya menambah baris data di settings (tanpa mengubah struktur). Aman dijalankan ulang.
-- Isi nilainya dari halaman Pengaturan (kelompok "Tagihan instansi").
INSERT IGNORE INTO settings (setting_key, value, type, description) VALUES
    ('bank_name', '', 'string', 'Nama bank rekening tujuan pembayaran tagihan instansi'),
    ('bank_account_number', '', 'string', 'Nomor rekening tujuan pembayaran tagihan instansi'),
    ('bank_account_name', '', 'string', 'Nama pemilik rekening tujuan pembayaran tagihan instansi');
