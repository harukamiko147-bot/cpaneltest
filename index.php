<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$pdo = db();
ensure_seed_data($pdo);

$page = (string) ($_GET['page'] ?? 'dashboard');
$message = (string) ($_GET['message'] ?? '');
$type = (string) ($_GET['type'] ?? 'success');

$statusLabels = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show' => 'No Show',
];

function flash_to_page(string $page, string $message, string $type = 'success'): void
{
    redirect_to_page('index.php', $page, $message, $type);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'register') {
        $firstName = post_string('first_name');
        $lastName = post_string('last_name');
        $username = post_string('username');
        $email = post_string('email');
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($firstName === '' || $lastName === '' || $username === '' || $email === '' || $password === '') {
            flash_to_page('register', 'Please complete all required fields.', 'error');
        }

        if ($password !== $confirmPassword) {
            flash_to_page('register', 'Passwords do not match.', 'error');
        }

        if (strlen($password) < 8) {
            flash_to_page('register', 'Use a password with at least 8 characters.', 'error');
        }

        if (!preg_match('/^(?:\+63|0)9\d{9}$/', post_string('cellphone'))) {
            flash_to_page('register', 'Enter a valid Philippine mobile number.', 'error');
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (first_name, last_name, username, email, password_hash, role, birthdate, gender, address, cellphone)
                 VALUES (:first_name, :last_name, :username, :email, :password_hash, :role, :birthdate, :gender, :address, :cellphone)'
            );
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => 'user',
                ':birthdate' => (string) ($_POST['birthdate'] ?? '2000-01-01'),
                ':gender' => (string) ($_POST['gender'] ?? 'Other'),
                ':address' => trim((string) ($_POST['address'] ?? '')),
                ':cellphone' => trim((string) ($_POST['cellphone'] ?? '')),
            ]);

            flash_to_page('login', 'Account registered. You can now log in.');
        } catch (Throwable) {
            flash_to_page('register', 'Registration failed. Username or email may already exist.', 'error');
        }
    }

    if ($action === 'login') {
        $identity = post_string('identity');
        $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1');
        $stmt->execute([
            ':username' => $identity,
            ':email' => $identity,
        ]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string) $user['password_hash'])) {
            login_user($user);
            if (($user['role'] ?? 'user') === 'admin') {
                redirect_to('admin.php?page=dashboard&message=' . urlencode('Admin login successful.') . '&type=success');
            }

            flash_to_page('dashboard', 'Login successful. Welcome back.');
        }

        flash_to_page('login', 'Invalid account or password.', 'error');
    }

    if ($action === 'logout') {
        logout_user();
        redirect_to('index.php?page=login');
    }

    require_login();

    if ($action === 'cancel_appointment') {
        $appointmentId = post_int('appointment_id');
        $user = current_user();
        $stmt = $pdo->prepare(
            "UPDATE appointments a JOIN schedules sc ON sc.id = a.schedule_id
             SET a.status = 'cancelled', a.remarks = 'Cancelled by resident.'
             WHERE a.id = :id AND a.user_id = :user_id AND a.status IN ('pending', 'confirmed') AND sc.schedule_date > CURDATE()"
        );
        $stmt->execute([':id' => $appointmentId, ':user_id' => $user['id']]);
        flash_to_page('dashboard', $stmt->rowCount() ? 'Appointment cancelled. Choose another schedule to rebook.' : 'This appointment can no longer be cancelled.', $stmt->rowCount() ? 'success' : 'error');
    }

    if ($action === 'update_profile') {
        $user = current_user();
        $firstName = post_string('first_name');
        $lastName = post_string('last_name');
        if ($firstName === '' || $lastName === '') {
            flash_to_page('profile', 'First name and last name are required.', 'error');
        }
        $profilePhoto = save_uploaded_image('profile_photo', 'profile', $user['photo_path'] ?? null);

        try {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET first_name = :first_name,
                     last_name = :last_name,
                     email = :email,
                     birthdate = :birthdate,
                     gender = :gender,
                     address = :address,
                     cellphone = :cellphone,
                     photo_path = :photo_path
                 WHERE id = :id'
            );
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => post_string('email'),
                ':birthdate' => (string) ($_POST['birthdate'] ?? '2000-01-01'),
                ':gender' => (string) ($_POST['gender'] ?? 'Other'),
                ':address' => post_string('address'),
                ':cellphone' => post_string('cellphone'),
                ':photo_path' => $profilePhoto,
                ':id' => $user['id'],
            ]);
            refresh_logged_in_user($pdo);
            flash_to_page('profile', 'Profile updated successfully.');
        } catch (Throwable) {
            flash_to_page('profile', 'Profile update failed. Email may already be in use.', 'error');
        }
    }

    if ($action === 'book_appointment') {
        $scheduleId = post_int('schedule_id');
        $scheduleStmt = $pdo->prepare(
            'SELECT sc.*, sv.name AS service_name, st.full_name AS staff_name, st.role AS staff_role
             FROM schedules sc
             JOIN services sv ON sv.id = sc.service_id
             LEFT JOIN clinic_staff st ON st.id = sc.staff_id
             WHERE sc.id = :id AND sc.status = "open" LIMIT 1'
        );
        $scheduleStmt->execute([':id' => $scheduleId]);
        $schedule = $scheduleStmt->fetch();

        if (!$schedule) {
            flash_to_page('dashboard', 'Selected schedule is not available.', 'error');
        }

        if (appointment_count($pdo, $scheduleId) >= (int) $schedule['slot_limit']) {
            flash_to_page('dashboard', 'Selected schedule is already full.', 'error');
        }

        $user = current_user();
        $fullName = post_string('full_name', user_display_name($user));
        $birthdate = (string) ($_POST['birthdate'] ?? '2000-01-01');
        $gender = (string) ($_POST['gender'] ?? 'Other');
        $address = post_string('address');
        $cellphone = post_string('cellphone');
        $guardianName = post_string('guardian_name');
        $concern = post_string('concern');

        $patientStmt = $pdo->prepare(
            'INSERT INTO patients (full_name, birthdate, gender, address, cellphone, guardian_name)
             VALUES (:full_name, :birthdate, :gender, :address, :cellphone, :guardian_name)'
        );
        $patientStmt->execute([
            ':full_name' => $fullName,
            ':birthdate' => $birthdate,
            ':gender' => $gender,
            ':address' => $address,
            ':cellphone' => $cellphone,
            ':guardian_name' => $guardianName,
        ]);

        $patientId = (int) $pdo->lastInsertId();
        $code = 'BBHC-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $appointmentStmt = $pdo->prepare(
            'INSERT INTO appointments (user_id, patient_id, schedule_id, appointment_code, concern, status, remarks)
             VALUES (:user_id, :patient_id, :schedule_id, :appointment_code, :concern, :status, :remarks)'
        );
        $appointmentStmt->execute([
            ':user_id' => $user['id'],
            ':patient_id' => $patientId,
            ':schedule_id' => $scheduleId,
            ':appointment_code' => $code,
            ':concern' => $concern,
            ':status' => 'pending',
            ':remarks' => 'Appointment request submitted online. Please wait for staff review.',
        ]);

        create_notification($pdo, (int) $user['id'], 'Appointment ' . $code . ' was submitted.');

        flash_to_page('dashboard', 'Appointment request submitted. Your code is ' . $code . '.');
    }
}

