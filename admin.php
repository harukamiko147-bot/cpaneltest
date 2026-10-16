Warning: truncated output (original token count: 15063)
Total output lines: 1476

<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$pdo = db();
ensure_seed_data($pdo);

$page = (string) ($_GET['page'] ?? 'dashboard');
$message = (string) ($_GET['message'] ?? '');
$type = (string) ($_GET['type'] ?? 'success');
$editingStaffId = isset($_GET['edit_staff']) ? (int) $_GET['edit_staff'] : 0;
$appointmentSearch = trim((string) ($_GET['appointment_search'] ?? ''));
$appointmentStatusFilter = (string) ($_GET['appointment_status'] ?? '');
$appointmentDateFilter = (string) ($_GET['appointment_date'] ?? '');

$statusLabels = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show' => 'No Show',
];

function admin_flash(string $page, string $message, string $type = 'success'): void
{
    redirect_to_page('admin.php', $page, $message, $type);
}

function staff_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters ?: 'S';
}

$editingStaff = null;
if ($editingStaffId > 0) {
    $staffStmt = $pdo->prepare('SELECT * FROM clinic_staff WHERE id = :id LIMIT 1');
    $staffStmt->execute([':id' => $editingStaffId]);
    $editingStaff = $staffStmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $identity = post_string('identity');
        $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1');
        $stmt->execute([
            ':username' => $identity,
            ':email' => $identity,
        ]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string) $user['password_hash']) && ($user['role'] ?? 'user') === 'admin') {
            login_user($user);
            redirect_to('admin.php?page=dashboard&message=' . urlencode('Admin login successful.') . '&type=success');
        }

        admin_flash('login', 'Invalid admin account or password.', 'error');
    }

    if ($action === 'logout') {
        logout_user();
        redirect_to('admin.php?page=login');
    }

    require_admin();

    if ($action === 'save_appointment_status') {
        $appointmentId = post_int('appointment_id');
        $status = (string) ($_POST['status'] ?? 'pending');
        $remarks = post_string('remarks');

        if (!array_key_exists($status, $statusLabels) || $appointmentId <= 0) {
            admin_flash('dashboard', 'Invalid appointment update request.', 'error');
        }

        $stmt = $pdo->prepare('UPDATE appointments SET status = :status, remarks = :remarks WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':remarks' => $remarks,
            ':id' => $appointmentId,
        ]);

        $ownerStmt = $pdo->prepare('SELECT user_id, appointment_code FROM appointments WHERE id = :id LIMIT 1');
        $ownerStmt->execute([':id' => $appointmentId]);
        $owner = $ownerStmt->fetch();
        if ($owner && $owner['user_id']) {
            create_notification($pdo, (int) $owner['user_id'], 'Appointment ' . $owner['appointment_code'] . ' is now ' . $statusLabels[$status] . '.');
        }

        admin_flash('appointments', 'Appointment status updated.');
    }

    if ($action === 'save_schedule') {
        $serviceId = post_int('service_id');
        $staffId = post_int('staff_id');
        $scheduleDate = (string) ($_POST['schedule_date'] ?? '');
        $startTime = (string) ($_POST['start_time'] ?? '');
        $endTime = (string) ($_POST['end_time'] ?? '');
        $slotLimit = max(1, (int) ($_POST['slot_limit'] ?? 20));
        $notes = post_string('notes');
        $status = (string) ($_POST['status'] ?? 'open');

        $providerName = post_string('provider_name');
        $providerRole = post_string('provider_role', 'Health Staff');

        if ($staffId > 0) {
            $staffStmt = $pdo->prepare('SELECT * FROM clinic_staff WHERE id = :id LIMIT 1');
            $staffStmt->execute([':id' => $staffId]);
            $staff = $staffStmt->fetch();
            if ($staff) {
                $providerName = (string) $staff['full_name'];
                $providerRole = (string) $staff['role'];
            }
        }

        if ($scheduleDate < date('Y-m-d') || $startTime >= $endTime || $providerName === '') {
            admin_flash('schedules', 'Enter a future date, valid times, and provider.', 'error');
        }

        if ($staffId > 0) {
            $conflictStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM schedules
                 WHERE staff_id = :staff_id AND schedule_date = :schedule_date AND status NOT IN ('cancelled')
                   AND start_time < :end_time AND end_time > :start_time"
            );
            $conflictStmt->execute([
                ':staff_id' => $staffId,
                ':schedule_date' => $scheduleDate,
                ':start_time' => $startTime,
                ':end_time' => $endTime,
            ]);
            if ((int) $conflictStmt->fetchColumn() > 0) {
                admin_flash('schedules', 'This staff member already has an overlapping schedule.', 'error');
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO schedules (service_id, staff_id, provider_name, provider_role, schedule_date, start_time, end_time, slot_limit, status, notes)
             VALUES (:service_id, :staff_id, :provider_name, :provider_role, :schedule_date, :start_time, :end_time, :slot_limit, :status, :notes)'
        );
        $stmt->execute([
            ':service_id' => $serviceId,
            ':staff_id' => $staffId > 0 ? $staffId : null,
            ':provider_name' => $providerName,
            ':provider_role' => $providerRole,
            ':schedule_date' => $scheduleDate,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':slot_limit' => $slotLimit,
            ':status' => $status,
            ':notes' => $notes,
        ]);

        admin_flash('schedules', 'Schedule posted successfully.');
    }

    if ($action === 'update_schedule_status') {
        $scheduleId = post_int('schedule_id');
        $status = (string) ($_POST['status'] ?? 'open');
        if ($scheduleId <= 0 || !in_array($status, ['open', 'closed', 'completed', 'cancelled'], true)) {
            admin_flash('schedules', 'Invalid schedule update request.', 'error');
        }

        $stmt = $pdo->prepare('UPDATE schedules SET status = :status WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':id' => $scheduleId,
        ]);

        admin_flash('schedules', 'Schedule status updated.');
    }

    if ($action === 'save_staff') {
        $staffId = post_int('staff_id');
        $currentStaff = null;
        if ($staffId > 0) {
            $currentStaffStmt = $pdo->prepare('SELECT * FROM clinic_staff WHERE id = :id LIMIT 1');
            $currentStaffStmt->execute([':id' => $staffId]);
            $currentStaff = $currentStaffStmt->fetch() ?: null;
        }

        $photoPath = save_uploaded_image('staff_photo', 'staff', $currentStaff['photo_path'] ?? null);
        $payload = [
            ':full_name' => post_string('full_name'),
            ':role' => (string) ($_POST['role'] ?? 'Nurse'),
            ':specialty' => post_string('specialty'),
            ':contact_number' => post_string('contact_number'),
            ':availability' => post_string('availability', 'Available'),
            ':status' => (string) ($_POST['status'] ?? 'active'),
            ':notes' => post_string('notes'),
            ':photo_path' => $photoPath,
        ];

        if ($staffId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE clinic_staff
                 SET full_name = :full_name,
                     role = :role,
                     specialty = :specialty,
                     contact_number = :contact_number,
                     availability = :availability,
                     status = :status,
                     notes = :notes,
                     photo_path = :photo_path
                 WHERE id = :id'
            );
            $stmt->execute($payload + [':id' => $staffId]);
            admin_flash('staff', 'Staff record updated.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO clinic_staff (full_name, role, specialty, contact_number, availability, status, notes, photo_path)
             VALUES (:full_name, :role, :specialty, :contact_number, :availability, :status, :notes, :photo_path)'
        );
        $stmt->execute($payload);
        admin_flash('staff', 'New staff member added.');
    }

    if ($action === 'delete_staff') {
        $staffId = post_int('staff_id');
        if ($staffId > 0) {
            $stmt = $pdo->prepare('DELETE FROM clinic_staff WHERE id = :id');
            $stmt->execute([':id' => $staffId]);
            admin_flash('staff', 'Staff member deleted.');
        }
        admin_flash('staff', 'Unable to delete staff member.', 'error');
    }
}

