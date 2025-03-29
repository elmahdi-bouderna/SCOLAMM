-- IoT Attendance System Database Backup
-- Created: 2025-03-17 03:53:56
-- Server: 127.0.0.1 via TCP/IP
-- Database: iot

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Table structure for table `attendance`
DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `scan_time` datetime NOT NULL,
  `teacher_scan_id` int(11) DEFAULT NULL,
  `status` enum('present','absent','late') NOT NULL DEFAULT 'present',
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `module_id` (`module_id`),
  KEY `teacher_scan_id` (`teacher_scan_id`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`teacher_scan_id`) REFERENCES `teacher_scans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `attendance` (16 rows)
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('23', '11', '1', '2025-03-16 05:35:45', '7', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('24', '18', '1', '2025-03-16 05:35:54', '7', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('25', '18', '2', '2025-03-16 05:42:28', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('26', '18', '2', '2025-03-16 05:46:32', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('27', '18', '2', '2025-03-16 05:46:38', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('28', '18', '2', '2025-03-16 05:47:47', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('29', '18', '2', '2025-03-16 05:48:19', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('30', '18', '2', '2025-03-16 05:54:00', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('31', '18', '2', '2025-03-16 05:54:06', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('32', '18', '2', '2025-03-16 05:54:24', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('33', '11', '2', '2025-03-16 06:04:34', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('34', '11', '2', '2025-03-16 06:04:46', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('35', '11', '2', '2025-03-16 06:09:34', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('36', '11', '2', '2025-03-16 06:12:04', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('37', '11', '2', '2025-03-16 06:14:46', '9', 'present');
INSERT INTO `attendance` (`id`, `student_id`, `module_id`, `scan_time`, `teacher_scan_id`, `status`) VALUES ('38', '18', '2', '2025-03-16 18:00:33', '9', 'present');

-- Table structure for table `logs`
DROP TABLE IF EXISTS `logs`;
CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `rfid_tag` varchar(50) NOT NULL,
  `scan_time` datetime NOT NULL,
  `status` enum('valid','invalid') NOT NULL DEFAULT 'valid',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `logs` (0 rows)

-- Table structure for table `modules`
DROP TABLE IF EXISTS `modules`;
CREATE TABLE `modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `semester` varchar(10) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `modules` (2 rows)
INSERT INTO `modules` (`id`, `name`, `semester`, `code`, `description`) VALUES ('1', 'Internet of Things', NULL, NULL, NULL);
INSERT INTO `modules` (`id`, `name`, `semester`, `code`, `description`) VALUES ('2', 'Génie Informatique', NULL, NULL, NULL);