if (is_logged_in() && is_admin()) {
    redirect_to('admin.php?page=dashboard');
}

if (!is_logged_in() && !in_array($page, ['login', 'register'], true)) {
    redirect_to('index.php?page=login');
}

$services = [];
$schedules = [];
$bookableSchedules = [];
$userAppointments = [];
$myAppointmentsPage = [
    'items' => [],
    'page' => 1,
    'pages' => 1,
    'total' => 0,
    'start' => 0,
    'end' => 0,
];
$profile = null;
$notifications = [];
$stats = [
    'open_schedules' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
];

if (is_logged_in()) {
    $services = $pdo->query('SELECT * FROM services ORDER BY name')->fetchAll();
    $schedules = $pdo->query(
        "SELECT sc.*, sv.name AS service_name, sv.description AS service_description,
                st.full_name AS staff_name, st.role AS staff_role, st.specialty AS staff_specialty,
                (SELECT COUNT(*) FROM appointments a WHERE a.schedule_id = sc.id AND a.status NOT IN ('cancelled', 'no_show')) AS booked_slots
         FROM schedules sc
         JOIN services sv ON sv.id = sc.service_id
         LEFT JOIN clinic_staff st ON st.id = sc.staff_id
         WHERE sc.schedule_date >= CURDATE()
         ORDER BY sc.schedule_date, sc.start_time"
    )->fetchAll();
    $bookableSchedules = array_values(array_filter($schedules, static function (array $schedule): bool {
        return $schedule['status'] === 'open' && ((int) $schedule['slot_limit'] - (int) $schedule['booked_slots']) > 0;
    }));

    $user = current_user();
    $profileStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $profileStmt->execute([':id' => $user['id']]);
    $profile = $profileStmt->fetch();

    $notificationStmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5');
    $notificationStmt->execute([':user_id' => $user['id']]);
    $notifications = $notificationStmt->fetchAll();

    $appointmentsStmt = $pdo->prepare(
        "SELECT a.*, p.full_name, p.birthdate, p.gender, p.address, p.cellphone, p.guardian_name,
                sc.schedule_date, sc.start_time, sc.end_time, sc.provider_name, sc.provider_role,
                sv.name AS service_name
         FROM appointments a
         JOIN patients p ON p.id = a.patient_id
         JOIN schedules sc ON sc.id = a.schedule_id
         JOIN services sv ON sv.id = sc.service_id
         WHERE a.user_id = :user_id
         ORDER BY a.created_at DESC, a.id DESC"
    );
    $appointmentsStmt->execute([':user_id' => $user['id']]);
    $userAppointments = $appointmentsStmt->fetchAll();
    $myAppointmentsPage = paginate_rows($userAppointments, max(1, (int) ($_GET['my_page'] ?? 1)), 2);

    $statsStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_total,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_total
         FROM appointments
         WHERE user_id = :user_id"
    );
    $statsStmt->execute([':user_id' => $user['id']]);
    $userStats = $statsStmt->fetch() ?: [];
    $stats['open_schedules'] = (int) $pdo->query("SELECT COUNT(*) AS total FROM schedules WHERE status = 'open' AND schedule_date >= CURDATE()")->fetch()['total'];
    $stats['pending'] = (int) ($userStats['pending_total'] ?? 0);
    $stats['confirmed'] = (int) ($userStats['confirmed_total'] ?? 0);
    $stats['completed'] = (int) ($userStats['completed_total'] ?? 0);
}