if (is_logged_in() && !is_admin()) {
    redirect_to('index.php?page=dashboard');
}

if (is_logged_in() && is_admin() && $page === 'login') {
    redirect_to('admin.php?page=dashboard');
}

if (is_logged_in() && is_admin() && !in_array($page, ['dashboard', 'appointments', 'schedules', 'staff'], true)) {
  redirect_to('admin.php?page=dashboard');
}

if (!is_logged_in() && $page !== 'login') {
    redirect_to('admin.php?page=login');
}

$services = [];
$staffList = [];
$appointments = [];
$schedules = [];
$stats = [
    'appointments' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'active_staff' => 0,
    'open_schedules' => 0,
];

if (is_logged_in() && is_admin()) {
    $services = $pdo->query('SELECT * FROM services ORDER BY name')->fetchAll();
    $staffList = $pdo->query('SELECT * FROM clinic_staff ORDER BY FIELD(status, "active", "inactive"), role, full_name')->fetchAll();
    $appointments = $pdo->query(
        "SELECT a.*, p.full_name AS patient_name, p.birthdate AS patient_birthdate, p.gender AS patient_gender, p.address AS patient_address, p.cellphone AS patient_cellphone,
                sc.schedule_date, sc.start_time, sc.end_time, sc.provider_name, sc.provider_role,
                sv.name AS service_name, u.username AS account_username
         FROM appointments a
         JOIN patients p ON p.id = a.patient_id
         JOIN schedules sc ON sc.id = a.schedule_id
         JOIN services sv ON sv.id = sc.service_id
         LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC, a.id DESC"
    )->fetchAll();
    $appointments = array_values(array_filter($appointments, static function (array $appointment) use ($appointmentSearch, $appointmentStatusFilter, $appointmentDateFilter): bool {
        $matchesSearch = $appointmentSearch === '' || str_contains(strtolower($appointment['patient_name'] . ' ' . $appointment['appointment_code']), strtolower($appointmentSearch));
        $matchesStatus = $appointmentStatusFilter === '' || $appointment['status'] === $appointmentStatusFilter;
        $matchesDate = $appointmentDateFilter === '' || $appointment['schedule_date'] === $appointmentDateFilter;
        return $matchesSearch && $matchesStatus && $matchesDate;
    }));
    $schedules = $pdo->query(
        "SELECT sc.*, sv.name AS service_name, st.full_name AS staff_name, st.role AS staff_role, st.specialty AS staff_specialty,
                (SELECT COUNT(*) FROM appointments a WHERE a.schedule_id = sc.id AND a.status NOT IN ('cancelled', 'no_show')) AS booked_slots
         FROM schedules sc
         JOIN services sv ON sv.id = sc.service_id
         LEFT JOIN clinic_staff st ON st.id = sc.staff_id
         ORDER BY sc.schedule_date DESC, sc.start_time DESC"
    )->fetchAll();

    $stats = [
        'appointments' => count($appointments),
        'pending' => (int) $pdo->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'pending'")->fetch()['total'],
        'confirmed' => (int) $pdo->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'confirmed'")->fetch()['total'],
        'active_staff' => (int) $pdo->query("SELECT COUNT(*) AS total FROM clinic_staff WHERE status = 'active'")->fetch()['total'],
        'open_schedules' => (int) $pdo->query("SELECT COUNT(*) AS total FROM schedules WHERE status = 'open' AND schedule_date >= CURDATE()")->fetch()['total'],
    ];
}