-- Table structure for table `professor_module`
DROP TABLE IF EXISTS `professor_module`;
CREATE TABLE `professor_module` (
  `professor_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  PRIMARY KEY (`professor_id`,`module_id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `professor_module_ibfk_1` FOREIGN KEY (`professor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `professor_module_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `professor_module` (2 rows)
INSERT INTO `professor_module` (`professor_id`, `module_id`) VALUES ('19', '1');
INSERT INTO `professor_module` (`professor_id`, `module_id`) VALUES ('19', '2');

-- Table structure for table `sessions`
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `session_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `expires` int(11) unsigned NOT NULL,
  `data` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `sessions` (0 rows)

-- Table structure for table `settings`
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(30) NOT NULL DEFAULT 'general',
  `display_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `input_type` varchar(20) NOT NULL DEFAULT 'text',
  `input_options` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `settings` (31 rows)
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('absent_threshold', '30', 'attendance', 'Absent Threshold', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('admin_email', '', 'email', 'Admin Email', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('attendance_grace_period', '15', 'attendance', 'Attendance Grace Period', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('attendance_verification_method', 'qrcode', 'attendance', 'Attendance Verification Method', '', 'select', '[\"qrcode\",\"nfc\",\"beacon\",\"facial\",\"manual\"]', '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('backup_frequency', 'weekly', 'system', 'Backup Frequency', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('backup_retention', '30', 'system', 'Backup Retention', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('beacon_sensitivity', 'medium', 'attendance', 'Beacon Sensitivity', '', 'select', '[\"low\",\"medium\",\"high\"]', '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('beacon_uuid', '', 'attendance', 'Beacon Uuid', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('date_format', 'Y-m-d', 'general', 'Date Format', '', 'select', '[\"Y-m-d\",\"d\\/m\\/Y\",\"m\\/d\\/Y\",\"d-m-Y\",\"d.m.Y\"]', '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('enable_email_notifications', '0', 'general', 'Enable Email Notifications', '', 'switch', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('enable_facial_recognition', '0', 'general', 'Enable Facial Recognition', '', 'switch', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('enable_location_verification', '0', 'general', 'Enable Location Verification', '', 'switch', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('enable_log_rotation', '1', 'general', 'Enable Log Rotation', '', 'switch', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('enable_registration', '0', 'general', 'Enable Registration', '', 'switch', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('enable_reset_password', '1', 'general', 'Enable Reset Password', '', 'switch', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('institution_name', 'University', 'general', 'Institution Name', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('late_threshold', '10', 'attendance', 'Late Threshold', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('lockout_time', '30', 'general', 'Lockout Time', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('logo_url', '', 'general', 'Logo Url', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('log_rotation_days', '30', 'system', 'Log Rotation Days', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('maintenance_mode', '0', 'system', 'Maintenance Mode', '', 'switch', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('max_login_attempts', '5', 'general', 'Max Login Attempts', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('session_timeout', '120', 'general', 'Session Timeout', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('smtp_encryption', 'tls', 'email', 'Smtp Encryption', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('smtp_host', '', 'email', 'Smtp Host', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('smtp_password', '', 'email', 'Smtp Password', '', 'password', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('smtp_port', '587', 'email', 'Smtp Port', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('smtp_username', '', 'email', 'Smtp Username', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('system_name', 'IoT Attendance System', 'general', 'System Name', '', 'text', NULL, '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('timezone', 'UTC', 'general', 'Timezone', '', 'select', '[\"Africa\\/Abidjan\",\"Africa\\/Accra\",\"Africa\\/Addis_Ababa\",\"Africa\\/Algiers\",\"Africa\\/Asmara\",\"Africa\\/Bamako\",\"Africa\\/Bangui\",\"Africa\\/Banjul\",\"Africa\\/Bissau\",\"Africa\\/Blantyre\",\"Africa\\/Brazzaville\",\"Africa\\/Bujumbura\",\"Africa\\/Cairo\",\"Africa\\/Casablanca\",\"Africa\\/Ceuta\",\"Africa\\/Conakry\",\"Africa\\/Dakar\",\"Africa\\/Dar_es_Salaam\",\"Africa\\/Djibouti\",\"Africa\\/Douala\",\"Africa\\/El_Aaiun\",\"Africa\\/Freetown\",\"Africa\\/Gaborone\",\"Africa\\/Harare\",\"Africa\\/Johannesburg\",\"Africa\\/Juba\",\"Africa\\/Kampala\",\"Africa\\/Khartoum\",\"Africa\\/Kigali\",\"Africa\\/Kinshasa\",\"Africa\\/Lagos\",\"Africa\\/Libreville\",\"Africa\\/Lome\",\"Africa\\/Luanda\",\"Africa\\/Lubumbashi\",\"Africa\\/Lusaka\",\"Africa\\/Malabo\",\"Africa\\/Maputo\",\"Africa\\/Maseru\",\"Africa\\/Mbabane\",\"Africa\\/Mogadishu\",\"Africa\\/Monrovia\",\"Africa\\/Nairobi\",\"Africa\\/Ndjamena\",\"Africa\\/Niamey\",\"Africa\\/Nouakchott\",\"Africa\\/Ouagadougou\",\"Africa\\/Porto-Novo\",\"Africa\\/Sao_Tome\",\"Africa\\/Tripoli\",\"Africa\\/Tunis\",\"Africa\\/Windhoek\",\"America\\/Adak\",\"America\\/Anchorage\",\"America\\/Anguilla\",\"America\\/Antigua\",\"America\\/Araguaina\",\"America\\/Argentina\\/Buenos_Aires\",\"America\\/Argentina\\/Catamarca\",\"America\\/Argentina\\/Cordoba\",\"America\\/Argentina\\/Jujuy\",\"America\\/Argentina\\/La_Rioja\",\"America\\/Argentina\\/Mendoza\",\"America\\/Argentina\\/Rio_Gallegos\",\"America\\/Argentina\\/Salta\",\"America\\/Argentina\\/San_Juan\",\"America\\/Argentina\\/San_Luis\",\"America\\/Argentina\\/Tucuman\",\"America\\/Argentina\\/Ushuaia\",\"America\\/Aruba\",\"America\\/Asuncion\",\"America\\/Atikokan\",\"America\\/Bahia\",\"America\\/Bahia_Banderas\",\"America\\/Barbados\",\"America\\/Belem\",\"America\\/Belize\",\"America\\/Blanc-Sablon\",\"America\\/Boa_Vista\",\"America\\/Bogota\",\"America\\/Boise\",\"America\\/Cambridge_Bay\",\"America\\/Campo_Grande\",\"America\\/Cancun\",\"America\\/Caracas\",\"America\\/Cayenne\",\"America\\/Cayman\",\"America\\/Chicago\",\"America\\/Chihuahua\",\"America\\/Ciudad_Juarez\",\"America\\/Costa_Rica\",\"America\\/Creston\",\"America\\/Cuiaba\",\"America\\/Curacao\",\"America\\/Danmarkshavn\",\"America\\/Dawson\",\"America\\/Dawson_Creek\",\"America\\/Denver\",\"America\\/Detroit\",\"America\\/Dominica\",\"America\\/Edmonton\",\"America\\/Eirunepe\",\"America\\/El_Salvador\",\"America\\/Fort_Nelson\",\"America\\/Fortaleza\",\"America\\/Glace_Bay\",\"America\\/Goose_Bay\",\"America\\/Grand_Turk\",\"America\\/Grenada\",\"America\\/Guadeloupe\",\"America\\/Guatemala\",\"America\\/Guayaquil\",\"America\\/Guyana\",\"America\\/Halifax\",\"America\\/Havana\",\"America\\/Hermosillo\",\"America\\/Indiana\\/Indianapolis\",\"America\\/Indiana\\/Knox\",\"America\\/Indiana\\/Marengo\",\"America\\/Indiana\\/Petersburg\",\"America\\/Indiana\\/Tell_City\",\"America\\/Indiana\\/Vevay\",\"America\\/Indiana\\/Vincennes\",\"America\\/Indiana\\/Winamac\",\"America\\/Inuvik\",\"America\\/Iqaluit\",\"America\\/Jamaica\",\"America\\/Juneau\",\"America\\/Kentucky\\/Louisville\",\"America\\/Kentucky\\/Monticello\",\"America\\/Kralendijk\",\"America\\/La_Paz\",\"America\\/Lima\",\"America\\/Los_Angeles\",\"America\\/Lower_Princes\",\"America\\/Maceio\",\"America\\/Managua\",\"America\\/Manaus\",\"America\\/Marigot\",\"America\\/Martinique\",\"America\\/Matamoros\",\"America\\/Mazatlan\",\"America\\/Menominee\",\"America\\/Merida\",\"America\\/Metlakatla\",\"America\\/Mexico_City\",\"America\\/Miquelon\",\"America\\/Moncton\",\"America\\/Monterrey\",\"America\\/Montevideo\",\"America\\/Montserrat\",\"America\\/Nassau\",\"America\\/New_York\",\"America\\/Nome\",\"America\\/Noronha\",\"America\\/North_Dakota\\/Beulah\",\"America\\/North_Dakota\\/Center\",\"America\\/North_Dakota\\/New_Salem\",\"America\\/Nuuk\",\"America\\/Ojinaga\",\"America\\/Panama\",\"America\\/Paramaribo\",\"America\\/Phoenix\",\"America\\/Port-au-Prince\",\"America\\/Port_of_Spain\",\"America\\/Porto_Velho\",\"America\\/Puerto_Rico\",\"America\\/Punta_Arenas\",\"America\\/Rankin_Inlet\",\"America\\/Recife\",\"America\\/Regina\",\"America\\/Resolute\",\"America\\/Rio_Branco\",\"America\\/Santarem\",\"America\\/Santiago\",\"America\\/Santo_Domingo\",\"America\\/Sao_Paulo\",\"America\\/Scoresbysund\",\"America\\/Sitka\",\"America\\/St_Barthelemy\",\"America\\/St_Johns\",\"America\\/St_Kitts\",\"America\\/St_Lucia\",\"America\\/St_Thomas\",\"America\\/St_Vincent\",\"America\\/Swift_Current\",\"America\\/Tegucigalpa\",\"America\\/Thule\",\"America\\/Tijuana\",\"America\\/Toronto\",\"America\\/Tortola\",\"America\\/Vancouver\",\"America\\/Whitehorse\",\"America\\/Winnipeg\",\"America\\/Yakutat\",\"Antarctica\\/Casey\",\"Antarctica\\/Davis\",\"Antarctica\\/DumontDUrville\",\"Antarctica\\/Macquarie\",\"Antarctica\\/Mawson\",\"Antarctica\\/McMurdo\",\"Antarctica\\/Palmer\",\"Antarctica\\/Rothera\",\"Antarctica\\/Syowa\",\"Antarctica\\/Troll\",\"Antarctica\\/Vostok\",\"Arctic\\/Longyearbyen\",\"Asia\\/Aden\",\"Asia\\/Almaty\",\"Asia\\/Amman\",\"Asia\\/Anadyr\",\"Asia\\/Aqtau\",\"Asia\\/Aqtobe\",\"Asia\\/Ashgabat\",\"Asia\\/Atyrau\",\"Asia\\/Baghdad\",\"Asia\\/Bahrain\",\"Asia\\/Baku\",\"Asia\\/Bangkok\",\"Asia\\/Barnaul\",\"Asia\\/Beirut\",\"Asia\\/Bishkek\",\"Asia\\/Brunei\",\"Asia\\/Chita\",\"Asia\\/Choibalsan\",\"Asia\\/Colombo\",\"Asia\\/Damascus\",\"Asia\\/Dhaka\",\"Asia\\/Dili\",\"Asia\\/Dubai\",\"Asia\\/Dushanbe\",\"Asia\\/Famagusta\",\"Asia\\/Gaza\",\"Asia\\/Hebron\",\"Asia\\/Ho_Chi_Minh\",\"Asia\\/Hong_Kong\",\"Asia\\/Hovd\",\"Asia\\/Irkutsk\",\"Asia\\/Jakarta\",\"Asia\\/Jayapura\",\"Asia\\/Jerusalem\",\"Asia\\/Kabul\",\"Asia\\/Kamchatka\",\"Asia\\/Karachi\",\"Asia\\/Kathmandu\",\"Asia\\/Khandyga\",\"Asia\\/Kolkata\",\"Asia\\/Krasnoyarsk\",\"Asia\\/Kuala_Lumpur\",\"Asia\\/Kuching\",\"Asia\\/Kuwait\",\"Asia\\/Macau\",\"Asia\\/Magadan\",\"Asia\\/Makassar\",\"Asia\\/Manila\",\"Asia\\/Muscat\",\"Asia\\/Nicosia\",\"Asia\\/Novokuznetsk\",\"Asia\\/Novosibirsk\",\"Asia\\/Omsk\",\"Asia\\/Oral\",\"Asia\\/Phnom_Penh\",\"Asia\\/Pontianak\",\"Asia\\/Pyongyang\",\"Asia\\/Qatar\",\"Asia\\/Qostanay\",\"Asia\\/Qyzylorda\",\"Asia\\/Riyadh\",\"Asia\\/Sakhalin\",\"Asia\\/Samarkand\",\"Asia\\/Seoul\",\"Asia\\/Shanghai\",\"Asia\\/Singapore\",\"Asia\\/Srednekolymsk\",\"Asia\\/Taipei\",\"Asia\\/Tashkent\",\"Asia\\/Tbilisi\",\"Asia\\/Tehran\",\"Asia\\/Thimphu\",\"Asia\\/Tokyo\",\"Asia\\/Tomsk\",\"Asia\\/Ulaanbaatar\",\"Asia\\/Urumqi\",\"Asia\\/Ust-Nera\",\"Asia\\/Vientiane\",\"Asia\\/Vladivostok\",\"Asia\\/Yakutsk\",\"Asia\\/Yangon\",\"Asia\\/Yekaterinburg\",\"Asia\\/Yerevan\",\"Atlantic\\/Azores\",\"Atlantic\\/Bermuda\",\"Atlantic\\/Canary\",\"Atlantic\\/Cape_Verde\",\"Atlantic\\/Faroe\",\"Atlantic\\/Madeira\",\"Atlantic\\/Reykjavik\",\"Atlantic\\/South_Georgia\",\"Atlantic\\/St_Helena\",\"Atlantic\\/Stanley\",\"Australia\\/Adelaide\",\"Australia\\/Brisbane\",\"Australia\\/Broken_Hill\",\"Australia\\/Darwin\",\"Australia\\/Eucla\",\"Australia\\/Hobart\",\"Australia\\/Lindeman\",\"Australia\\/Lord_Howe\",\"Australia\\/Melbourne\",\"Australia\\/Perth\",\"Australia\\/Sydney\",\"Europe\\/Amsterdam\",\"Europe\\/Andorra\",\"Europe\\/Astrakhan\",\"Europe\\/Athens\",\"Europe\\/Belgrade\",\"Europe\\/Berlin\",\"Europe\\/Bratislava\",\"Europe\\/Brussels\",\"Europe\\/Bucharest\",\"Europe\\/Budapest\",\"Europe\\/Busingen\",\"Europe\\/Chisinau\",\"Europe\\/Copenhagen\",\"Europe\\/Dublin\",\"Europe\\/Gibraltar\",\"Europe\\/Guernsey\",\"Europe\\/Helsinki\",\"Europe\\/Isle_of_Man\",\"Europe\\/Istanbul\",\"Europe\\/Jersey\",\"Europe\\/Kaliningrad\",\"Europe\\/Kirov\",\"Europe\\/Kyiv\",\"Europe\\/Lisbon\",\"Europe\\/Ljubljana\",\"Europe\\/London\",\"Europe\\/Luxembourg\",\"Europe\\/Madrid\",\"Europe\\/Malta\",\"Europe\\/Mariehamn\",\"Europe\\/Minsk\",\"Europe\\/Monaco\",\"Europe\\/Moscow\",\"Europe\\/Oslo\",\"Europe\\/Paris\",\"Europe\\/Podgorica\",\"Europe\\/Prague\",\"Europe\\/Riga\",\"Europe\\/Rome\",\"Europe\\/Samara\",\"Europe\\/San_Marino\",\"Europe\\/Sarajevo\",\"Europe\\/Saratov\",\"Europe\\/Simferopol\",\"Europe\\/Skopje\",\"Europe\\/Sofia\",\"Europe\\/Stockholm\",\"Europe\\/Tallinn\",\"Europe\\/Tirane\",\"Europe\\/Ulyanovsk\",\"Europe\\/Vaduz\",\"Europe\\/Vatican\",\"Europe\\/Vienna\",\"Europe\\/Vilnius\",\"Europe\\/Volgograd\",\"Europe\\/Warsaw\",\"Europe\\/Zagreb\",\"Europe\\/Zurich\",\"Indian\\/Antananarivo\",\"Indian\\/Chagos\",\"Indian\\/Christmas\",\"Indian\\/Cocos\",\"Indian\\/Comoro\",\"Indian\\/Kerguelen\",\"Indian\\/Mahe\",\"Indian\\/Maldives\",\"Indian\\/Mauritius\",\"Indian\\/Mayotte\",\"Indian\\/Reunion\",\"Pacific\\/Apia\",\"Pacific\\/Auckland\",\"Pacific\\/Bougainville\",\"Pacific\\/Chatham\",\"Pacific\\/Chuuk\",\"Pacific\\/Easter\",\"Pacific\\/Efate\",\"Pacific\\/Fakaofo\",\"Pacific\\/Fiji\",\"Pacific\\/Funafuti\",\"Pacific\\/Galapagos\",\"Pacific\\/Gambier\",\"Pacific\\/Guadalcanal\",\"Pacific\\/Guam\",\"Pacific\\/Honolulu\",\"Pacific\\/Kanton\",\"Pacific\\/Kiritimati\",\"Pacific\\/Kosrae\",\"Pacific\\/Kwajalein\",\"Pacific\\/Majuro\",\"Pacific\\/Marquesas\",\"Pacific\\/Midway\",\"Pacific\\/Nauru\",\"Pacific\\/Niue\",\"Pacific\\/Norfolk\",\"Pacific\\/Noumea\",\"Pacific\\/Pago_Pago\",\"Pacific\\/Palau\",\"Pacific\\/Pitcairn\",\"Pacific\\/Pohnpei\",\"Pacific\\/Port_Moresby\",\"Pacific\\/Rarotonga\",\"Pacific\\/Saipan\",\"Pacific\\/Tahiti\",\"Pacific\\/Tarawa\",\"Pacific\\/Tongatapu\",\"Pacific\\/Wake\",\"Pacific\\/Wallis\",\"UTC\"]', '2025-03-17 02:49:23', '2025-03-17 02:49:23');
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `display_name`, `description`, `input_type`, `input_options`, `created_at`, `updated_at`) VALUES ('time_format', 'H:i:s', 'general', 'Time Format', '', 'select', '[\"H:i:s\",\"h:i:s A\",\"h:i A\"]', '2025-03-17 02:49:23', '2025-03-17 02:49:23');

-- Table structure for table `student_module`
DROP TABLE IF EXISTS `student_module`;
CREATE TABLE `student_module` (
  `student_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  PRIMARY KEY (`student_id`,`module_id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `student_module_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_module_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `student_module` (6 rows)
INSERT INTO `student_module` (`student_id`, `module_id`) VALUES ('11', '1');
INSERT INTO `student_module` (`student_id`, `module_id`) VALUES ('11', '2');
INSERT INTO `student_module` (`student_id`, `module_id`) VALUES ('18', '1');
INSERT INTO `student_module` (`student_id`, `module_id`) VALUES ('18', '2');
INSERT INTO `student_module` (`student_id`, `module_id`) VALUES ('21', '1');
INSERT INTO `student_module` (`student_id`, `module_id`) VALUES ('21', '2');

-- Table structure for table `system_logs`
DROP TABLE IF EXISTS `system_logs`;
CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` datetime NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `level` varchar(20) NOT NULL DEFAULT 'info',
  `module` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timestamp` (`timestamp`),
  KEY `level` (`level`),
  KEY `module` (`module`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `system_logs` (2 rows)
INSERT INTO `system_logs` (`id`, `timestamp`, `user_id`, `username`, `action`, `description`, `ip_address`, `level`, `module`) VALUES ('1', '2025-03-17 03:42:18', '20', 'Mohammed Mikou', 'Table Created', 'System logs table was created', '0', 'info', 'system');
INSERT INTO `system_logs` (`id`, `timestamp`, `user_id`, `username`, `action`, `description`, `ip_address`, `level`, `module`) VALUES ('2', '2025-03-17 03:49:23', '20', 'Mohammed Mikou', 'Table Created', 'Settings table was created', '0', 'info', 'settings');

-- Table structure for table `teacher_scans`
DROP TABLE IF EXISTS `teacher_scans`;
CREATE TABLE `teacher_scans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `professor_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `scan_time` datetime NOT NULL,
  `status` enum('started','absent') NOT NULL DEFAULT 'started',
  PRIMARY KEY (`id`),
  KEY `professor_id` (`professor_id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `teacher_scans_ibfk_1` FOREIGN KEY (`professor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_scans_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `teacher_scans` (7 rows)
INSERT INTO `teacher_scans` (`id`, `professor_id`, `module_id`, `scan_time`, `status`) VALUES ('7', '19', '1', '2025-03-16 05:23:06', 'started');
INSERT INTO `teacher_scans` (`id`, `professor_id`, `module_id`, `scan_time`, `status`) VALUES ('8', '19', '1', '2025-03-16 05:33:20', 'started');
INSERT INTO `teacher_scans` (`id`, `professor_id`, `module_id`, `scan_time`, `status`) VALUES ('9', '19', '2', '2025-03-16 05:42:19', 'started');
INSERT INTO `teacher_scans` (`id`, `professor_id`, `module_id`, `scan_time`, `status`) VALUES ('10', '19', '2', '2025-03-16 06:04:12', 'started');
INSERT INTO `teacher_scans` (`id`, `professor_id`, `module_id`, `scan_time`, `status`) VALUES ('11', '19', '2', '2025-03-16 06:04:20', 'started');
INSERT INTO `teacher_scans` (`id`, `professor_id`, `module_id`, `scan_time`, `status`) VALUES ('12', '20', '2', '2025-03-16 07:17:08', 'started');
INSERT INTO `teacher_scans` (`id`, `professor_id`, `module_id`, `scan_time`, `status`) VALUES ('13', '19', '2', '2025-03-16 18:00:25', 'started');

-- Table structure for table `timetable`
DROP TABLE IF EXISTS `timetable`;
CREATE TABLE `timetable` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_id` int(11) NOT NULL,
  `professor_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  PRIMARY KEY (`id`),
  KEY `module_id` (`module_id`),
  KEY `professor_id` (`professor_id`),
  CONSTRAINT `timetable_ibfk_1` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `timetable_ibfk_2` FOREIGN KEY (`professor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `timetable` (5 rows)
INSERT INTO `timetable` (`id`, `module_id`, `professor_id`, `day_of_week`, `start_time`, `end_time`) VALUES ('4', '1', '19', 'Sunday', '05:22:09', '05:40:09');
INSERT INTO `timetable` (`id`, `module_id`, `professor_id`, `day_of_week`, `start_time`, `end_time`) VALUES ('5', '2', '19', 'Sunday', '05:41:40', '06:15:40');
INSERT INTO `timetable` (`id`, `module_id`, `professor_id`, `day_of_week`, `start_time`, `end_time`) VALUES ('6', '2', '20', '', '06:15:05', '06:30:05');
INSERT INTO `timetable` (`id`, `module_id`, `professor_id`, `day_of_week`, `start_time`, `end_time`) VALUES ('7', '2', '20', 'Monday', '06:15:55', '06:35:55');
INSERT INTO `timetable` (`id`, `module_id`, `professor_id`, `day_of_week`, `start_time`, `end_time`) VALUES ('8', '2', '19', 'Sunday', '17:59:41', '18:02:41');

-- Table structure for table `users`
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `rfid_tag` varchar(50) NOT NULL,
  `role` enum('student','professor','admin') NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `field` varchar(100) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `rfid_tag` (`rfid_tag`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table `users` (5 rows)
INSERT INTO `users` (`id`, `name`, `rfid_tag`, `role`, `email`, `password`, `last_login`, `field`, `active`, `created_at`) VALUES ('11', 'Meryam EL HADEQ', '63c0bec', 'student', 'mery@gmail.com', '$2y$10$jhWXNAHGiR6upOql6W0uBOVW0GpBvlgL1Pe15rZ2lZokbwNOre1oO', NULL, NULL, '1', '2025-03-16 23:46:45');
INSERT INTO `users` (`id`, `name`, `rfid_tag`, `role`, `email`, `password`, `last_login`, `field`, `active`, `created_at`) VALUES ('18', 'EL MAHDI BOUDERNA', 'f34f152a', 'student', 'mestud23@gmail.com', '$2y$10$dxxSu4zlVmP.TI7/0FFftuoZpGPig8Wy.ofBafwFQeM3cE2.7FsVy', NULL, NULL, '1', '2025-03-16 23:46:45');
INSERT INTO `users` (`id`, `name`, `rfid_tag`, `role`, `email`, `password`, `last_login`, `field`, `active`, `created_at`) VALUES ('19', 'Tahiry', '6c674131', 'professor', 'tahiry@gmail.com', '$2y$10$eIltTLKYBLq82nLPSYVjzuxnMHAmvAT4/h.YEFvGfjBRDVKjtD69q', NULL, NULL, '1', '2025-03-16 23:46:45');
INSERT INTO `users` (`id`, `name`, `rfid_tag`, `role`, `email`, `password`, `last_login`, `field`, `active`, `created_at`) VALUES ('20', 'Mohammed Mikou', '', 'admin', 'mikou@gmail.com', '$2y$10$joHhgtksYLCi93mJJa6XVeLP7vx8CEChNiQ3FOjenGLGnRtpuKrgG', NULL, NULL, '1', '2025-03-16 23:46:45');
INSERT INTO `users` (`id`, `name`, `rfid_tag`, `role`, `email`, `password`, `last_login`, `field`, `active`, `created_at`) VALUES ('21', 'Aziz Alyaa', 'test', 'student', 'aziz@gmail.com', '$2y$10$yxVYa7QagvmfdYVyx.DMWukvkx9Ai209XnytAP4uMhGCuIrh8/cXi', NULL, NULL, '1', '2025-03-16 23:46:45');

SET FOREIGN_KEY_CHECKS=1;