if ($page === 'slip') {
    $slipStmt = $pdo->prepare(
        "SELECT a.appointment_code, a.status, p.full_name, sc.schedule_date, sc.start_time, sc.end_time,
                sv.name AS service_name, sc.provider_name, sc.provider_role
         FROM appointments a
         JOIN patients p ON p.id = a.patient_id
         JOIN schedules sc ON sc.id = a.schedule_id
         JOIN services sv ON sv.id = sc.service_id
         WHERE a.id = :id AND a.user_id = :user_id LIMIT 1"
    );
    $slipStmt->execute([':id' => (int) ($_GET['id'] ?? 0), ':user_id' => current_user()['id']]);
    $slip = $slipStmt->fetch();
    if (!$slip) {
        http_response_code(404);
        exit('Appointment not found.');
    }
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Appointment Slip</title><style>body{font:16px Arial;max-width:680px;margin:48px auto;padding:24px;color:#142b38}h1{margin:0 0 24px}p{border-bottom:1px solid #ddd;padding:10px 0;margin:0}@media print{button{display:none}}</style></head><body>
    <h1>Barangay Balagan Health Center</h1><h2>Appointment Slip</h2>
    <p><b>Code:</b> <?php echo e($slip['appointment_code']); ?></p><p><b>Patient:</b> <?php echo e($slip['full_name']); ?></p><p><b>Service:</b> <?php echo e($slip['service_name']); ?></p><p><b>Schedule:</b> <?php echo e(format_date($slip['schedule_date']) . ' Â· ' . format_time($slip['start_time']) . ' - ' . format_time($slip['end_time'])); ?></p><p><b>Provider:</b> <?php echo e($slip['provider_name'] . ', ' . $slip['provider_role']); ?></p><p><b>Status:</b> <?php echo e(ucfirst($slip['status'])); ?></p><button onclick="window.print()">Print</button>
    </body></html><?php
    exit;
}

function schedule_display_label(array $schedule): string
{
    $remaining = max(0, (int) $schedule['slot_limit'] - (int) $schedule['booked_slots']);
    $provider = $schedule['staff_name'] ?: $schedule['provider_name'];
    $role = $schedule['staff_role'] ?: $schedule['provider_role'];

    return format_date((string) $schedule['schedule_date']) . ' | ' . format_time((string) $schedule['start_time']) . ' - ' . format_time((string) $schedule['end_time']) . ' | ' . $schedule['service_name'] . ' | ' . $provider . ' (' . $role . ') | ' . $remaining . ' slots left';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Barangay Balagan Health Center | Online Appointment System</title>
  <style>
    :root {
      --bg: #edf8fb;
      --bg-2: #e7f6ef;
      --surface: rgba(255, 255, 255, 0.88);
      --surface-strong: #ffffff;
      --surface-soft: rgba(255, 255, 255, 0.65);
      --line: rgba(15, 118, 110, 0.14);
      --text: #0f172a;
      --muted: #567182;
      --primary: #0f766e;
      --primary-2: #12838f;
      --secondary: #2563eb;
      --accent: #14b8a6;
      --success: #16a34a;
      --warning: #d97706;
      --danger: #dc2626;
      --shadow: 0 18px 60px rgba(15, 23, 42, 0.10);
      --shadow-soft: 0 10px 30px rgba(15, 23, 42, 0.07);
      --radius-xl: 28px;
      --radius-lg: 22px;
      --radius-md: 16px;
      --radius-sm: 12px;
      --max: 1280px;
      --serif: "Georgia", "Times New Roman", serif;
      --sans: "Trebuchet MS", "Segoe UI", "Helvetica Neue", sans-serif;
    }

    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      margin: 0;
      font-family: var(--sans);
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(20, 184, 166, 0.14), transparent 28%),
        radial-gradient(circle at top right, rgba(37, 99, 235, 0.12), transparent 24%),
        linear-gradient(180deg, var(--bg), var(--bg-2));
      min-height: 100vh;
      overflow-x: hidden;
    }
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      background-image:
        linear-gradient(rgba(15, 118, 110, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(15, 118, 110, 0.04) 1px, transparent 1px);
      background-size: 42px 42px;
      mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.55), transparent 90%);
      opacity: 0.45;
    }
    a { color: inherit; text-decoration: none; }
    button, input, select, textarea { font: inherit; }

    .glow {
      position: fixed;
      inset: auto;
      border-radius: 999px;
      pointer-events: none;
      filter: blur(36px);
      opacity: 0.55;
      z-index: 0;
    }
    .glow.one { width: 260px; height: 260px; background: rgba(20, 184, 166, 0.16); top: -90px; right: -80px; }
    .glow.two { width: 220px; height: 220px; background: rgba(37, 99, 235, 0.12); bottom: 10%; left: -70px; }

    .shell {
      width: min(var(--max), calc(100% - 32px));
      margin: 0 auto;
      position: relative;
      z-index: 1;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 18px 0 8px;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .brand-mark {
      width: 54px;
      height: 54px;
      border-radius: 18px;
      display: grid;
      place-items: center;
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.92), rgba(222, 245, 243, 0.88));
      box-shadow: var(--shadow-soft);
      border: 1px solid rgba(15, 118, 110, 0.12);
      position: relative;
      overflow: hidden;
      flex: 0 0 auto;
    }
    .brand-mark::before,
    .brand-mark::after {
      content: "";
      position: absolute;
      inset: auto;
      border-radius: 999px;
    }
    .brand-mark::before {
      width: 38px;
      height: 10px;
      background: linear-gradient(90deg, transparent 0, transparent 20%, rgba(18, 131, 143, 0.95) 20%, rgba(18, 131, 143, 0.95) 80%, transparent 80%);
      transform: rotate(90deg);
    }
    .brand-mark::after {
      width: 38px;
      height: 10px;
      background: linear-gradient(90deg, transparent 0, transparent 20%, rgba(18, 131, 143, 0.95) 20%, rgba(18, 131, 143, 0.95) 80%, transparent 80%);
    }
    .brand-copy strong {
      display: block;
      font-family: var(--serif);
      font-size: 1.05rem;
      line-height: 1.15;
      letter-spacing: 0.2px;
    }
    .brand-copy span {
      color: var(--muted);
      font-size: 0.9rem;
    }
    .header-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.8);
      border: 1px solid rgba(15, 118, 110, 0.12);
      box-shadow: var(--shadow-soft);
      color: var(--muted);
      font-size: 0.92rem;
      white-space: nowrap;
    }
    .pill strong { color: var(--text); font-weight: 700; }

    .hero {
      margin-top: 12px;
      padding: 20px 0 28px;
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 22px;
      align-items: stretch;
    }
    .hero-left,
    .hero-right,
    .card,
    .panel,
    .auth-card {
      background: var(--surface);
      border: 1px solid rgba(255, 255, 255, 0.72);
      backdrop-filter: blur(14px);
      box-shadow: var(--shadow);
      border-radius: var(--radius-xl);
    }
    .hero-left {
      padding: 28px;
      position: relative;
      overflow: hidden;
    }
    .hero-left::before {
      content: "";
      position: absolute;
      inset: auto -70px -90px auto;
      width: 240px;
      height: 240px;
      background: radial-gradient(circle, rgba(20, 184, 166, 0.18), transparent 70%);
      pointer-events: none;
    }
    .hero-left::after {
      content: "";
      position: absolute;
      inset: 18px 18px auto auto;
      width: 120px;
      height: 120px;
      background: radial-gradient(circle, rgba(37, 99, 235, 0.12), transparent 72%);
      pointer-events: none;
    }
    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--primary);
      background: rgba(20, 184, 166, 0.08);
      border: 1px solid rgba(20, 184, 166, 0.16);
      padding: 8px 12px;
      border-radius: 999px;
      font-size: 0.88rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      position: relative;
      z-index: 1;
      width: fit-content;
    }
    h1 {
      margin: 18px 0 12px;
      font-family: var(--serif);
      font-size: clamp(2.2rem, 5vw, 4.4rem);
      line-height: 0.98;
      letter-spacing: -0.03em;
      position: relative;
      z-index: 1;
      max-width: 12ch;
    }
    .hero-copy {
      margin: 0;
      max-width: 62ch;
      color: var(--muted);
      font-size: 1.02rem;
      line-height: 1.7;
      position: relative;
      z-index: 1;
    }
    .hero-stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-top: 22px;
      position: relative;
      z-index: 1;
    }
    .stat {
      background: rgba(255, 255, 255, 0.78);
      border: 1px solid rgba(15, 118, 110, 0.10);
      border-radius: 20px;
      padding: 16px;
      min-height: 110px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .stat span {
      color: var(--muted);
      font-size: 0.88rem;
    }
    .stat strong {
      font-size: 1.7rem;
      letter-spacing: -0.04em;
    }
    .cta-row {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 24px;
      position: relative;
      z-index: 1;
    }
    .btn {
      appearance: none;
      border: 0;
      border-radius: 16px;
      padding: 14px 18px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      cursor: pointer;
      transition: transform 180ms ease, box-shadow 180ms ease, background 180ms ease, border-color 180ms ease;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    .btn:hover { transform: translateY(-1px); }
    .btn-primary {
      color: #fff;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      box-shadow: 0 16px 30px rgba(37, 99, 235, 0.18);
    }
    .btn-secondary {
      color: var(--primary);
      background: rgba(255, 255, 255, 0.86);
      border: 1px solid rgba(15, 118, 110, 0.14);
    }
    .hero-right {
      padding: 22px;
      display: grid;
      grid-template-rows: auto auto 1fr;
      gap: 14px;
    }
    .panel-head {
      display: flex;
      justify-content: space-between;
      align-items: start;
      gap: 12px;
    }
    .panel-head h2,
    .section-head h2 {
      margin: 0;
      font-family: var(--serif);
      letter-spacing: -0.02em;
      font-size: 1.55rem;
    }
    .panel-head p,
    .section-head p {
      margin: 6px 0 0;
      color: var(--muted);
      line-height: 1.6;
    }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 9px 12px;
      border-radius: 999px;
      background: rgba(22, 163, 74, 0.08);
      color: var(--success);
      font-weight: 700;
      font-size: 0.88rem;
      white-space: nowrap;
    }
    .status-badge::before {
      content: "";
      width: 9px;
      height: 9px;
      border-radius: 999px;
      background: currentColor;
      box-shadow: 0 0 0 5px rgba(22, 163, 74, 0.12);
    }
    .availability {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    .availability-card {
      background: rgba(255, 255, 255, 0.8);
      border: 1px solid rgba(15, 118, 110, 0.10);
      border-radius: 18px;
      padding: 14px;
    }
    .availability-card span {
      display: block;
      color: var(--muted);
      font-size: 0.85rem;
    }
    .availability-card strong {
      display: block;
      margin-top: 4px;
      font-size: 1.3rem;
    }
    .appointment-card {
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.88));
      border: 1px solid rgba(15, 118, 110, 0.11);
      border-radius: 20px;
      padding: 18px;
      margin-top: 2px;
    }
    .appointment-card h3 {
      margin: 0 0 8px;
      font-size: 1.1rem;
    }
    .appointment-card p {
      margin: 0 0 14px;
      color: var(--muted);
      line-height: 1.6;
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    .field {
      display: flex;
      flex-direction: column;
      gap: 7px;
    }
    .field label {
      font-size: 0.88rem;
      font-weight: 700;
      color: #294454;
    }
    .field input,
    .field select,
    .field textarea {
      width: 100%;
      border: 1px solid rgba(15, 118, 110, 0.16);
      background: rgba(255, 255, 255, 0.94);
      border-radius: 14px;
      padding: 13px 14px;
      color: var(--text);
      outline: none;
      transition: border-color 180ms ease, box-shadow 180ms ease, transform 180ms ease;
    }
    .field input:focus,
    .field select:focus,
    .field textarea:focus {
      border-color: rgba(20, 184, 166, 0.55);
      box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.12);
    }
    .field textarea {
      min-height: 104px;
      resize: vertical;
    }
    .field.full { grid-column: 1 / -1; }
    .form-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 10px;
    }
    .microcopy {
      color: var(--muted);
      font-size: 0.88rem;
      line-height: 1.5;
    }
    .section {
      padding: 0 0 24px;
    }
    .section-head {
      display: flex;
      align-items: end;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 14px;
    }
    .section-head .hint {
      color: var(--muted);
      font-size: 0.94rem;
      max-width: 52ch;
      line-height: 1.6;
    }
    .dashboard {
      display: grid;
      grid-template-columns: 0.95fr 1.05fr;
      gap: 22px;
      align-items: start;
    }
    .card {
      padding: 22px;
    }
    .toolbar {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 12px;
    }
    .chip {
      border: 1px solid rgba(15, 118, 110, 0.14);
      background: rgba(255, 255, 255, 0.8);
      color: #294454;
      border-radius: 999px;
      padding: 10px 13px;
      cursor: pointer;
      transition: background 180ms ease, color 180ms ease, transform 180ms ease, border-color 180ms ease;
    }
    .chip:hover { transform: translateY(-1px); }
    .chip.active {
      color: #fff;
      border-color: transparent;
      background: linear-gradient(135deg, var(--primary), var(--primary-2));
    }
    .slot-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      margin-top: 16px;
    }
    .slot {
      border-radius: 18px;
      padding: 14px;
      background: rgba(255, 255, 255, 0.86);
      border: 1px solid rgba(15, 118, 110, 0.12);
      display: flex;
      justify-content: space-between;
      align-items: start;
      gap: 10px;
      cursor: pointer;
      transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease;
    }
    .slot:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
    }
    .slot.selected {
      border-color: rgba(20, 184, 166, 0.65);
      box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.10);
    }
    .slot.full {
      opacity: 0.58;
      cursor: not-allowed;
    }
    .slot strong {
      display: block;
      font-size: 1rem;
      margin-bottom: 4px;
    }
    .slot small {
      color: var(--muted);
      line-height: 1.5;
    }
    .capacity {
      font-weight: 800;
      color: var(--primary);
      white-space: nowrap;
      font-size: 0.9rem;
    }
    .grid-2 {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .mini-card {
      padding: 18px;
      border-radius: 20px;
      background: rgba(255, 255, 255, 0.86);
      border: 1px solid rgba(15, 118, 110, 0.11);
    }
    .mini-card h3 {
      margin: 0 0 8px;
      font-size: 1.02rem;
    }
    .mini-card p {
      margin: 0;
      color: var(--muted);
      line-height: 1.6;
      font-size: 0.95rem;
    }
    .doctor {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 0;
      border-top: 1px solid rgba(15, 118, 110, 0.1);
    }
    .doctor:first-of-type {
      border-top: 0;
      padding-top: 2px;
    }
    .portrait {
      width: 54px;
      height: 54px;
      border-radius: 18px;
      display: grid;
      place-items: center;
      background: linear-gradient(145deg, rgba(20, 184, 166, 0.14), rgba(37, 99, 235, 0.12));
      color: var(--primary);
      font-weight: 900;
      font-size: 1.05rem;
      flex: 0 0 auto;
    }
    .doctor strong {
      display: block;
      margin-bottom: 4px;
    }
    .doctor span {
      color: var(--muted);
      font-size: 0.92rem;
      line-height: 1.45;
    }
    .queue {
      display: grid;
      gap: 12px;
      margin-top: 16px;
    }
    .queue-item {
      border-radius: 18px;
      padding: 14px 16px;
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(15, 118, 110, 0.10);
      display: grid;
      grid-template-columns: 110px 1fr auto;
      gap: 14px;
      align-items: center;
    }
    .token {
      font-weight: 900;
      letter-spacing: 0.05em;
      color: var(--primary);
    }
    .queue-item p {
      margin: 4px 0 0;
      color: var(--muted);
      line-height: 1.45;
      font-size: 0.94rem;
    }
    .tag {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 7px 10px;
      border-radius: 999px;
      font-size: 0.84rem;
      font-weight: 700;
      white-space: nowrap;
      justify-self: end;
    }
    .tag.pending { background: rgba(217, 119, 6, 0.10); color: var(--warning); }
    .tag.confirmed { background: rgba(37, 99, 235, 0.10); color: var(--secondary); }
    .tag.completed { background: rgba(22, 163, 74, 0.10); color: var(--success); }
    .tag.cancelled,
    .tag.no_show { background: rgba(220, 38, 38, 0.10); color: var(--danger); }
    .row-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: end;
    }
    .tiny-btn {
      border: 1px solid rgba(15, 118, 110, 0.14);
      background: rgba(255, 255, 255, 0.84);
      color: #294454;
      border-radius: 999px;
      padding: 8px 11px;
      cursor: pointer;
      transition: background 180ms ease, transform 180ms ease, border-color 180ms ease;
      font-size: 0.84rem;
      font-weight: 700;
    }
    .tiny-btn:hover { transform: translateY(-1px); }
    .alert {
      margin: 14px 0 0;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid #bbf7d0;
      background: #f0fdf4;
      color: #166534;
    }
    .alert.error {
      border-color: #fecaca;
      background: #fff1f2;
      color: #b91c1c;
    }
    .table-wrap {
      overflow: auto;
      border-radius: var(--radius-xl);
      background: rgba(255, 255, 255, 0.8);
      border: 1px solid rgba(15, 118, 110, 0.10);
      box-shadow: var(--shadow-soft);
    }
    table {
      width: 100%;
      min-width: 1120px;
      border-collapse: collapse;
    }
    th, td {
      padding: 12px;
      border-bottom: 1px solid var(--line);
      text-align: left;
      vertical-align: top;
      font-size: 14px;
    }
    th {
      background: rgba(236, 253, 250, 0.95);
      color: #34495d;
      position: sticky;
      top: 0;
      z-index: 1;
    }
    td strong {
      display: block;
      margin-bottom: 4px;
    }
    .inline-form {
      display: grid;
      gap: 8px;
      min-width: 210px;
    }
    .empty {
      padding: 20px;
      color: var(--muted);
    }
    .pagination {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 14px;
    }
    .page-link,
    .page-ellipsis {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 42px;
      height: 42px;
      padding: 0 12px;
      border-radius: 12px;
      border: 1px solid rgba(15, 118, 110, 0.14);
      background: rgba(255, 255, 255, 0.88);
      color: #294454;
      font-weight: 800;
    }
    .page-link.active {
      color: #fff;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      border-color: transparent;
    }
    .page-link.disabled {
      opacity: 0.45;
      pointer-events: none;
    }
    .page-ellipsis {
      min-width: auto;
      background: transparent;
      border-color: transparent;
      padding: 0 6px;
    }
    .profile-top {
      display: grid;
      grid-template-columns: 180px 1fr;
      gap: 18px;
      align-items: start;
    }
    .avatar {
      width: 160px;
      height: 160px;
      border-radius: 24px;
      object-fit: cover;
      border: 1px solid var(--line);
      background: var(--surface-soft);
      display: grid;
      place-items: center;
      color: var(--muted);
      font-weight: 800;
    }
    footer {
      color: var(--muted);
      padding: 26px 0 36px;
      border-top: 1px solid var(--line);
      margin-top: 18px;
    }

    .auth-grid {
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 24px;
    }
    .auth-card {
      width: min(1020px, 100%);
      display: grid;
      grid-template-columns: 0.95fr 1.05fr;
      overflow: hidden;
    }
    .auth-intro {
      background:
        radial-gradient(circle at top left, rgba(20, 184, 166, 0.16), transparent 28%),
        linear-gradient(165deg, rgba(15, 118, 110, 1), rgba(18, 131, 143, 0.95));
      color: #fff;
      padding: 34px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 24px;
      position: relative;
      overflow: hidden;
    }
    .auth-intro::after {
      content: "";
      position: absolute;
      inset: auto -40px -40px auto;
      width: 220px;
      height: 220px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.12);
      filter: blur(14px);
    }
    .auth-intro p {
      color: rgba(235, 255, 253, 0.88);
      line-height: 1.7;
    }
    .auth-intro .pill {
      background: rgba(255, 255, 255, 0.12);
      border-color: rgba(255, 255, 255, 0.15);
      color: rgba(255, 255, 255, 0.92);
      box-shadow: none;
    }
    .auth-intro .pill strong { color: #fff; }
    .auth-panel {
      padding: 30px;
      background: rgba(255, 255, 255, 0.92);
      display: grid;
      gap: 12px;
    }
    .auth-panel h2 {
      margin: 0;
      font-family: var(--serif);
      letter-spacing: -0.02em;
      font-size: 1.8rem;
    }
    .auth-panel .muted,
    .muted { color: var(--muted); line-height: 1.6; }

    @media (max-width: 1080px) {
      .hero,
      .dashboard,
      .auth-card {
        grid-template-columns: 1fr;
      }
      .hero-stats,
      .slot-grid,
      .grid-2,
      .availability,
      .form-grid,
      .profile-top {
        grid-template-columns: 1fr;
      }
      .topbar,
      .section-head {
        align-items: start;
        flex-direction: column;
      }
      h1 { font-size: 2.9rem; }
      table { min-width: 980px; }
    }

    .reveal {
      opacity: 0;
      transform: translateY(18px);
      transition: opacity 700ms ease, transform 700ms ease;
    }
    .reveal.is-visible {
      opacity: 1;
      transform: translateY(0);
    }

    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
      .reveal,
      .btn,
      .chip,
      .slot,
      .tiny-btn {
        transition: none !important;
      }
    }
  </style>
