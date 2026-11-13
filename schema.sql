CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(60) NOT NULL,
  last_name VARCHAR(60) NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('patient', 'admin') NOT NULL DEFAULT 'patient',
  birthdate DATE NOT NULL,
  gender ENUM('Female', 'Male', 'Other') NOT NULL,
  address VARCHAR(255) NOT NULL,
  cellphone VARCHAR(30) NOT NULL,
  photo_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_users_username (username),
  UNIQUE KEY unique_users_email (email)
);

-- Safely upgrade databases created by earlier versions that used one full_name field.
SET @has_first_name = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'first_name');
SET @add_first_name = IF(@has_first_name = 0, 'ALTER TABLE users ADD COLUMN first_name VARCHAR(60) NOT NULL DEFAULT '''' AFTER id', 'SELECT 1');
PREPARE add_first_name_statement FROM @add_first_name;
EXECUTE add_first_name_statement;
DEALLOCATE PREPARE add_first_name_statement;

SET @has_last_name = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_name');
SET @add_last_name = IF(@has_last_name = 0, 'ALTER TABLE users ADD COLUMN last_name VARCHAR(60) NOT NULL DEFAULT '''' AFTER first_name', 'SELECT 1');
PREPARE add_last_name_statement FROM @add_last_name;
EXECUTE add_last_name_statement;
DEALLOCATE PREPARE add_last_name_statement;

SET @has_legacy_full_name = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'full_name');
SET @copy_legacy_names = IF(@has_legacy_full_name = 1, "UPDATE users SET first_name = TRIM(SUBSTRING_INDEX(full_name, ' ', 1)), last_name = TRIM(SUBSTRING(full_name, CHAR_LENGTH(SUBSTRING_INDEX(full_name, ' ', 1)) + 1)) WHERE first_name = '' AND last_name = ''", 'SELECT 1');
PREPARE copy_legacy_names_statement FROM @copy_legacy_names;
EXECUTE copy_legacy_names_statement;
DEALLOCATE PREPARE copy_legacy_names_statement;

CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_service_name (name)
);

CREATE TABLE IF NOT EXISTS clinic_staff (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  role ENUM('Doctor', 'Nurse', 'Midwife') NOT NULL,
  specialty VARCHAR(160) NOT NULL,
  contact_number VARCHAR(30) NOT NULL,
  availability VARCHAR(120) NOT NULL DEFAULT 'Available',
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  photo_path VARCHAR(255) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clinic_staff_role (role),
  KEY idx_clinic_staff_status (status)
);

CREATE TABLE IF NOT EXISTS schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id INT UNSIGNED NOT NULL,
  staff_id INT UNSIGNED NULL,
  provider_name VARCHAR(120) NOT NULL,
  provider_role VARCHAR(80) NOT NULL,
  schedule_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  slot_limit INT UNSIGNED NOT NULL DEFAULT 20,
  status ENUM('open', 'closed', 'completed', 'cancelled') NOT NULL DEFAULT 'open',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_schedule_slot (service_id, provider_name, schedule_date, start_time, end_time),
  KEY idx_schedules_date (schedule_date),
  KEY idx_schedules_service (service_id),
  KEY idx_schedules_staff (staff_id),
  CONSTRAINT fk_schedules_service
    FOREIGN KEY (service_id) REFERENCES services(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT fk_schedules_staff
    FOREIGN KEY (staff_id) REFERENCES clinic_staff(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS patients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  birthdate DATE NOT NULL,
  gender ENUM('Female', 'Male', 'Other') NOT NULL,
  address VARCHAR(255) NOT NULL,
  cellphone VARCHAR(30) NOT NULL,
  guardian_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patients_name (full_name)
);

CREATE TABLE IF NOT EXISTS appointments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  patient_id INT UNSIGNED NOT NULL,
  schedule_id INT UNSIGNED NOT NULL,
  appointment_code VARCHAR(30) NOT NULL,
  concern TEXT NULL,
  status ENUM('pending', 'confirmed', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'pending',
  remarks TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_appointment_code (appointment_code),
  KEY idx_appointments_user (user_id),
  KEY idx_appointments_schedule (schedule_id),
  KEY idx_appointments_patient (patient_id),
  KEY idx_appointments_status (status),
  CONSTRAINT fk_appointments_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT fk_appointments_patient
    FOREIGN KEY (patient_id) REFERENCES patients(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT fk_appointments_schedule
    FOREIGN KEY (schedule_id) REFERENCES schedules(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  message VARCHAR(255) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user (user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO services (name, description) VALUES
  ('General Consultation', 'Checkup for fever, cough, headache, body pain, blood pressure check, and other common concerns.'),
  ('Prenatal Checkup', 'Scheduled monitoring for pregnant mothers.'),
  ('Immunization', 'Vaccination for babies, children, and eligible residents.'),
  ('Newborn / Child Injection', 'Injection services for newborns and children based on health center schedule.'),
  ('Child Growth Monitoring', 'Weight, height, nutrition, and child development monitoring.'),
  ('Family Planning', 'Counseling and scheduled family planning services.'),
  ('Follow-up Checkup', 'Return visit for previous health concern or medicine review.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Create schedules and administrator accounts only after clinic staff have approved their details.

