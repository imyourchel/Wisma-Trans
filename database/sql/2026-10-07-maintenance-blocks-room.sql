-- Tambah penanda "kamar tidak bisa dipakai" pada laporan kerusakan.
-- Jalankan sekali di database utama. Proyek ini tidak memakai migration;
-- struktur untuk tes ada di database/schema/mysql-schema.sql.

ALTER TABLE maintenance_requests
  ADD COLUMN blocks_room BOOLEAN NOT NULL DEFAULT 0
  COMMENT '1 = kerusakan membuat kamar tidak bisa dipakai'
  AFTER priority;

-- Data lama: laporan yang kodenya tercatat di log penutupan kamar yang sama (status maintenance),
-- misalnya catatan "AC tidak dingin, lihat MNT-0003" atau "MNT-0006: gagang pintu lepas".
UPDATE maintenance_requests mr
LEFT JOIN facility_units fu ON fu.id = mr.facility_unit_id
JOIN room_status_logs l
  ON l.status = 'maintenance'
 AND l.room_id = COALESCE(fu.room_id, mr.room_id)
 AND l.note LIKE CONCAT('%', mr.request_code, '%')
SET mr.blocks_room = 1
WHERE mr.maintenance_type = 'corrective';