</head>
<body>
  <div class="glow one"></div>
  <div class="glow two"></div>

<?php if (!is_logged_in() && in_array($page, ['login', 'register'], true)): ?>
  <main class="auth-grid">
    <section class="auth-card reveal">
      <div class="auth-intro">
        <div>
          <div class="brand" style="margin-bottom: 22px;">
            <div class="brand-mark" aria-hidden="true"></div>
            <div class="brand-copy">
              <strong>Barangay Balagan Health Center</strong>
              <span>Online Appointment System</span>
            </div>
          </div>
          <span class="pill">Patient portal</span>
          <h1 style="color:#fff; margin-top: 18px; max-width: 11ch;">Book your visit with ease.</h1>
        </div>
      </div>
      <div class="auth-panel">
        <?php if ($message !== ''): ?><div class="alert <?php echo $type === 'error' ? 'error' : ''; ?>"><?php echo e($message); ?></div><?php endif; ?>
        <?php if ($page === 'register'): ?>
          <h2>Create Account</h2>
          <form method="post" class="form-grid">
            <input type="hidden" name="action" value="register">
            <div class="field"><label>First name</label><input name="first_name" autocomplete="given-name" required autofocus></div>
            <div class="field"><label>Last name</label><input name="last_name" autocomplete="family-name" required></div>
            <div class="field"><label>Username</label><input name="username" required></div>
            <div class="field"><label>Email</label><input name="email" type="email" required></div>
            <div class="field"><label>Cellphone</label><input name="cellphone" required></div>
            <div class="field"><label>Birthdate</label><input name="birthdate" type="date" required></div>
            <div class="field"><label>Gender</label><select name="gender"><option>Female</option><option>Male</option><option>Other</option></select></div>
            <div class="field full"><label>Address</label><input name="address" required></div>
            <div class="field"><label>Password</label><input name="password" type="password" required></div>
            <div class="field"><label>Confirm password</label><input name="confirm_password" type="password" required></div>
            <div class="field full"><button class="btn btn-primary" type="submit">Register account</button></div>
          </form>
          <p class="muted">Already registered? <a href="index.php?page=login"><strong>Log in here</strong></a>.</p>
        <?php else: ?>
          <h2>Log In</h2>
          <form method="post" class="form-grid">
            <input type="hidden" name="action" value="login">
            <div class="field full"><label>Username or email</label><input name="identity" required autofocus></div>
            <div class="field full"><label>Password</label><input name="password" type="password" required></div>
            <div class="field full"><button class="btn btn-primary" type="submit">Log in</button></div>
          </form>
          <p class="muted">No account yet? <a href="index.php?page=register"><strong>Create one here</strong></a>.</p>
        <?php endif; ?>
      </div>
    </section>
  </main>
