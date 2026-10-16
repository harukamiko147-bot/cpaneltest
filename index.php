<?php
declare(strict_types=1);

// Clinic information shown to visitors is demonstration data; verify official details before real-world use.

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
        if (($_POST['terms_accepted'] ?? '') !== '1') {
            flash_to_page('login', 'Please agree to the Terms and Conditions before logging in.', 'error');
        }

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
        flash_to_page('appointments', $stmt->rowCount() ? 'Appointment cancelled. Choose another schedule to rebook.' : 'This appointment can no longer be cancelled.', $stmt->rowCount() ? 'success' : 'error');
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

    if ($action === 'reschedule_appointment') {
        $appointmentId = post_int('appointment_id');
        $scheduleId = post_int('schedule_id');
        $user = current_user();
        try {
            $pdo->beginTransaction();
            $currentStmt = $pdo->prepare(
                "SELECT a.id, a.schedule_id, a.status, sc.schedule_date
                 FROM appointments a
                 JOIN schedules sc ON sc.id = a.schedule_id
                 WHERE a.id = :id AND a.user_id = :user_id
                 FOR UPDATE"
            );
            $currentStmt->execute([':id' => $appointmentId, ':user_id' => $user['id']]);
            $current = $currentStmt->fetch();
            if (!$current || !in_array($current['status'], ['pending', 'confirmed'], true) || $current['schedule_date'] <= date('Y-m-d')) {
                throw new RuntimeException('This appointment can no longer be rescheduled.');
            }
            if ((int) $current['schedule_id'] === $scheduleId) {
                throw new RuntimeException('Choose a different schedule to reschedule.');
            }

            $targetStmt = $pdo->prepare(
                "SELECT id, slot_limit FROM schedules
                 WHERE id = :id AND status = 'open' AND schedule_date > CURDATE()
                 FOR UPDATE"
            );
            $targetStmt->execute([':id' => $scheduleId]);
            $target = $targetStmt->fetch();
            if (!$target || appointment_count($pdo, $scheduleId) >= (int) $target['slot_limit']) {
                throw new RuntimeException('That schedule is unavailable or full. Please choose another.');
            }

            $updateStmt = $pdo->prepare(
                "UPDATE appointments
                 SET schedule_id = :schedule_id, status = 'pending',
                     remarks = 'Rescheduled by resident. Waiting for staff review.'
                 WHERE id = :id AND user_id = :user_id"
            );
            $updateStmt->execute([':schedule_id' => $scheduleId, ':id' => $appointmentId, ':user_id' => $user['id']]);
            $pdo->commit();
            try { create_notification($pdo, (int) $user['id'], 'Appointment ' . $appointmentId . ' was rescheduled and is waiting for staff review.'); } catch (Throwable) { /* The appointment update is already committed. */ }
            flash_to_page('appointments', 'Appointment rescheduled. The clinic team will review the new request.');
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_to_page('appointments', $error instanceof RuntimeException ? $error->getMessage() : 'Could not reschedule this appointment. Please try again.', 'error');
        }
    }

    if ($action === 'book_appointment') {
        $scheduleId = post_int('schedule_id');
        $user = current_user();
        try {
            $pdo->beginTransaction();
            $scheduleStmt = $pdo->prepare(
                "SELECT sc.*, sv.name AS service_name, st.full_name AS staff_name, st.role AS staff_role
                 FROM schedules sc
                 JOIN services sv ON sv.id = sc.service_id
                 LEFT JOIN clinic_staff st ON st.id = sc.staff_id
                 WHERE sc.id = :id AND sc.status = 'open' AND sc.schedule_date > CURDATE()
                 FOR UPDATE"
            );
            $scheduleStmt->execute([':id' => $scheduleId]);
            $schedule = $scheduleStmt->fetch();
            if (!$schedule || appointment_count($pdo, $scheduleId) >= (int) ($schedule['slot_limit'] ?? 0)) {
                throw new RuntimeException('Selected schedule is unavailable or already full.');
            }

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
            $pdo->commit();

            try { create_notification($pdo, (int) $user['id'], 'Appointment ' . $code . ' was submitted.'); } catch (Throwable) { /* The appointment booking is already committed. */ }
            flash_to_page('dashboard', 'Appointment request submitted. Your code is ' . $code . '.');
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_to_page('schedule', $error instanceof RuntimeException ? $error->getMessage() : 'Could not submit your appointment. Please try again.', 'error');
        }
    }
}

if (is_logged_in() && is_admin()) {
    redirect_to('admin.php?page=dashboard');
}