$appointmentsPage = paginate_rows($appointments, max(1, (int) ($_GET['appt_page'] ?? 1)), 2);
$schedulesPage = paginate_rows($schedules, max(1, (int) ($_GET['sched_page'] ?? 1)), 2);
$staffPage = paginate_rows($staffList, max(1, (int) ($_GET['staff_page'] ?? 1)), 2);

$editingStaff = $editingStaff ?? null;
$appointmentBaseParams = [
  'page' => 'appointments',
  'appointment_search' => $appointmentSearch,
  'appointment_status' => $appointmentStatusFilter,
  'appointment_date' => $appointmentDateFilter,
];
$staffBaseParams = ['page' => 'staff'];
if ($editingStaffId > 0) {
    $staffBaseParams['edit_staff'] = $editingStaffId;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Barangay Balagan Health Center | Admin Dashboard</title>
  <style>
    :root {
      --bg: #edf8fb;
      --bg-2: #e7f6ef;
      --surface: rgba(255, 255, 255, 0.88);
      --line: rgba(15, 118, 110, 0.14);
      --text: #0f172a;
      --muted: #567182;
      --primary: #0f766e;
      --secondary: #2563eb;
      --success: #16a34a;
      --warning: #d97706;
      --danger: #dc2626;
      --shadow: 0 18px 60px rgba(15, 23, 42, 0.10);
      --shadow-soft: 0 10px 30px rgba(15, 23, 42, 0.07);
      --radius-xl: 28px;
      --radius-lg: 22px;
      --radius-md: 16px;
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
    .glow { position: fixed; border-radius: 999px; pointer-events: none; filter: blur(36px); opacity: 0.55; z-index: 0; }
    .glow.one { width: 260px; height: 260px; background: rgba(20, 184, 166, 0.16); top: -90px; right: -80px; }
    .glow.two { width: 220px; height: 220px; background: rgba(37, 99, 235, 0.12); bottom: 10%; left: -70px; }
    .shell { width: min(var(--max), calc(100% - 32px)); margin: 0 auto; position: relative; z-index: 1; }
    .admin-sidebar {
      position: fixed;
      inset: 0 auto 0 0;
      z-index: 5;
      width: 264px;
      display: flex;
      flex-direction: column;
      gap: 24px;
      padding: 24px 18px;
      background: rgba(255, 255, 255, 0.9);
      border-right: 1px solid rgba(15, 118, 110, 0.14);
      backdrop-filter: blur(16px);
    }
    .admin-sidebar .brand { align-items: flex-start; gap: 12px; }
    .admin-sidebar .brand-mark { width: 46px; height: 46px; border-radius: 14px; }
    .admin-sidebar .brand-copy strong { font-size: 0.98rem; letter-spacing: 0; }
    .admin-sidebar .brand-copy span { font-size: 0.82rem; }
    .admin-user {
      width: 100%;
      justify-content: flex-start;
      white-space: normal;
      border-radius: 12px;
    }
    .admin-main {
      width: min(var(--max), calc(100% - 320px));
      margin: 0 28px 0 292px;
    }
    .brand { display: flex; align-items: center; gap: 14px; }
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
    .brand-copy span { color: var(--muted); font-size: 0.9rem; }
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
    .admin-nav {
      display: flex;
      flex: 1;
      flex-direction: column;
      gap: 7px;
    }
    .admin-nav a {
      display: flex;
      align-items: center;
      min-height: 44px;
      padding: 11px 13px;
      border: 1px solid transparent;
      border-radius: 10px;
      background: transparent;
      color: #294454;
      font-weight: 700;
      transition: background 180ms ease, border-color 180ms ease, color 180ms ease;
    }
    .admin-nav a:hover,
    .admin-nav a:focus-visible {
      color: var(--primary);
      background: rgba(20, 184, 166, 0.1);
      border-color: rgba(15, 118, 110, 0.14);
    }
    .admin-nav a.active {
      color: var(--primary);
      background: rgba(20, 184, 166, 0.1);
      border-color: rgba(15, 118, 110, 0.14);
    }
    .logout-form {
      margin: auto 0 0;
      width: 100%;
    }
    .logout-form .btn { width: 100%; }
    .admin-footer { margin-left: 264px; }
    .admin-footer .shell { width: min(var(--max), calc(100% - 56px)); margin: 0 28px; }
    .hero {
      margin-top: 12px;
      padding: 20px 0 28px;
      display: grid;
      grid-template-columns: 1.05fr 0.95fr;
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
 …5063 tokens truncated…     <span class="status-badge">Live control</span>
        </div>
        <div class="availability">
          <div class="availability-card">
            <span>Staff roles</span>
            <strong>Doctor, Nurse, Midwife</strong>
          </div>
          <div class="availability-card">
            <span>Services</span>
            <strong><?php echo count($services); ?></strong>
          </div>
          <div class="availability-card">
            <span>Schedules</span>
            <strong><?php echo count($schedules); ?></strong>
          </div>
          <div class="availability-card">
            <span>Clinic status</span>
            <strong>Managed by admin</strong>
          </div>
        </div>
        <div class="appointment-card">
          <h3>Recommended workflow</h3>
          <p>Review pending appointments first, then publish or close schedules, and keep staff records updated so every booking points to the right health worker.</p>
          <div class="toolbar">
            <a class="chip active" href="admin.php?page=appointments">Appointments</a>
            <a class="chip" href="admin.php?page=schedules">Schedules</a>
            <a class="chip" href="admin.php?page=staff">Staff</a>
          </div>
        </div>
      </aside>
    </section>
    <?php endif; ?>

    <?php if ($page === 'appointments'): ?>
    <section class="section" id="appointments">
      <div class="card reveal">
        <div class="section-head">
          <div>
            <h2>Appointments</h2>
            <p>Update appointment status and remarks submitted by residents.</p>
          </div>
          <span class="pill"><?php echo $appointmentsPage['total']; ?> records</span>
        </div>
        <form method="get" class="form-grid" style="margin-bottom:16px;">
          <input type="hidden" name="page" value="appointments">
          <div class="field"><label>Search</label><input name="appointment_search" value="<?php echo e($appointmentSearch); ?>" placeholder="Patient or code"></div>
          <div class="field"><label>Status</label><select name="appointment_status"><option value="">All</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?php echo e($value); ?>" <?php echo $appointmentStatusFilter === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Date</label><input name="appointment_date" type="date" value="<?php echo e($appointmentDateFilter); ?>"></div>
          <div class="field"><button class="btn btn-secondary" type="submit">Filter</button></div>
        </form>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>Patient</th>
                <th>Schedule</th>
                <th>Concern</th>
                <th>Status</th>
                <th>Admin Update</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($appointmentsPage['items'] as $appointment): ?>
                <tr>
                  <td>
                    <strong><?php echo e($appointment['appointment_code']); ?></strong>
                    <?php echo e(format_date($appointment['created_at'])); ?><br>
                    User: <?php echo e($appointment['account_username'] ?: 'N/A'); ?>
                  </td>
                  <td>
                    <strong><?php echo e($appointment['patient_name']); ?></strong>
                    Age: <?php echo age_from_birthdate((string) $appointment['patient_birthdate']); ?> | <?php echo e($appointment['patient_gender']); ?><br>
                    Birthdate: <?php echo e(format_date($appointment['patient_birthdate'])); ?><br>
                    Phone: <?php echo e($appointment['patient_cellphone']); ?><br>
                    Address: <?php echo e($appointment['patient_address']); ?>
                  </td>
                  <td>
                    <strong><?php echo e($appointment['service_name']); ?></strong>
                    <?php echo e(format_date($appointment['schedule_date'])); ?><br>
                    <?php echo e(format_time($appointment['start_time'])); ?> - <?php echo e(format_time($appointment['end_time'])); ?><br>
                    <?php echo e($appointment['provider_name']); ?>, <?php echo e($appointment['provider_role']); ?>
                  </td>
                  <td><?php echo e($appointment['concern'] ?: 'N/A'); ?></td>
                  <td><span class="status-pill <?php echo e($appointment['status']); ?>"><?php echo e($statusLabels[$appointment['status']] ?? $appointment['status']); ?></span></td>
                  <td>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="action" value="save_appointment_status">
                      <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                      <select name="status">
                        <?php foreach ($statusLabels as $value => $label): ?>
                          <option value="<?php echo e($value); ?>" <?php echo $appointment['status'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <textarea name="remarks" placeholder="Remarks"><?php echo e($appointment['remarks']); ?></textarea>
                      <button class="btn btn-secondary" type="submit">Update</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$appointmentsPage['items']): ?>
                <tr><td colspan="6" class="empty">No appointment requests yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($appointmentsPage['pages'] > 1): ?>
          <div class="pagination">
            <?php render_pagination_links('admin.php', $appointmentBaseParams, 'appt_page', $appointmentsPage); ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($page === 'schedules'): ?>
    <section class="section" id="schedules">
      <div class="card reveal">
        <div class="section-head">
          <div>
            <h2>Schedules</h2>
            <p>Post new schedules and control whether they stay open or closed.</p>
          </div>
        </div>
        <form method="post" class="form-grid" style="margin-bottom: 16px;">
          <input type="hidden" name="action" value="save_schedule">
          <div class="field full">
            <label>Service</label>
            <select name="service_id" required>
              <?php foreach ($services as $service): ?>
                <option value="<?php echo (int) $service['id']; ?>"><?php echo e($service['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field full">
            <label>Clinic staff</label>
            <select name="staff_id" id="schedule_staff">
              <option value="0">Manual provider entry</option>
              <?php foreach ($staffList as $staff): ?>
                <option value="<?php echo (int) $staff['id']; ?>" data-name="<?php echo e($staff['full_name']); ?>" data-role="<?php echo e($staff['role']); ?>">
                  <?php echo e($staff['full_name'] . ' - ' . $staff['role']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Provider name</label><input name="provider_name" id="provider_name" placeholder="Nurse / Midwife / Doctor" required></div>
          <div class="field"><label>Provider role</label><input name="provider_role" id="provider_role" placeholder="Doctor, Nurse, Midwife" required></div>
          <div class="field"><label>Date</label><input name="schedule_date" type="date" required></div>
          <div class="field"><label>Start time</label><input name="start_time" type="time" required></div>
          <div class="field"><label>End time</label><input name="end_time" type="time" required></div>
          <div class="field"><label>Slot limit</label><input name="slot_limit" type="number" min="1" value="20" required></div>
          <div class="field"><label>Status</label><select name="status"><option value="open">Open</option><option value="closed">Closed</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
          <div class="field full"><label>Notes</label><textarea name="notes" placeholder="Example: prenatal, injection, or consultation schedule"></textarea></div>
          <div class="field full"><button class="btn btn-primary" type="submit">Post schedule</button></div>
        </form>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Service</th>
                <th>Staff</th>
                <th>Date & Time</th>
                <th>Slots</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($schedulesPage['items'] as $schedule): ?>
                <?php
                  $booked = (int) $schedule['booked_slots'];
                  $limit = max(1, (int) $schedule['slot_limit']);
                  $remaining = max(0, $limit - $booked);
                ?>
                <tr>
                  <td><strong><?php echo e($schedule['service_name']); ?></strong><?php echo e($schedule['notes'] ?: 'No notes.'); ?></td>
                  <td>
                    <strong><?php echo e($schedule['staff_name'] ?: $schedule['provider_name']); ?></strong>
                    <?php echo e($schedule['staff_role'] ?: $schedule['provider_role']); ?><br>
                    <?php echo e($schedule['staff_specialty'] ?: 'Manual provider'); ?>
                  </td>
                  <td>
                    <?php echo e(format_date($schedule['schedule_date'])); ?><br>
                    <?php echo e(format_time($schedule['start_time'])); ?> - <?php echo e(format_time($schedule['end_time'])); ?>
                  </td>
                  <td><?php echo $booked; ?> / <?php echo $limit; ?><br><?php echo $remaining; ?> open</td>
                  <td><span class="status-pill <?php echo e($schedule['status']); ?>"><?php echo e(ucfirst($schedule['status'])); ?></span></td>
                  <td>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="action" value="update_schedule_status">
                      <input type="hidden" name="schedule_id" value="<?php echo (int) $schedule['id']; ?>">
                      <select name="status">
                        <option value="open" <?php echo $schedule['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                        <option value="closed" <?php echo $schedule['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        <option value="completed" <?php echo $schedule['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $schedule['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                      </select>
                      <button class="btn btn-secondary" type="submit">Update</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$schedulesPage['items']): ?>
                <tr><td colspan="6" class="empty">No schedules posted yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($schedulesPage['pages'] > 1): ?>
          <div class="pagination">
            <?php render_pagination_links('admin.php', ['page' => 'schedules'], 'sched_page', $schedulesPage); ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($page === 'staff'): ?>
    <section class="section" id="staff">
      <div class="section-head">
        <div>
          <h2>Clinic Staff</h2>
          <p>Add, update, or delete doctors, nurses, and midwives from the same page.</p>
        </div>
        <span class="pill"><?php echo $staffPage['total']; ?> staff records</span>
      </div>

      <div class="dashboard">
        <div class="panel reveal">
          <h2 style="margin-top:0; font-family:var(--serif);"><?php echo $editingStaff ? 'Edit staff' : 'Add staff'; ?></h2>
          <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="action" value="save_staff">
            <input type="hidden" name="staff_id" value="<?php echo (int) ($editingStaff['id'] ?? 0); ?>">
            <div class="field full"><label>Full name</label><input name="full_name" value="<?php echo e($editingStaff['full_name'] ?? ''); ?>" required></div>
            <div class="field"><label>Role</label>
              <select name="role">
                <option value="Doctor" <?php echo (($editingStaff['role'] ?? '') === 'Doctor') ? 'selected' : ''; ?>>Doctor</option>
                <option value="Nurse" <?php echo (($editingStaff['role'] ?? '') === 'Nurse') ? 'selected' : ''; ?>>Nurse</option>
                <option value="Midwife" <?php echo (($editingStaff['role'] ?? '') === 'Midwife') ? 'selected' : ''; ?>>Midwife</option>
              </select>
            </div>
            <div class="field"><label>Status</label>
              <select name="status">
                <option value="active" <?php echo (($editingStaff['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (($editingStaff['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
              </select>
            </div>
            <div class="field full"><label>Specialty</label><input name="specialty" value="<?php echo e($editingStaff['specialty'] ?? ''); ?>" placeholder="General Medicine, Maternal Care, etc." required></div>
            <div class="field"><label>Contact number</label><input name="contact_number" value="<?php echo e($editingStaff['contact_number'] ?? ''); ?>" required></div>
            <div class="field"><label>Availability</label><input name="availability" value="<?php echo e($editingStaff['availability'] ?? 'Available'); ?>" placeholder="Mon-Fri 8:00 AM - 5:00 PM" required></div>
            <div class="field full"><label>Photo</label><input name="staff_photo" type="file" accept="image/*"></div>
            <div class="field full"><label>Notes</label><textarea name="notes" placeholder="Short staff notes"><?php echo e($editingStaff['notes'] ?? ''); ?></textarea></div>
            <div class="field full" style="display:flex; gap:12px; flex-wrap:wrap;">
              <button class="btn btn-primary" type="submit"><?php echo $editingStaff ? 'Update staff' : 'Save staff'; ?></button>
              <?php if ($editingStaff): ?><a class="btn btn-secondary" href="admin.php?page=staff">Cancel edit</a><?php endif; ?>
            </div>
          </form>
        </div>

        <div class="stack reveal">
          <?php foreach ($staffPage['items'] as $staff): ?>
            <article class="staff-card">
              <div class="avatar-mini">
                <?php if (!empty($staff['photo_path'])): ?>
                  <img src="<?php echo e($staff['photo_path']); ?>" alt="<?php echo e($staff['full_name']); ?>">
                <?php else: ?>
                  <?php echo e(staff_initials((string) $staff['full_name'])); ?>
                <?php endif; ?>
              </div>
              <div class="stack">
                <div style="display:flex; align-items:start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                  <div>
                    <h3><?php echo e($staff['full_name']); ?></h3>
                    <p><?php echo e($staff['specialty']); ?></p>
                  </div>
                  <span class="badge <?php echo e(strtolower((string) $staff['role'])); ?>"><?php echo e($staff['role']); ?></span>
                </div>
                <p><?php echo e($staff['availability']); ?><br><?php echo e($staff['contact_number']); ?><br>Status: <span class="badge <?php echo e($staff['status']); ?>"><?php echo e(ucfirst($staff['status'])); ?></span></p>
                <p><?php echo e($staff['notes'] ?: 'No notes.'); ?></p>
                <div class="form-actions">
                  <a class="btn btn-secondary" href="<?php echo e(build_url('admin.php', array_merge($staffBaseParams, ['edit_staff' => (int) $staff['id']]))); ?>#staff">Edit</a>
                  <form method="post" onsubmit="return confirm('Delete this staff record?');" style="margin:0;">
                    <input type="hidden" name="action" value="delete_staff">
                    <input type="hidden" name="staff_id" value="<?php echo (int) $staff['id']; ?>">
                    <button class="btn btn-secondary" type="submit">Delete</button>
                  </form>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if (!$staffPage['items']): ?><div class="empty">No staff records yet.</div><?php endif; ?>
          <?php if ($staffPage['pages'] > 1): ?>
            <div class="pagination">
              <?php render_pagination_links('admin.php', $staffBaseParams, 'staff_page', $staffPage); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </main>

  <footer class="admin-footer">
    <div class="shell">Barangay Balagan Health Center admin dashboard</div>
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

    const staffSelect = document.getElementById('schedule_staff');
    const providerName = document.getElementById('provider_name');
    const providerRole = document.getElementById('provider_role');
    if (staffSelect && providerName && providerRole) {
      const syncProvider = () => {
        const option = staffSelect.selectedOptions[0];
        if (!option || option.value === '0') {
          providerName.value = providerName.value || 'Health Staff';
          providerRole.value = providerRole.value || 'Health Staff';
          return;
        }
        providerName.value = option.dataset.name || providerName.value;
        providerRole.value = option.dataset.role || providerRole.value;
      };
      staffSelect.addEventListener('change', syncProvider);
      syncProvider();
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
          if (progress < 1) requestAnimationFrame(animate);
        };
        requestAnimationFrame(animate);
      });
    }
  })();
</script>
</body>
</html>

