# Barangay Balagan Health Center Online Appointment Scheduling System

This is a focused PHP and MySQL website for online appointment scheduling.

## Main Logic

The health center posts available schedules first. Patients can only request appointments from those posted schedules, so the appointment date and time are controlled by the Barangay Balagan Health Center schedule.

## Features

- Resident portal for registration, login, profile updates, and online appointment requests
- Separate admin dashboard for appointment management, schedule posting, and clinic staff management
- Service list for consultation, prenatal, immunization, newborn/child injection, child growth monitoring, family planning, and follow-up checkup
- Automatic appointment code
- Slot limit per schedule
- Appointment list with patient profile
- Status tracking: pending, confirmed, completed, cancelled, and no show
- Remarks field for health center staff
- Animated glass-style UI with dashboard counters and reveal effects

## Files

- `index.php` resident portal
- `admin.php` separate admin dashboard
- `db.php` database connection and helpers
- `schema.sql` MySQL database and starter data

## Run

1. Start MySQL in XAMPP, or run `setup-barangay-balagan-database.bat` to initialize the database.
2. Double-click `run-barangay-balagan-health-center.bat` in this folder.
3. Open `http://127.0.0.1:8000/`.

If your local MySQL root account has a password, set `BBHC_DB_PASS` in your environment before running either batch file. Do not store the database password in project files.

## Admin Login

The app auto-seeds a default admin account on first run:

- Username: `admin`
- Password: `Admin@12345`

Use `admin.php` for the admin dashboard. Resident users should use `index.php`.