<?php else: ?>
  <header>
    <div class="shell topbar">
      <div class="brand">
        <div class="brand-mark" aria-hidden="true"></div>
        <div class="brand-copy">
          <strong>Barangay Balagan Health Center</strong>
          <span>Patient portal</span>
        </div>
      </div>
      <div class="header-actions">
        <span class="pill">Logged in as <strong><?php echo e(user_display_name(current_user() ?? [])); ?></strong></span>
        <nav class="header-actions">
          <a class="pill" href="index.php?page=dashboard">Dashboard</a>
          <a class="pill" href="index.php?page=profile">My Profile</a>
          <a class="pill" href="#appointments">My Appointments</a>
          <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="logout">
            <button class="btn btn-secondary" type="submit">Log out</button>
          </form>
        </nav>
      </div>
    </div>
  </header>

  <main class="shell">
    <?php if ($message !== ''): ?><div class="alert <?php echo $type === 'error' ? 'error' : ''; ?> reveal is-visible"><?php echo e($message); ?></div><?php endif; ?>

    <?php if ($page === 'profile'): ?>
      <section class="section reveal is-visible" style="padding-top: 18px;">
        <div class="section-head">
          <div>
            <h2>My Profile</h2>
          </div>
        </div>
        <div class="panel profile-top">
          <div>
            <?php if (!empty($profile['photo_path'])): ?>
              <img class="avatar" src="<?php echo e($profile['photo_path']); ?>" alt="Profile photo">
            <?php else: ?>
              <div class="avatar">No Photo</div>
            <?php endif; ?>
          </div>
          <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="action" value="update_profile">
            <div class="field full"><label>Profile photo</label><input name="profile_photo" type="file" accept="image/*"></div>
            <div class="field"><label>First name</label><input name="first_name" value="<?php echo e($profile['first_name'] ?? ''); ?>" required></div>
            <div class="field"><label>Last name</label><input name="last_name" value="<?php echo e($profile['last_name'] ?? ''); ?>" required></div>
            <div class="field"><label>Email</label><input name="email" type="email" value="<?php echo e($profile['email'] ?? ''); ?>" required></div>
            <div class="field"><label>Username</label><input value="<?php echo e($profile['username'] ?? ''); ?>" disabled></div>
            <div class="field"><label>Cellphone</label><input name="cellphone" value="<?php echo e($profile['cellphone'] ?? ''); ?>" required></div>
            <div class="field"><label>Birthdate</label><input name="birthdate" type="date" value="<?php echo e($profile['birthdate'] ?? ''); ?>" required></div>
            <div class="field"><label>Gender</label>
              <select name="gender">
                <option <?php echo (($profile['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                <option <?php echo (($profile['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                <option <?php echo (($profile['gender'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
              </select>
            </div>
            <div class="field full"><label>Address</label><input name="address" value="<?php echo e($profile['address'] ?? ''); ?>" required></div>
            <div class="field full"><button class="btn btn-primary" type="submit">Save profile changes</button></div>
          </form>
        </div>
      </section>
    <?php else: ?>
      <section class="hero">
        <div class="hero-left reveal is-visible">
          <div class="eyebrow">Resident dashboard</div>
          <h1>Book your health center appointment with less friction.</h1>
          <div class="cta-row">
            <a class="btn btn-primary" href="#book">Request appointment</a>
            <a class="btn btn-secondary" href="#appointments">View my appointments</a>
          </div>
          <div class="hero-stats">
            <div class="stat"><span>Open schedules</span><strong data-count="<?php echo $stats['open_schedules']; ?>"><?php echo $stats['open_schedules']; ?></strong></div>
            <div class="stat"><span>Pending</span><strong data-count="<?php echo $stats['pending']; ?>"><?php echo $stats['pending']; ?></strong></div>
            <div class="stat"><span>Confirmed</span><strong data-count="<?php echo $stats['confirmed']; ?>"><?php echo $stats['confirmed']; ?></strong></div>
            <div class="stat"><span>Completed</span><strong data-count="<?php echo $stats['completed']; ?>"><?php echo $stats['completed']; ?></strong></div>
          </div>
        </div>
        <aside class="hero-right reveal is-visible">
          <div class="panel-head">
            <div>
              <h2>Today's overview</h2>
            </div>
            <span class="status-badge">Live portal</span>
          </div>
          <div class="availability">
            <div class="availability-card">
              <span>Available services</span>
              <strong><?php echo count($services); ?></strong>
            </div>
            <div class="availability-card">
              <span>Your appointments</span>
              <strong><?php echo count($userAppointments); ?></strong>
            </div>
            <div class="availability-card">
              <span>Next booking</span>
              <strong><?php echo $bookableSchedules ? format_date($bookableSchedules[0]['schedule_date']) : 'None'; ?></strong>
            </div>
            <div class="availability-card">
              <span>Most active</span>
              <strong><?php echo $bookableSchedules ? e($bookableSchedules[0]['service_name']) : 'No open slot'; ?></strong>
            </div>
          </div>
        </aside>
      </section>

      <section class="section dashboard">
        <div class="card reveal">
          <div class="section-head">
            <div>
              <h2>Request Appointment</h2>
            </div>
          </div>
          <form method="post" class="form-grid">
            <input type="hidden" name="action" value="book_appointment">
            <div class="field full">
              <label>Available schedule</label>
              <select name="schedule_id" required <?php echo $bookableSchedules ? '' : 'disabled'; ?>>
                <?php if ($bookableSchedules): ?>
                  <?php foreach ($bookableSchedules as $schedule): ?>
                    <option value="<?php echo (int) $schedule['id']; ?>"><?php echo e(schedule_display_label($schedule)); ?></option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="">No open schedules available</option>
                <?php endif; ?>
              </select>
            </div>
            <div class="field"><label>Patient full name</label><input name="full_name" value="<?php echo e(user_display_name($profile ?? [])); ?>" required></div>
            <div class="field"><label>Birthdate</label><input name="birthdate" type="date" value="<?php echo e($profile['birthdate'] ?? ''); ?>" required></div>
            <div class="field"><label>Gender</label>
              <select name="gender">
                <option <?php echo (($profile['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                <option <?php echo (($profile['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                <option <?php echo (($profile['gender'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
              </select>
            </div>
            <div class="field"><label>Cellphone number</label><input name="cellphone" value="<?php echo e($profile['cellphone'] ?? ''); ?>" required></div>
            <div class="field full"><label>Address</label><input name="address" value="<?php echo e($profile['address'] ?? ''); ?>" required></div>
            <div class="field"><label>Guardian name if child</label><input name="guardian_name"></div>
            <div class="field full"><label>Concern / reason for visit</label><textarea name="concern" placeholder="Example: prenatal follow-up, newborn injection, immunization, fever checkup"></textarea></div>
            <div class="field full">
              <div class="form-actions">
                <button class="btn btn-primary" type="submit" <?php echo $bookableSchedules ? '' : 'disabled'; ?>>Submit appointment request</button>
                <span class="microcopy">Your request will appear below after it is recorded.</span>
              </div>
            </div>
          </form>
        </div>

        <div class="card reveal">
          <div class="section-head"><div><h2>Booking Tip</h2><p>Select a schedule with availability, then submit your details.</p></div></div>
        </div>
      </section>

      <section class="section">
        <div class="section-head">
          <div>
            <h2>Available Health Center Schedules</h2>
          </div>
          <span class="pill"><?php echo count($bookableSchedules); ?> open slots ready</span>
        </div>
        <div class="slot-grid">
          <?php foreach ($schedules as $schedule): ?>
            <?php
              $booked = (int) $schedule['booked_slots'];
              $limit = max(1, (int) $schedule['slot_limit']);
              $remaining = max(0, $limit - $booked);
              $percent = min(100, (int) round(($booked / $limit) * 100));
              $isOpen = $schedule['status'] === 'open' && $remaining > 0;
            ?>
            <article class="slot reveal <?php echo $isOpen ? '' : 'full'; ?>">
              <div>
                <strong><?php echo e($schedule['service_name']); ?></strong>
                <small>
                  <?php echo e(format_date($schedule['schedule_date'])); ?><br>
                  <?php echo e(format_time($schedule['start_time'])); ?> - <?php echo e(format_time($schedule['end_time'])); ?><br>
                  <?php echo e(($schedule['staff_name'] ?: $schedule['provider_name']) . ', ' . ($schedule['staff_role'] ?: $schedule['provider_role'])); ?><br>
                  <?php echo e($schedule['notes'] ?: $schedule['service_description']); ?>
                </small>
              </div>
              <div class="capacity"><?php echo $remaining; ?> left<br><small><?php echo $percent; ?>% used</small></div>
            </article>
          <?php endforeach; ?>
          <?php if (!$schedules): ?>
            <div class="empty">No schedules posted yet.</div>
          <?php endif; ?>
        </div>
      </section>

      <section class="section" id="appointments">
        <div class="section-head">
          <div>
            <h2>My Appointments</h2>
          </div>
          <span class="pill"><?php echo $myAppointmentsPage['total']; ?> records</span>
        </div>
        <?php if ($notifications): ?>
          <div class="alert"><strong>Updates:</strong> <?php echo e($notifications[0]['message']); ?></div>
        <?php endif; ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>Patient Profile</th>
                <th>Schedule</th>
                <th>Concern</th>
                <th>Status</th>
                <th>Remarks</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($myAppointmentsPage['items'] as $appointment): ?>
                <tr>
                  <td>
                    <strong><?php echo e($appointment['appointment_code']); ?></strong>
                    <?php echo e(format_date($appointment['created_at'])); ?>
                  </td>
                  <td>
                    <strong><?php echo e($appointment['full_name']); ?></strong>
                    Age: <?php echo age_from_birthdate((string) $appointment['birthdate']); ?> | <?php echo e($appointment['gender']); ?><br>
                    Birthdate: <?php echo e(format_date($appointment['birthdate'])); ?><br>
                    Phone: <?php echo e($appointment['cellphone']); ?><br>
                    Address: <?php echo e($appointment['address']); ?><br>
                    Guardian: <?php echo e($appointment['guardian_name'] ?: 'N/A'); ?>
                  </td>
                  <td>
                    <strong><?php echo e($appointment['service_name']); ?></strong>
                    <?php echo e(format_date($appointment['schedule_date'])); ?><br>
                    <?php echo e(format_time($appointment['start_time'])); ?> - <?php echo e(format_time($appointment['end_time'])); ?><br>
                    <?php echo e($appointment['provider_name']); ?>, <?php echo e($appointment['provider_role']); ?>
                  </td>
                  <td><?php echo e($appointment['concern'] ?: 'N/A'); ?></td>
                  <td><span class="tag <?php echo e($appointment['status']); ?>"><?php echo e($statusLabels[$appointment['status']] ?? $appointment['status']); ?></span></td>
                  <td><?php echo e($appointment['remarks'] ?: 'No remarks yet.'); ?></td>
                  <td>
                    <a class="tiny-btn" target="_blank" href="index.php?page=slip&id=<?php echo (int) $appointment['id']; ?>">Print</a>
                    <?php if (in_array($appointment['status'], ['pending', 'confirmed'], true) && $appointment['schedule_date'] > date('Y-m-d')): ?>
                      <form method="post" style="margin-top:8px;" onsubmit="return confirm('Cancel this appointment?');">
                        <input type="hidden" name="action" value="cancel_appointment">
                        <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                        <button class="tiny-btn" type="submit">Cancel / Rebook</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$myAppointmentsPage['items']): ?>
                <tr><td colspan="7" class="empty">No appointment requests yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($myAppointmentsPage['pages'] > 1): ?>
          <div class="pagination">
            <?php render_pagination_links('index.php', ['page' => 'dashboard'], 'my_page', $myAppointmentsPage, '#appointments'); ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>

  <footer>
    <div class="shell">Barangay Balagan Health Center online appointment scheduling system</div>
  </footer>
<?php endif; ?>

<script>
  (() => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const revealItems = document.querySelectorAll('.reveal');
    if (!reduceMotion && 'IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12 });

      revealItems.forEach((item) => observer.observe(item));
    } else {
      revealItems.forEach((item) => item.classList.add('is-visible'));
    }

    if (!reduceMotion) {
      document.querySelectorAll('[data-count]').forEach((node) => {
        const target = Number(node.getAttribute('data-count') || '0');
        const duration = 900;
        const start = performance.now();
        const animate = (now) => {
          const progress = Math.min((now - start) / duration, 1);
          const value = Math.round(target * (0.15 + 0.85 * progress));
          node.textContent = String(progress >= 1 ? target : value);
          if (progress < 1) {
            requestAnimationFrame(animate);
          }
        };
        requestAnimationFrame(animate);
      });
    }
  })();
</script>
</body>
</html>

