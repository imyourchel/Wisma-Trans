-- Struktur basis data SIM Wisma Trans (hasil dump tanpa data dari database utama).
-- Dipakai RefreshDatabase untuk database tes wisma_trans_test. Proyek ini tidak memakai migration untuk tabel aplikasi.

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ahp_comparisons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ahp_session_id` bigint(20) unsigned NOT NULL,
  `criteria_row_id` bigint(20) unsigned NOT NULL,
  `criteria_column_id` bigint(20) unsigned NOT NULL,
  `comparison_value` decimal(9,6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ahp_comparisons` (`ahp_session_id`,`criteria_row_id`,`criteria_column_id`),
  KEY `idx_ahp_comparisons_criteria_row_id` (`criteria_row_id`),
  KEY `idx_ahp_comparisons_criteria_column_id` (`criteria_column_id`),
  CONSTRAINT `fk_ahpc_col` FOREIGN KEY (`criteria_column_id`) REFERENCES `cleaning_criteria` (`id`),
  CONSTRAINT `fk_ahpc_row` FOREIGN KEY (`criteria_row_id`) REFERENCES `cleaning_criteria` (`id`),
  CONSTRAINT `fk_ahpc_session` FOREIGN KEY (`ahp_session_id`) REFERENCES `ahp_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ahp_criteria_weights` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ahp_session_id` bigint(20) unsigned NOT NULL,
  `criteria_id` bigint(20) unsigned NOT NULL,
  `weight` decimal(10,6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ahp_weights` (`ahp_session_id`,`criteria_id`),
  KEY `idx_ahp_criteria_weights_criteria_id` (`criteria_id`),
  CONSTRAINT `fk_ahpw_criteria` FOREIGN KEY (`criteria_id`) REFERENCES `cleaning_criteria` (`id`),
  CONSTRAINT `fk_ahpw_session` FOREIGN KEY (`ahp_session_id`) REFERENCES `ahp_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ahp_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_name` varchar(80) DEFAULT NULL,
  `calculated_by` bigint(20) unsigned DEFAULT NULL,
  `calculated_at` datetime NOT NULL,
  `lambda_max` decimal(10,6) DEFAULT NULL,
  `consistency_index` decimal(10,6) DEFAULT NULL,
  `random_index` decimal(6,4) DEFAULT NULL,
  `consistency_ratio` decimal(10,6) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ahp_sessions_calculated_by` (`calculated_by`),
  CONSTRAINT `fk_ahp_sessions_calculated_by` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_reminders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned DEFAULT NULL COMMENT 'sasaran pengingat bila tagihan per reservasi',
  `billing_statement_id` bigint(20) unsigned DEFAULT NULL COMMENT 'sasaran pengingat bila tagihan bulanan; tepat satu dari keduanya wajib terisi',
  `reminder_stage` enum('internal','to_institution') NOT NULL,
  `scheduled_date` date NOT NULL,
  `channel` enum('email','whatsapp','letter') NOT NULL,
  `recipient` varchar(150) DEFAULT NULL,
  `status` enum('pending','verified','sent','failed','cancelled') NOT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_billing_reminders_invoice_id` (`invoice_id`),
  KEY `idx_billing_reminders_billing_statement_id` (`billing_statement_id`),
  KEY `idx_billing_reminders_verified_by` (`verified_by`),
  CONSTRAINT `fk_billing_reminders_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_billing_reminders_statement` FOREIGN KEY (`billing_statement_id`) REFERENCES `billing_statements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_billing_reminders_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing_statements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `statement_number` varchar(25) NOT NULL,
  `institution_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `issue_date` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','issued','partially_paid','paid','overdue','cancelled') NOT NULL,
  `issued_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_billing_statements_number` (`statement_number`),
  UNIQUE KEY `uq_billing_statements_period` (`institution_id`,`period_start`,`period_end`),
  KEY `idx_billing_statements_due` (`status`,`due_date`),
  KEY `idx_billing_statements_issued_by` (`issued_by`),
  CONSTRAINT `fk_bs_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  CONSTRAINT `fk_bs_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cleaning_criteria` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(80) NOT NULL,
  `attribute_type` enum('benefit','cost') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cleaning_criteria_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cleaning_priority_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_date` date NOT NULL,
  `generated_at` datetime NOT NULL,
  `generated_by` bigint(20) unsigned DEFAULT NULL,
  `ahp_session_id` bigint(20) unsigned NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cleaning_priority_batches_ahp_session_id` (`ahp_session_id`),
  KEY `idx_cleaning_priority_batches_generated_by` (`generated_by`),
  CONSTRAINT `fk_cpb_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cpb_session` FOREIGN KEY (`ahp_session_id`) REFERENCES `ahp_sessions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cleaning_priority_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cleaning_priority_batch_id` bigint(20) unsigned NOT NULL,
  `room_id` bigint(20) unsigned NOT NULL,
  `preference_score` decimal(12,8) NOT NULL,
  `priority_rank` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cpi_batch_room` (`cleaning_priority_batch_id`,`room_id`),
  KEY `idx_cleaning_priority_items_room_id` (`room_id`),
  CONSTRAINT `fk_cpi_batch` FOREIGN KEY (`cleaning_priority_batch_id`) REFERENCES `cleaning_priority_batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpi_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cleaning_priority_scores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cleaning_priority_item_id` bigint(20) unsigned NOT NULL,
  `criteria_id` bigint(20) unsigned NOT NULL,
  `raw_value` decimal(14,4) NOT NULL,
  `normalized_value` decimal(12,8) NOT NULL,
  `weighted_value` decimal(12,8) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cps_item_criteria` (`cleaning_priority_item_id`,`criteria_id`),
  KEY `idx_cleaning_priority_scores_criteria_id` (`criteria_id`),
  CONSTRAINT `fk_cps_criteria` FOREIGN KEY (`criteria_id`) REFERENCES `cleaning_criteria` (`id`),
  CONSTRAINT `fk_cps_item` FOREIGN KEY (`cleaning_priority_item_id`) REFERENCES `cleaning_priority_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `institution_id` bigint(20) unsigned DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `gender` enum('L','P') DEFAULT NULL COMMENT 'opsional, tidak wajib diisi tamu',
  `date_of_birth` date DEFAULT NULL COMMENT 'opsional, tidak wajib diisi tamu',
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'narahubung yang sudah tidak menjabat dinonaktifkan, riwayatnya tetap utuh',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_phone` (`phone`),
  UNIQUE KEY `uq_customers_user` (`user_id`),
  KEY `idx_customers_institution_id` (`institution_id`),
  CONSTRAINT `fk_customers_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  CONSTRAINT `fk_customers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `facilities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `category` enum('room','public') NOT NULL,
  `is_maintainable` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facilities_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `facility_units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `facility_id` bigint(20) unsigned NOT NULL COMMENT 'jenis fasilitas: AC, WiFi, kloset',
  `room_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = unit berada di area umum',
  `area_label` varchar(50) NOT NULL COMMENT 'kelompok lokasi untuk perawatan massal: Aula, Lantai 1, Lantai 2, Toilet Umum, Lobby',
  `unit_label` varchar(50) NOT NULL COMMENT 'penanda unik unit: AC Aula 1, Router Lt.2',
  `installed_at` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_units_label` (`unit_label`),
  KEY `idx_facility_units_room` (`room_id`,`facility_id`),
  KEY `idx_facility_units_facility_id` (`facility_id`),
  CONSTRAINT `fk_fu_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`),
  CONSTRAINT `fk_fu_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hall_bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_code` varchar(20) NOT NULL,
  `hall_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `reservation_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `start_at` datetime NOT NULL COMMENT 'tanggal dan jam mulai acara',
  `end_at` datetime NOT NULL COMMENT 'tanggal dan jam selesai; mendukung acara lintas hari',
  `participant_count` smallint(5) unsigned DEFAULT NULL,
  `rental_price` decimal(12,2) NOT NULL COMMENT 'harga hasil negosiasi per acara; tidak ada tarif master',
  `purpose` varchar(150) DEFAULT NULL,
  `status` enum('pending','confirmed','rejected','cancelled','completed') NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hall_bookings_code` (`booking_code`),
  KEY `idx_hall_bookings_hall_time` (`hall_id`,`start_at`,`end_at`),
  KEY `idx_hall_bookings_customer_id` (`customer_id`),
  KEY `idx_hall_bookings_reservation_id` (`reservation_id`),
  KEY `idx_hall_bookings_invoice_id` (`invoice_id`),
  KEY `idx_hall_bookings_created_by` (`created_by`),
  CONSTRAINT `fk_hb_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hb_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_hb_hall` FOREIGN KEY (`hall_id`) REFERENCES `halls` (`id`),
  CONSTRAINT `fk_hb_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `fk_hb_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `halls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `capacity` smallint(5) unsigned NOT NULL,
  `description` text DEFAULT NULL,
  `photos` text DEFAULT NULL COMMENT 'daftar nama berkas foto dalam bentuk JSON',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_halls_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `housekeeping_task_assignees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `housekeeping_task_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `workload_share` decimal(6,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hta_task_user` (`housekeeping_task_id`,`user_id`),
  KEY `idx_housekeeping_task_assignees_user_id` (`user_id`),
  CONSTRAINT `fk_hta_task` FOREIGN KEY (`housekeeping_task_id`) REFERENCES `housekeeping_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hta_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `housekeeping_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint(20) unsigned NOT NULL,
  `task_date` date NOT NULL,
  `cleaning_priority_item_id` bigint(20) unsigned DEFAULT NULL,
  `workload_point` decimal(6,2) NOT NULL,
  `assignment_method` enum('greedy','manual') NOT NULL DEFAULT 'greedy',
  `status` enum('assigned','in_progress','completed','verified','cancelled') NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `assigned_by` bigint(20) unsigned DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hktasks_date_room` (`task_date`,`room_id`),
  KEY `idx_housekeeping_tasks_date_status` (`task_date`,`status`),
  KEY `idx_housekeeping_tasks_room_id` (`room_id`),
  KEY `idx_housekeeping_tasks_cleaning_priority_item_id` (`cleaning_priority_item_id`),
  KEY `idx_housekeeping_tasks_assigned_by` (`assigned_by`),
  KEY `idx_housekeeping_tasks_verified_by` (`verified_by`),
  CONSTRAINT `fk_ht_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ht_cpi` FOREIGN KEY (`cleaning_priority_item_id`) REFERENCES `cleaning_priority_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ht_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `fk_ht_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `institutions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `primary_contact_id` bigint(20) unsigned DEFAULT NULL COMMENT 'narahubung utama, tujuan pengiriman tagihan',
  `default_billing_scheme` enum('upfront','monthly_fixed_date','due_after_checkout') NOT NULL,
  `billing_day` tinyint(3) unsigned DEFAULT NULL,
  `due_days` smallint(5) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_institutions_code` (`code`),
  UNIQUE KEY `uq_institutions_contact` (`primary_contact_id`),
  CONSTRAINT `fk_institutions_contact` FOREIGN KEY (`primary_contact_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `item_type` enum('room','fnb','laundry','amenity','hall','late_checkout','other') NOT NULL,
  `description` varchar(150) NOT NULL,
  `service_date` date DEFAULT NULL,
  `quantity` decimal(8,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `discount_type` enum('percent','amount') DEFAULT NULL COMMENT 'diskon khusus baris ini',
  `discount_value` decimal(12,2) DEFAULT NULL,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'potongan rupiah baris ini',
  `subtotal` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invoice_items_invoice_id` (`invoice_id`),
  CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(25) NOT NULL,
  `invoice_type` enum('stay','service','hall') NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `reservation_id` bigint(20) unsigned DEFAULT NULL,
  `institution_id` bigint(20) unsigned DEFAULT NULL,
  `billing_statement_id` bigint(20) unsigned DEFAULT NULL COMMENT 'terisi bila folio ini digabung ke tagihan bulanan instansi',
  `payment_term` enum('immediate','credit') NOT NULL,
  `issue_date` date DEFAULT NULL,
  `period_start` date DEFAULT NULL COMMENT 'awal periode yang ditagih; NULL untuk menginap biasa',
  `period_end` date DEFAULT NULL COMMENT 'akhir periode yang ditagih; NULL untuk menginap biasa',
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_type` enum('percent','amount') DEFAULT NULL COMMENT 'bentuk diskon yang disepakati',
  `discount_value` decimal(12,2) DEFAULT NULL COMMENT 'nilai masukan: 10 berarti 10% bila percent, atau nominal rupiah bila amount',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'potongan dalam rupiah hasil perhitungan; nilai inilah yang tercetak di nota',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','issued','partially_paid','paid','overdue','cancelled') NOT NULL,
  `issued_by` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_number` (`invoice_number`),
  KEY `idx_invoices_reservation` (`reservation_id`),
  KEY `idx_invoices_status_due` (`status`,`due_date`),
  KEY `idx_invoices_institution_status` (`institution_id`,`status`),
  KEY `idx_invoices_customer_id` (`customer_id`),
  KEY `idx_invoices_billing_statement_id` (`billing_statement_id`),
  KEY `idx_invoices_issued_by` (`issued_by`),
  CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_invoices_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  CONSTRAINT `fk_invoices_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`),
  CONSTRAINT `fk_invoices_statement` FOREIGN KEY (`billing_statement_id`) REFERENCES `billing_statements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_code` varchar(20) NOT NULL,
  `maintenance_type` enum('corrective','preventive') NOT NULL,
  `maintenance_schedule_id` bigint(20) unsigned DEFAULT NULL,
  `facility_unit_id` bigint(20) unsigned DEFAULT NULL COMMENT 'corrective: unit fisik yang rusak. preventive: NULL, cakupan diambil dari jadwal',
  `room_id` bigint(20) unsigned DEFAULT NULL COMMENT 'diisi hanya bila facility_unit_id NULL (kerusakan umum pada kamar)',
  `damage_description` text NOT NULL,
  `photos` text DEFAULT NULL COMMENT 'daftar nama berkas foto kerusakan dalam bentuk JSON',
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `blocks_room` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = kerusakan membuat kamar tidak bisa dipakai',
  `reported_by` bigint(20) unsigned DEFAULT NULL,
  `reported_at` datetime NOT NULL,
  `status` enum('reported','in_progress','completed','cancelled') NOT NULL,
  `handled_by` bigint(20) unsigned DEFAULT NULL,
  `vendor_name` varchar(100) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cost` decimal(12,2) DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_maintenance_requests_code` (`request_code`),
  KEY `idx_maintenance_requests_maintenance_schedule_id` (`maintenance_schedule_id`),
  KEY `idx_maintenance_requests_facility_unit_id` (`facility_unit_id`),
  KEY `idx_maintenance_requests_room_id` (`room_id`),
  KEY `idx_maintenance_requests_reported_by` (`reported_by`),
  KEY `idx_maintenance_requests_handled_by` (`handled_by`),
  CONSTRAINT `fk_mr_handled_by` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mr_reported_by` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mr_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `fk_mr_schedule` FOREIGN KEY (`maintenance_schedule_id`) REFERENCES `maintenance_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mr_unit` FOREIGN KEY (`facility_unit_id`) REFERENCES `facility_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `facility_id` bigint(20) unsigned NOT NULL COMMENT 'jenis fasilitas yang dirawat',
  `area_label` varchar(50) NOT NULL COMMENT 'area cakupan; cocok dengan facility_units.area_label',
  `interval_months` tinyint(3) unsigned NOT NULL DEFAULT 6,
  `last_maintenance_date` date DEFAULT NULL,
  `next_due_date` date NOT NULL,
  `vendor_name` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_maintenance_schedules_scope` (`facility_id`,`area_label`),
  KEY `idx_maintenance_schedules_due` (`next_due_date`,`is_active`),
  CONSTRAINT `fk_ms_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_code` varchar(25) NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL COMMENT 'diisi bila pembayaran atas satu folio',
  `billing_statement_id` bigint(20) unsigned DEFAULT NULL COMMENT 'diisi bila pembayaran atas tagihan bulanan; tepat satu dari keduanya wajib terisi',
  `transaction_type` enum('payment','refund') NOT NULL,
  `payment_purpose` enum('down_payment','settlement','service','hall','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` enum('cash','bank_transfer','qris','debit_card','credit_card','virtual_account','retail_outlet') NOT NULL,
  `channel` enum('online','manual') NOT NULL,
  `status` enum('pending','success','failed','expired') NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `midtrans_order_id` varchar(60) DEFAULT NULL,
  `midtrans_transaction_id` varchar(60) DEFAULT NULL,
  `gateway_response` text DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_code` (`payment_code`),
  UNIQUE KEY `uq_payments_midtrans_order` (`midtrans_order_id`),
  KEY `idx_payments_invoice_id` (`invoice_id`),
  KEY `idx_payments_billing_statement_id` (`billing_statement_id`),
  KEY `idx_payments_received_by` (`received_by`),
  CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_statement` FOREIGN KEY (`billing_statement_id`) REFERENCES `billing_statements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservation_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `event_type` enum('status_change','id_card_received','id_card_returned') NOT NULL,
  `status` enum('pending_payment','waiting_approval','confirmed','checked_in','checked_out','cancelled','rejected','no_show') DEFAULT NULL COMMENT 'diisi bila event_type = status_change',
  `changed_by` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL berarti dilakukan sistem',
  `changed_at` datetime NOT NULL,
  `note` varchar(255) DEFAULT NULL COMMENT 'alasan penolakan, pembatalan, atau keterangan lain',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reservation_logs_reservation_id` (`reservation_id`),
  KEY `idx_reservation_logs_changed_by` (`changed_by`),
  KEY `idx_reservation_logs_event` (`reservation_id`,`event_type`,`changed_at`),
  CONSTRAINT `fk_reslog_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reslog_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservation_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `room_count` tinyint(3) unsigned NOT NULL COMMENT 'perkiraan jumlah kamar yang diminta',
  `event_name` varchar(150) DEFAULT NULL COMMENT 'nama kegiatan instansi',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resreq_reservation` (`reservation_id`),
  CONSTRAINT `fk_resreq_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservation_rooms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` bigint(20) unsigned NOT NULL,
  `room_type_id` bigint(20) unsigned NOT NULL,
  `room_id` bigint(20) unsigned DEFAULT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `price_per_night` decimal(12,2) NOT NULL,
  `discount_type` enum('percent','amount') DEFAULT NULL COMMENT 'potongan kesepakatan khusus kamar ini',
  `discount_value` decimal(12,2) DEFAULT NULL,
  `actual_check_in_at` datetime DEFAULT NULL,
  `actual_check_out_at` datetime DEFAULT NULL,
  `status` enum('booked','checked_in','moved','checked_out','cancelled') NOT NULL,
  `moved_from_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reservation_rooms_room_dates` (`room_id`,`check_in_date`,`check_out_date`),
  KEY `idx_reservation_rooms_status_out` (`status`,`check_out_date`),
  KEY `idx_reservation_rooms_reservation_id` (`reservation_id`),
  KEY `idx_reservation_rooms_room_type_id` (`room_type_id`),
  KEY `idx_reservation_rooms_moved_from_id` (`moved_from_id`),
  CONSTRAINT `fk_resroom_moved_from` FOREIGN KEY (`moved_from_id`) REFERENCES `reservation_rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_resroom_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resroom_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `fk_resroom_room_type` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_code` varchar(20) NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `institution_id` bigint(20) unsigned DEFAULT NULL,
  `billing_scheme` enum('upfront','monthly_fixed_date','due_after_checkout') DEFAULT NULL COMMENT 'override per reservasi; NULL = ikut default instansi',
  `service_billing_scheme` enum('satu_nota','dua_nota','tamu_bayar') NOT NULL DEFAULT 'satu_nota' COMMENT 'satu_nota = layanan ikut nota kamar; dua_nota = nota terpisah, keduanya ke instansi; tamu_bayar = layanan dibayar tamu sendiri',
  `booking_channel` enum('online','walk_in') NOT NULL,
  `status` enum('pending_payment','waiting_approval','confirmed','checked_in','checked_out','cancelled','rejected','no_show') NOT NULL,
  `special_request` text DEFAULT NULL,
  `dp_amount_required` decimal(12,2) DEFAULT NULL,
  `dp_due_at` datetime DEFAULT NULL,
  `id_card_held` tinyint(1) NOT NULL DEFAULT 0,
  `id_card_holder_name` varchar(100) DEFAULT NULL COMMENT 'nama pada KTP yang ditahan; bisa berbeda dari pemesan',
  `access_token` varchar(64) DEFAULT NULL COMMENT 'token tautan cek pesanan pada email dan WhatsApp konfirmasi',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservations_code` (`reservation_code`),
  UNIQUE KEY `uq_reservations_access_token` (`access_token`),
  KEY `idx_reservations_customer_id` (`customer_id`),
  KEY `idx_reservations_institution_id` (`institution_id`),
  KEY `idx_reservations_created_by` (`created_by`),
  CONSTRAINT `fk_reservations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reservations_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_reservations_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `room_status_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint(20) unsigned NOT NULL,
  `status` enum('available','occupied_clean','occupied_dirty','vacant_dirty','cleaning','maintenance') NOT NULL COMMENT 'status kamar setelah perubahan',
  `changed_by` bigint(20) unsigned DEFAULT NULL,
  `changed_at` datetime NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_room_status_logs_room_time` (`room_id`,`changed_at`),
  KEY `idx_room_status_logs_changed_by` (`changed_by`),
  CONSTRAINT `fk_rsl_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rsl_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `room_type_facilities` (
  `room_type_id` bigint(20) unsigned NOT NULL,
  `facility_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`room_type_id`,`facility_id`),
  KEY `idx_room_type_facilities_facility_id` (`facility_id`),
  CONSTRAINT `fk_rtf_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rtf_room_type` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `room_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(12,2) NOT NULL,
  `max_capacity` tinyint(3) unsigned NOT NULL,
  `photos` text DEFAULT NULL COMMENT 'daftar nama berkas foto dalam bentuk JSON, mis. ["1.jpg","2.jpg"]; berkas pertama menjadi foto utama',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rooms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `room_number` varchar(10) NOT NULL,
  `room_type_id` bigint(20) unsigned NOT NULL,
  `floor` tinyint(3) unsigned NOT NULL,
  `bed_type` enum('twin_single','double','queen','twin_double','king','twin_queen','triple_single') NOT NULL DEFAULT 'double',
  `status` enum('available','occupied_clean','occupied_dirty','vacant_dirty','cleaning','maintenance') NOT NULL DEFAULT 'available',
  `dirty_since` datetime DEFAULT NULL COMMENT 'kapan kamar terakhir masuk antrean bersih (vacant_dirty / occupied_dirty); sumber kriteria C3',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rooms_number` (`room_number`),
  KEY `idx_rooms_status` (`status`),
  KEY `idx_rooms_room_type_id` (`room_type_id`),
  CONSTRAINT `fk_rooms_room_type` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_order_id` bigint(20) unsigned NOT NULL,
  `service_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(8,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_service_order_items_service_order_id` (`service_order_id`),
  KEY `idx_service_order_items_service_id` (`service_id`),
  CONSTRAINT `fk_soi_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`),
  CONSTRAINT `fk_soi_service_order` FOREIGN KEY (`service_order_id`) REFERENCES `service_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_code` varchar(20) NOT NULL,
  `access_token` varchar(64) DEFAULT NULL COMMENT 'token tautan pantau status pesanan, dikirim lewat WhatsApp',
  `order_type` enum('in_house','outside_guest') NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `reservation_room_id` bigint(20) unsigned DEFAULT NULL COMMENT 'kamar dan periode yang dipesankan; NULL untuk tamu luar',
  `service_start_date` date DEFAULT NULL COMMENT 'diisi untuk layanan berdurasi seperti extra bed',
  `service_end_date` date DEFAULT NULL,
  `serving_type` enum('dine_in','room_service','take_away') NOT NULL COMMENT 'acuan penyajian, terpisah dari penagihan',
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `payment_mode` enum('pay_now','charge_to_room') NOT NULL,
  `order_date` datetime NOT NULL,
  `status` enum('pending','in_progress','ready','delivered','cancelled') NOT NULL COMMENT 'ready = selesai dikerjakan, menunggu diambil tamu',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_orders_code` (`order_code`),
  UNIQUE KEY `uq_service_orders_token` (`access_token`),
  KEY `idx_service_orders_date_status` (`order_date`,`status`),
  KEY `idx_service_orders_customer_id` (`customer_id`),
  KEY `idx_service_orders_reservation_room_id` (`reservation_room_id`),
  KEY `idx_service_orders_invoice_id` (`invoice_id`),
  KEY `idx_service_orders_created_by` (`created_by`),
  CONSTRAINT `fk_so_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_so_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_so_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `fk_so_resroom` FOREIGN KEY (`reservation_room_id`) REFERENCES `reservation_rooms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `services` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL COMMENT 'keterangan isi/porsi untuk tamu',
  `category` enum('fnb','laundry','amenity') NOT NULL COMMENT 'pos pendapatan pada laporan; amenity = perlengkapan kamar seperti extra bed',
  `menu_group` varchar(50) DEFAULT NULL COMMENT 'pengelompokan tampilan menu: Fruit Juice, Tea, Coffee, dst.',
  `department` enum('fnb','housekeeping','front_office') NOT NULL,
  `unit` varchar(20) NOT NULL,
  `price` decimal(12,2) DEFAULT NULL COMMENT 'NULL = harga sesuai ukuran, diisi staf saat memesan',
  `photo` varchar(255) DEFAULT NULL COMMENT 'satu foto menu, opsional',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_sold_out` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = sedang habis (ditandai F&B); kembali 0 saat ditandai tersedia lagi',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `setting_key` varchar(60) NOT NULL,
  `value` varchar(255) NOT NULL,
  `type` enum('string','integer','decimal','boolean','time') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL COMMENT 'pengguna yang terakhir mengubah nilai; NULL bila diisi sistem saat pemasangan',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `idx_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `social_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` enum('google','facebook','apple') NOT NULL,
  `provider_user_id` varchar(191) NOT NULL COMMENT 'ID pengguna dari penyedia, bukan email',
  `provider_email` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_social_provider` (`provider`,`provider_user_id`),
  UNIQUE KEY `uq_social_user_provider` (`user_id`,`provider`),
  CONSTRAINT `fk_social_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_availabilities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `work_date` date NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 bila berhalangan: sakit, izin, atau cuti',
  `max_workload` decimal(6,2) DEFAULT NULL COMMENT 'batas beban kerja bila berbeda dari biasanya',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_availabilities` (`user_id`,`work_date`),
  CONSTRAINT `fk_staff_availabilities_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_roles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `assigned_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_user_roles_role_id` (`role_id`),
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) DEFAULT NULL COMMENT 'NULL bila akun dibuat lewat login Google',
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Migration bawaan Laravel ditandai sudah jalan karena tabelnya sudah ada di dump ini
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('0001_01_01_000000_create_users_table', 1), ('0001_01_01_000001_create_cache_table', 1), ('0001_01_01_000002_create_jobs_table', 1);