if (!is_logged_in() && !in_array($page, ['login', 'register', 'terms', 'privacy', 'moderation', 'copyright', 'clinic_info'], true)) {
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
$publicServices = [];
$publicSchedules = [];
if ($page === 'clinic_info') {
    $publicServices = $pdo->query('SELECT name, description FROM services ORDER BY name')->fetchAll();
    $publicSchedules = $pdo->query(
        "SELECT sc.schedule_date, sc.start_time, sc.end_time, sv.name AS service_name
         FROM schedules sc
         JOIN services sv ON sv.id = sc.service_id
         WHERE sc.status = 'open' AND sc.schedule_date >= CURDATE()
         ORDER BY sc.schedule_date, sc.start_time
         LIMIT 20"
    )->fetchAll();
}
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

$legalPages = [
 'privacy' => ['title'=>'Privacy Policy','paragraphs'=>[
  'This website is a demonstration and academic project for an SIA subject. It is not an official Barangay Balagan Health Center service. Please do not submit real patient records, health information, or other sensitive personal information.',
  'The demo application may store account details, profile information, appointment form entries, and uploaded profile photos in its database or hosting account so its features can be demonstrated. Access and handling depend on the project code and hosting configuration.',
  'If you have entered real personal information into this demonstration, contact the project owner through the channel where you accessed the site and request its removal. Do not use the site for emergencies or clinical care.',
 ]],
 'terms' => ['title'=>'Terms & Conditions','paragraphs'=>[
  'This website is created for demonstration and academic purposes only as part of an SIA subject project. It is not a real company, official service, registered business, or commercial website. No claims are made regarding actual business operations, legal standing, or real-world services.',
  'The appointment and account features are demonstrations only. Do not rely on this site to request or confirm real health services, receive medical advice, or communicate an emergency. Contact the appropriate local health facility or emergency services for real needs.',
  'Use the demonstration respectfully and do not enter real patient, medical, or other sensitive personal information. Content may be reviewed or removed by project administrators.',
 ]],
 'moderation' => ['title'=>'Moderation','paragraphs'=>[
  'This is an academic demonstration website, not an official channel for the barangay or a health facility. Information submitted through forms may be visible to project administrators for demonstration and maintenance.',
  'Do not submit real medical records, sensitive health details, threats, unlawful material, spam, or content that targets or harasses another person. Project administrators may review, restrict, or remove submitted content or accounts in the demonstration.',
  'For real health questions, appointments, or emergencies, contact the appropriate health provider directly.',
 ]],
 'copyright' => ['title'=>'Copyright','paragraphs'=>[
  '© 2026 Barangay Balagan Health Center demonstration project. This website is presented for academic and demonstration purposes only and does not represent an official government or health-center service.',
  'The logo shown on this site was supplied for this project. Other names, marks, and materials remain with their respective owners. No affiliation or endorsement is implied.',
  'For questions about material displayed on this demonstration, contact the project owner through the channel where the site was shared.',
 ]],
];
function render_legal_page(array $content, string $backUrl, string $backLabel): void
{
 echo '<h2>' . e($content['title']) . '</h2><div class="terms-copy">';
 foreach ($content['paragraphs'] as $paragraph) echo '<p>' . e($paragraph) . '</p>';
 echo '</div><p class="muted"><a href="' . e($backUrl) . '"><strong>' . e($backLabel) . '</strong></a></p>';
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
    .brand-mark { display:block; width:64px; height:64px; flex:0 0 auto; object-fit:contain; border-radius:14px; background:#fff; box-shadow:var(--shadow-soft); }
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
  
    /* Refined clinic portal theme */
    :root {
      --bg: #f3f8f6;
      --bg-2: #eaf3ef;
      --surface: rgba(255, 255, 255, 0.94);
      --surface-strong: #ffffff;
      --surface-soft: rgba(255, 255, 255, 0.76);
      --line: rgba(25, 73, 68, 0.12);
      --text: #173238;
      --muted: #647a7c;
      --primary: #087f73;
      --primary-2: #0b9384;
      --secondary: #216c78;
      --accent: #e8a263;
      --shadow: 0 20px 54px rgba(22, 54, 51, 0.09);
      --shadow-soft: 0 8px 24px rgba(22, 54, 51, 0.07);
      --radius-xl: 26px;
      --radius-lg: 20px;
      --radius-md: 15px;
    }

    body {
      background:
        radial-gradient(ellipse at 8% 0%, rgba(8, 127, 115, 0.09), transparent 30%),
        radial-gradient(ellipse at 94% 12%, rgba(232, 162, 99, 0.10), transparent 24%),
        linear-gradient(180deg, #f7faf8 0%, var(--bg-2) 100%);
      line-height: 1.55;
    }
    body::before {
      background-image:
        linear-gradient(rgba(23, 50, 56, 0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(23, 50, 56, 0.025) 1px, transparent 1px);
      opacity: 0.35;
    }
    .shell { --max: 1200px; }
    .topbar { padding-top: 22px; padding-bottom: 12px; }
    .brand-mark { border-radius:14px; box-shadow:0 8px 18px rgba(8,127,115,.12); }
    .brand-copy strong { color: #173238; }
    .pill {
      background: rgba(255, 255, 255, 0.9);
      border-color: rgba(25, 73, 68, 0.11);
      color: #526c6d;
    }
    .hero { gap: 20px; padding-top: 18px; }
    .hero-left {
      color: #fff;
      border: 0;
      background:
        radial-gradient(circle at 90% 12%, rgba(255,255,255,.12), transparent 24%),
        linear-gradient(135deg, #145c57 0%, #087f73 62%, #0b9384 100%);
      box-shadow: 0 22px 48px rgba(8, 91, 82, 0.20);
    }
    .hero-left h1 { color: #fff; }
    .hero-left .eyebrow {
      color: #d8f5ed;
      background: rgba(255,255,255,.10);
      border-color: rgba(255,255,255,.18);
    }
    .hero-left .stat {
      background: rgba(255,255,255,.11);
      border-color: rgba(255,255,255,.15);
      backdrop-filter: blur(8px);
    }
    .hero-left .stat span { color: rgba(237,250,247,.78); }
    .hero-left .stat strong { color: #fff; }
    .hero-right, .card, .panel, .auth-card {
      border-color: rgba(255,255,255,.92);
      box-shadow: var(--shadow);
    }
    .hero-right { background: rgba(255,255,255,.94); }
    .availability-card, .slot, .mini-card, .stat {
      border-color: rgba(25,73,68,.10);
      background: #fff;
    }
    .btn { border-radius: 13px; min-height: 48px; }
    .btn-primary {
      background: linear-gradient(135deg, #087f73, #0b9384);
      box-shadow: 0 10px 22px rgba(8,127,115,.20);
    }
    .btn-primary:hover { box-shadow: 0 14px 26px rgba(8,127,115,.25); }
    .btn-secondary {
      color: #17645d;
      border-color: rgba(25,73,68,.14);
    }
    .field label { color: #355255; }
    .field input, .field select, .field textarea {
      border-color: rgba(25,73,68,.16);
      border-radius: 12px;
      background: #fff;
    }
    .field input:focus, .field select:focus, .field textarea:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(8,127,115,.12);
    }
    .field input:focus-visible, .field select:focus-visible,
    .field textarea:focus-visible, .btn:focus-visible,
    a:focus-visible, button:focus-visible {
      outline: 3px solid rgba(232,162,99,.72);
      outline-offset: 2px;
    }
    .section { padding-bottom: 30px; }
    .section-head { margin-bottom: 17px; }
    .section-head h2, .panel-head h2 { color: #173238; }
    .slot { transition: transform 180ms ease, box-shadow 180ms ease; }
    .slot:hover { transform: translateY(-2px); box-shadow: var(--shadow-soft); }
    .table-wrap { border-color: rgba(25,73,68,.10); }
    th { background: #edf6f2; color: #25484a; }
    tbody tr:hover { background: rgba(8,127,115,.035); }
    .auth-grid { padding: clamp(16px, 4vw, 40px); }
    .auth-card {
      width: min(1040px, 100%);
      grid-template-columns: .92fr 1.08fr;
      border-radius: 28px;
    }
    .auth-intro {
      background:
        radial-gradient(circle at 12% 8%, rgba(232,162,99,.22), transparent 28%),
        linear-gradient(150deg, #145c57 0%, #087f73 70%, #0b9384 100%);
      padding: clamp(26px, 4vw, 42px);
    }
    .auth-intro::before {
      content: "+";
      position: absolute;
      right: 14px;
      bottom: -122px;
      color: rgba(255,255,255,.075);
      font: 300 22rem/1 Arial, sans-serif;
      pointer-events: none;
    }
    .auth-intro > div { position: relative; z-index: 1; }
    .auth-intro .pill { backdrop-filter: blur(8px); }
    .auth-panel {
      padding: clamp(24px, 4vw, 42px);
      background: rgba(255,255,255,.97);
      align-content: center;
      gap: 16px;
    }
    .auth-panel h2 { color: #173238; font-size: 2rem; }
    .auth-panel .muted a { color: var(--primary); }
    .auth-panel .muted a:hover { text-decoration: underline; }
    footer { border-color: rgba(25,73,68,.10); }

    @media (min-width: 640px) and (max-width: 1080px) {
      .hero-stats, .availability, .slot-grid, .grid-2, .form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .field.full { grid-column: 1 / -1; }
    }
    @media (max-width: 639px) {
      .shell { width: min(100% - 24px, var(--max)); }
      .topbar { padding-top: 14px; }
      .brand { gap: 10px; }
      .brand-mark { width: 48px; height: 48px; }
      .brand-copy strong { font-size: .96rem; }
      .brand-copy span { font-size: .8rem; }
      .hero { padding-top: 10px; gap: 14px; }
      .hero-left, .hero-right, .card { padding: 20px; border-radius: 21px; }
      .hero-stats, .availability { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
      .stat { min-height: 90px; padding: 12px; }
      .stat span { font-size: .78rem; }
      .stat strong { font-size: 1.45rem; }
      h1 { font-size: clamp(2.35rem, 11vw, 3rem); }
      .auth-grid { padding: 14px; align-items: start; }
      .auth-card { border-radius: 22px; }
      .auth-intro { min-height: 240px; padding: 23px; }
      .auth-intro .brand { align-items: center; }
      .auth-intro h1 { font-size: 2.35rem; }
      .auth-panel { padding: 23px; }
      .form-grid, .slot-grid, .grid-2, .profile-top { grid-template-columns: 1fr; }
      .field.full { grid-column: auto; }
      .section-head { gap: 10px; }
      .queue-item { grid-template-columns: 1fr; }
      .tag { justify-self: start; }
    }

    .portal-layout { min-height:100vh; }
    .portal-sidebar { position:fixed; inset:0 auto 0 0; z-index:30; width:272px; display:flex; flex-direction:column; padding:24px 18px 18px; color:#eaf6f2; background:linear-gradient(180deg,#153f3e,#123735); box-shadow:12px 0 34px rgba(20,52,49,.10); }
    .sidebar-brand { display:flex; align-items:center; gap:12px; padding:4px 7px 24px; color:#fff; }
    .sidebar-brand .brand-mark { width:48px; height:48px; flex:0 0 48px; border:0; }
    .sidebar-brand strong,.sidebar-brand small { display:block; }
    .sidebar-brand strong { font-size:.96rem; line-height:1.25; }
    .sidebar-brand small { margin-top:4px; color:#aac6c0; font-size:.78rem; }
    .sidebar-account { display:flex; align-items:center; gap:11px; padding:16px 10px; border-block:1px solid rgba(255,255,255,.12); }
    .account-avatar,.topbar-avatar { display:grid; place-items:center; flex:0 0 auto; border-radius:50%; font-weight:800; }
    .account-avatar { width:38px; height:38px; color:#124e48; background:#c9e8dd; }
    .sidebar-account small,.sidebar-account strong { display:block; max-width:170px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sidebar-account small { margin-bottom:3px; color:#a9c2bc; font-size:.73rem; }
    .sidebar-account strong { color:#fff; font-size:.88rem; }
    .sidebar-nav { display:grid; gap:5px; padding-top:25px; }
    .sidebar-label { padding:0 12px 7px; color:#91b1aa; font-size:.68rem; font-weight:800; letter-spacing:.12em; }
    .sidebar-nav a,.sidebar-logout button { display:flex; align-items:center; gap:12px; width:100%; padding:12px; border:0; border-radius:11px; color:#c1d6d1; background:transparent; text-align:left; font-weight:650; transition:background .16s ease,color .16s ease; }
    .sidebar-nav a:hover,.sidebar-logout button:hover { color:#fff; background:rgba(255,255,255,.08); }
    .sidebar-nav a.active { color:#fff; background:#087f73; box-shadow:0 8px 20px rgba(0,0,0,.12); }
    .nav-icon { display:inline-grid; place-items:center; width:22px; height:22px; font-size:1.14rem; }
    .sidebar-bottom { margin-top:auto; }
    .sidebar-help { display:grid; gap:6px; padding:14px; border:1px solid rgba(255,255,255,.10); border-radius:13px; background:rgba(255,255,255,.055); }
    .sidebar-help strong { color:#f4faf8; font-size:.82rem; }
    .sidebar-help span { color:#afc6c0; font-size:.76rem; line-height:1.5; }
    .sidebar-logout { margin-top:12px; }
    .sidebar-logout button { cursor:pointer; font:inherit; }
    .portal-main { min-height:100vh; margin-left:272px; display:flex; flex-direction:column; }
    .portal-topbar { position:sticky; top:0; z-index:15; display:flex; align-items:center; gap:15px; min-height:78px; padding:12px clamp(18px,4vw,48px); border-bottom:1px solid rgba(25,73,68,.08); background:rgba(248,251,249,.91); backdrop-filter:blur(16px); }
    .topbar-page { display:grid; gap:2px; }
    .topbar-page span { color:#6b8181; font-size:.78rem; }
    .topbar-page strong { color:#173238; font-size:1.05rem; }
    .topbar-profile { display:flex; align-items:center; gap:9px; margin-left:auto; color:#355255; font-size:.88rem; font-weight:700; }
    .topbar-avatar { width:36px; height:36px; color:#17645d; background:#dcefe9; }
    .menu-toggle { display:none; }
    .portal-content { width:min(1240px,100%); flex:1; margin:0 auto; padding:28px clamp(18px,4vw,48px) 18px; }
    .portal-content .hero { margin-top:0; }
    .portal-footer { padding:18px clamp(18px,4vw,48px) 24px; color:#738787; font-size:.82rem; }
    .site-footer { position:relative; z-index:1; width:min(1060px,calc(100% - 32px)); margin:24px auto 20px; }
    .portal-footer.site-footer { width:auto; margin:0; }
    .site-disclaimer { padding:15px 17px; border:1px solid #e5d8a7; border-radius:12px; color:#554a27; background:#fff8dc; font-size:.9rem; line-height:1.65; }
    .site-disclaimer strong { color:#3f361c; }
    .site-footer-links { display:flex; flex-wrap:wrap; justify-content:center; gap:10px 18px; padding:15px 8px 7px; border-top:1px solid rgba(25,73,68,.12); margin-top:16px; }
    .site-footer-links a { color:#315b55; font-weight:700; }
    .site-footer-links a:hover { color:#087f73; text-decoration:underline; text-underline-offset:3px; }
    .site-copyright { color:#647a7c; text-align:center; font-size:.82rem; }
    @media (max-width:1080px) { .portal-footer.site-footer { margin:0 16px; } }
    .quick-links { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin:0 0 28px; }
    .quick-link { display:flex; align-items:center; gap:13px; min-height:88px; padding:17px; border:1px solid rgba(25,73,68,.10); border-radius:18px; background:#fff; box-shadow:var(--shadow-soft); transition:transform .18s ease,box-shadow .18s ease; }
    .quick-link:hover { transform:translateY(-2px); box-shadow:var(--shadow); }
    .quick-link-icon { display:grid; place-items:center; width:42px; height:42px; flex:0 0 auto; border-radius:13px; color:#087f73; background:#e9f5f0; font-size:1.35rem; }
    .quick-link strong,.quick-link small { display:block; }
    .quick-link strong { color:#173238; font-size:.92rem; }
    .quick-link small { margin-top:4px; color:#6b8181; font-size:.78rem; line-height:1.4; }
    .quick-arrow { margin-left:auto; color:#087f73; font-size:1.2rem; }
    .skip-link { position:absolute; left:-9999px; top:8px; z-index:1000; padding:10px 14px; border-radius:8px; background:#fff; color:#075e57; box-shadow:0 2px 12px rgba(0,0,0,.2); }
    .skip-link:focus { left:12px; }\n    .page-intro { margin:0 0 20px; }
    .page-intro h1 { max-width:none; margin:10px 0 6px; color:#173238; font:700 clamp(1.8rem,3vw,2.5rem)/1.12 var(--sans); letter-spacing:-.035em; }
    .page-intro p { margin:0; color:#647a7c; }
    .portal-content .schedule-page { margin-top:0; }
    .portal-content .schedule-page > .card { grid-column:1/-1; }
    .terms-check { display:flex; flex-direction:row; align-items:flex-start; gap:10px; padding:4px 0 2px; }
    .terms-check input { width:18px; height:18px; flex:0 0 18px; margin:2px 0 0; accent-color:#087f73; }
    .terms-check label { color:#536b6c; font-size:.88rem; font-weight:500; line-height:1.5; }
    .terms-check a { color:#087f73; font-weight:750; text-decoration:underline; text-underline-offset:2px; }
    .terms-copy { color:#425d5f; line-height:1.7; }
    .terms-copy p { margin:.2rem 0 1rem; }
    .sidebar-backdrop { display:none; }
    @media (max-width:1080px) {
      .portal-content .hero { grid-template-columns:1.1fr .9fr; }
      .portal-content .hero-stats,.portal-content .availability { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
    @media (max-width:760px) {
      .portal-sidebar { width:min(290px,86vw); transform:translateX(-105%); transition:transform .22s ease; }
      .portal-layout.sidebar-open .portal-sidebar { transform:translateX(0); }
      .portal-main { margin-left:0; }
      .portal-topbar { min-height:68px; padding:10px 16px; }
      .menu-toggle { display:grid; gap:4px; width:40px; height:40px; padding:10px; border:1px solid rgba(25,73,68,.12); border-radius:11px; background:#fff; cursor:pointer; }
      .menu-toggle span { display:block; width:18px; height:2px; border-radius:2px; background:#17645d; }
      .portal-layout.sidebar-open .sidebar-backdrop { display:block; position:fixed; inset:0; z-index:25; width:100%; border:0; background:rgba(13,34,34,.42); }
      .portal-content { padding:20px 16px 12px; }
      .portal-content .hero { grid-template-columns:1fr; }
      .quick-links { grid-template-columns:1fr; gap:10px; }
      .topbar-profile > span:last-child { display:none; }
    }
    @media (prefers-reduced-motion:reduce) { .portal-sidebar,.quick-link { transition:none; } }
</style>
</head>
<body>
  <div class="glow one"></div>
  <div class="glow two"></div>

<?php if (!is_logged_in() && in_array($page, ['login', 'register', 'terms', 'privacy', 'moderation', 'copyright'], true)): ?>
  <main class="auth-grid">
    <section class="auth-card reveal">
      <div class="auth-intro">
        <div>
          <div class="brand" style="margin-bottom: 22px;">
            <img class="brand-mark" src="assets/balangan-health-center-logo.png" alt="Balangan Health Center logo">
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
        <?php if ($page === 'clinic_info'): ?>
          <h2>Clinic Information &amp; Services</h2>
          <p class="muted">This page displays demonstration data from the project. It is not an official clinic notice.</p>
          <h3>Services listed in this demo</h3>
          <?php if ($publicServices): ?><ul><?php foreach ($publicServices as $service): ?><li><strong><?php echo e($service['name']); ?></strong><?php if ($service['description']): ?> — <?php echo e($service['description']); ?><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><p>No services have been listed.</p><?php endif; ?>
          <h3>Posted schedules</h3>
          <?php if ($publicSchedules): ?><ul><?php foreach ($publicSchedules as $schedule): ?><li><?php echo e(format_date($schedule['schedule_date'])); ?>, <?php echo e(format_time($schedule['start_time'])); ?>–<?php echo e(format_time($schedule['end_time'])); ?> — <?php echo e($schedule['service_name']); ?></li><?php endforeach; ?></ul><?php else: ?><p>No open schedules are posted.</p><?php endif; ?>
          <div class="alert">Office hours, address, contact details, and real service availability have not been verified here. Confirm them through official barangay channels. This academic demo is not for emergencies or real patient information.</div>
          <p class="muted"><a href="index.php?page=login"><strong>Back to login</strong></a></p>
        <?php elseif (isset($legalPages[$page])): ?>
          <?php render_legal_page($legalPages[$page], 'index.php?page=login', 'Back to login'); ?>
        <?php elseif ($page === 'register'): ?>
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
            <div class="field full"><label>Username or email</label><input name="identity" autocomplete="username" required autofocus></div>
            <div class="field full"><label>Password</label><input name="password" type="password" autocomplete="current-password" required></div>
            <div class="field full terms-check">
              <input id="terms-accepted" name="terms_accepted" value="1" type="checkbox" required>
              <label for="terms-accepted">I agree to the <a href="index.php?page=terms" target="_blank" rel="noopener">Terms &amp; Conditions</a>.</label>
            </div>
            <div class="field full"><button class="btn btn-primary" type="submit">Log in</button></div>
          </form>
          <p class="muted"><a href="index.php?page=clinic_info"><strong>View clinic information and services</strong></a></p>
          <p class="muted">No account yet? <a href="index.php?page=register"><strong>Create one here</strong></a>.</p>
        <?php endif; ?>
      </div>
    </section>
  </main>
    <footer class="site-footer auth-footer"><div class="site-disclaimer"><strong>Disclaimer:</strong> This website is created for demonstration and academic purposes only as part of an SIA subject project. It is not a real company, official service, registered business, or commercial website. No claims are made regarding actual business operations, legal standing, or real-world services.</div>
      <nav class="site-footer-links" aria-label="Legal information"><a href="index.php?page=privacy">Privacy Policy</a><a href="index.php?page=terms">Terms</a><a href="index.php?page=moderation">Moderation</a><a href="index.php?page=copyright">Copyright</a></nav>
      <div class="site-copyright">© 2026 Barangay Balagan Health Center</div></footer>
<?php else: ?>
<div class="portal-layout" id="portalLayout">
  <aside class="portal-sidebar" id="portalSidebar" aria-label="Resident navigation">
    <a class="sidebar-brand" href="index.php?page=dashboard"><img class="brand-mark" src="assets/balangan-health-center-logo.png" alt=""><span><strong>Balagan Health Center</strong><small>Resident portal</small></span></a>
    <div class="sidebar-account"><span class="account-avatar" aria-hidden="true"><?php echo e(strtoupper(substr(user_display_name(current_user() ?? []), 0, 1))); ?></span><span><small>Signed in as</small><strong><?php echo e(user_display_name(current_user() ?? [])); ?></strong></span></div>
    <nav class="sidebar-nav" aria-label="Main menu">
      <span class="sidebar-label">MENU</span>
      <a class="<?php echo $page === 'dashboard' ? 'active' : ''; ?>" href="index.php?page=dashboard"><span class="nav-icon" aria-hidden="true">⌂</span>Dashboard</a>
      <a class="<?php echo $page === 'profile' ? 'active' : ''; ?>" href="index.php?page=profile"><span class="nav-icon" aria-hidden="true">◉</span>My Profile</a>
      <a class="<?php echo $page === 'appointments' ? 'active' : ''; ?>" href="index.php?page=appointments"><span class="nav-icon" aria-hidden="true">✓</span>My Appointments</a>
      <a class="<?php echo $page === 'schedule' ? 'active' : ''; ?>" href="index.php?page=schedule"><span class="nav-icon" aria-hidden="true">＋</span>Appointment Schedule</a>
      <a class="<?php echo $page === 'clinic_info' ? 'active' : ''; ?>" href="index.php?page=clinic_info"><span class="nav-icon" aria-hidden="true">ⓘ</span>Clinic Information</a>
    </nav>
    <div class="sidebar-bottom">
      <div class="sidebar-help"><strong>Need to book a visit?</strong><span>Choose an open clinic schedule to send a request.</span></div>
      <form method="post" class="sidebar-logout"><input type="hidden" name="action" value="logout"><button type="submit"><span class="nav-icon" aria-hidden="true">↪</span>Log out</button></form>
    </div>
  </aside>
  <button type="button" class="sidebar-backdrop" id="sidebarBackdrop" aria-label="Close navigation"></button>
  <div class="portal-main">
    <a class="skip-link" href="#mainContent">Skip to main content</a>
    <header class="portal-topbar">
      <button type="button" class="menu-toggle" id="menuToggle" aria-label="Open navigation" aria-expanded="false" aria-controls="portalSidebar"><span></span><span></span><span></span></button>
      <div class="topbar-page"><span>Barangay Balagan Health Center</span><strong><?php echo e(['dashboard' => 'Dashboard', 'profile' => 'My Profile', 'appointments' => 'My Appointments', 'schedule' => 'Appointment Schedule', 'clinic_info' => 'Clinic Information'][$page] ?? 'Resident Portal'); ?></strong></div>
      <a class="topbar-profile" href="index.php?page=profile"><span class="topbar-avatar" aria-hidden="true"><?php echo e(strtoupper(substr(user_display_name(current_user() ?? []), 0, 1))); ?></span><span><?php echo e(user_display_name(current_user() ?? [])); ?></span></a>
    </header>
    <main class="portal-content" id="mainContent">
      <?php if ($message !== ''): ?><div class="alert <?php echo $type === 'error' ? 'error' : ''; ?> reveal is-visible"><?php echo e($message); ?></div><?php endif; ?>
      <?php if ($page === 'clinic_info'): ?>
        <section class="section reveal is-visible" style="padding-top:18px;">
          <div class="panel terms-copy">
            <h2>Clinic Information &amp; Services</h2>
            <p>This page displays demonstration data from the project. It is not an official clinic notice.</p>
            <h3>Services listed in this demo</h3>
            <?php if ($publicServices): ?><ul><?php foreach ($publicServices as $service): ?><li><strong><?php echo e($service['name']); ?></strong><?php if ($service['description']): ?> — <?php echo e($service['description']); ?><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><p>No services have been listed.</p><?php endif; ?>
            <h3>Posted schedules</h3>
            <?php if ($publicSchedules): ?><ul><?php foreach ($publicSchedules as $schedule): ?><li><?php echo e(format_date($schedule['schedule_date'])); ?>, <?php echo e(format_time($schedule['start_time'])); ?>–<?php echo e(format_time($schedule['end_time'])); ?> — <?php echo e($schedule['service_name']); ?></li><?php endforeach; ?></ul><?php else: ?><p>No open schedules are posted.</p><?php endif; ?>
            <div class="alert">Office hours, address, contact details, and real service availability have not been verified here. Confirm them through official barangay channels. This academic demo is not for emergencies or real patient information.</div>
          </div>
        </section>
      <?php elseif (isset($legalPages[$page])): ?>
        <section class="section reveal is-visible" style="padding-top:18px;"><div class="panel terms-copy"><?php render_legal_page($legalPages[$page], 'index.php?page=dashboard', 'Back to dashboard'); ?></div></section>
      <?php elseif ($page === 'profile'): ?>
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
      <?php elseif ($page === 'appointments'): ?>
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
                      <?php
                        $alternativeSchedules = array_values(array_filter($bookableSchedules, static fn(array $candidate): bool => (int) $candidate['id'] !== (int) $appointment['schedule_id']));
                      ?>
                      <?php if ($alternativeSchedules): ?>
                        <form method="post" style="margin-top:8px;">
                          <input type="hidden" name="action" value="reschedule_appointment">
                          <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                          <label class="sr-only" for="reschedule-<?php echo (int) $appointment['id']; ?>">Choose a new schedule</label>
                          <select id="reschedule-<?php echo (int) $appointment['id']; ?>" name="schedule_id" required>
                            <?php foreach ($alternativeSchedules as $candidate): ?>
                              <option value="<?php echo (int) $candidate['id']; ?>"><?php echo e(schedule_display_label($candidate)); ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button class="tiny-btn" type="submit" style="margin-top:6px;">Reschedule</button>
                        </form>
                      <?php endif; ?>
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
            <?php render_pagination_links('index.php', ['page' => 'appointments'], 'my_page', $myAppointmentsPage, '#appointments'); ?>
          </div>
        <?php endif; ?>
      </section>
      <?php elseif ($page === 'schedule'): ?>
        <section class="page-intro"><span class="eyebrow">Book a clinic visit</span><h1>Appointment Schedule</h1><p>Choose an available schedule and send your appointment request.</p></section>
      <section class="section schedule-page">
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
      <?php else: ?>
      <section class="hero">
        <div class="hero-left reveal is-visible">
          <div class="eyebrow">Resident dashboard</div>
          <h1>Book your health center appointment with less friction.</h1>
          <div class="cta-row">
            <a class="btn btn-primary" href="index.php?page=schedule">Request appointment</a>
            <a class="btn btn-secondary" href="index.php?page=appointments">View my appointments</a>
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
        <?php
          $upcomingReminders = array_values(array_filter($userAppointments, static fn(array $appointment): bool =>
              in_array($appointment['status'], ['pending', 'confirmed'], true) && $appointment['schedule_date'] >= date('Y-m-d')
          ));
          usort($upcomingReminders, static fn(array $left, array $right): int =>
              [$left['schedule_date'], $left['start_time']] <=> [$right['schedule_date'], $right['start_time']]
          );
          $upcomingReminders = array_slice($upcomingReminders, 0, 3);
        ?>
        <section class="section" aria-labelledby="upcoming-reminders-title">
          <div class="section-head">
            <div><h2 id="upcoming-reminders-title">Upcoming appointment reminders</h2><p>Check the schedule and current status before your visit.</p></div>
            <a class="tiny-btn" href="index.php?page=appointments">Manage appointments</a>
          </div>
          <?php if ($upcomingReminders): ?>
            <div class="slot-grid">
              <?php foreach ($upcomingReminders as $reminder): ?>
                <article class="slot">
                  <div><strong><?php echo e($reminder['appointment_code']); ?></strong><small><?php echo e(format_date($reminder['schedule_date'])); ?> · <?php echo e(format_time($reminder['start_time'])); ?>–<?php echo e(format_time($reminder['end_time'])); ?><br><?php echo e($reminder['service_name']); ?></small></div>
                  <span class="tag <?php echo e($reminder['status']); ?>"><?php echo e($statusLabels[$reminder['status']] ?? $reminder['status']); ?></span>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="empty">No upcoming appointment. <a href="index.php?page=schedule">Browse available schedules</a>.</div>
          <?php endif; ?>
        </section>
        <section class="quick-links" aria-label="Quick actions">
          <a class="quick-link" href="index.php?page=schedule"><span class="quick-link-icon">＋</span><span><strong>Request an appointment</strong><small>Browse open schedules and choose a time.</small></span><span class="quick-arrow">→</span></a>
          <a class="quick-link" href="index.php?page=appointments"><span class="quick-link-icon">✓</span><span><strong>View my appointments</strong><small>Check request status, details, and remarks.</small></span><span class="quick-arrow">→</span></a>
        </section>
      <?php endif; ?>
    </main>
    <footer class="portal-footer site-footer"><div class="site-disclaimer"><strong>Disclaimer:</strong> This website is created for demonstration and academic purposes only as part of an SIA subject project. It is not a real company, official service, registered business, or commercial website. No claims are made regarding actual business operations, legal standing, or real-world services.</div>
      <nav class="site-footer-links" aria-label="Legal information"><a href="index.php?page=privacy">Privacy Policy</a><a href="index.php?page=terms">Terms</a><a href="index.php?page=moderation">Moderation</a><a href="index.php?page=copyright">Copyright</a></nav>
      <div class="site-copyright">© 2026 Barangay Balagan Health Center</div></footer>
  </div>
</div>

<?php endif; ?>

<script>
  (() => {
    const portalLayout = document.getElementById('portalLayout');
    const menuToggle = document.getElementById('menuToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (portalLayout && menuToggle && sidebarBackdrop) {
      const closeSidebar = () => {
        portalLayout.classList.remove('sidebar-open');
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.setAttribute('aria-label', 'Open navigation');
      };
      menuToggle.addEventListener('click', () => {
        const isOpen = portalLayout.classList.toggle('sidebar-open');
        menuToggle.setAttribute('aria-expanded', String(isOpen));
        menuToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
      });
      sidebarBackdrop.addEventListener('click', closeSidebar);
      portalLayout.querySelectorAll('.sidebar-nav a').forEach((link) => {
        link.addEventListener('click', closeSidebar);
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeSidebar();
      });
    }

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

