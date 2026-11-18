<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role   = strtolower(trim((string) ($_SESSION['role'] ?? $_SESSION['user']['role_name'] ?? '')));
$userId = (int) ($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? 0);

if ($userId <= 0 || $role !== 'instructor') {
    header('Location: ../index.php?page=login');
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $envPath = __DIR__ . '/../.env';
    if (is_file($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }

    $dbUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
    if ($dbUrl !== '') {
        $parts = parse_url($dbUrl);
        $host  = $parts['host'] ?? '';
        $port  = $parts['port'] ?? 5432;
        $db    = ltrim($parts['path'] ?? '', '/');
        $user  = $parts['user'] ?? '';
        $pass  = $parts['pass'] ?? '';
        $dsn   = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function formatSessionDate(string $iso): string
{
    static $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
    $ts = strtotime($iso);
    if (!$ts) {
        return $iso;
    }
    return date('l', $ts) . ', ' . date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

function formatTimeDot(string $dbTime): string
{
    $ts = strtotime($dbTime);
    return $ts ? date('H.i', $ts) : substr($dbTime, 0, 5);
}

function formatTimeRange(string $start, string $end, bool $withZone = true): string
{
    return $start . ' - ' . $end . ($withZone ? ' WIB' : '');
}

$rawName        = (string) ($_SESSION['user']['name'] ?? $_SESSION['name'] ?? 'Instructor');
$instructorName = 'Coach ' . preg_replace('/^Coach\s+/i', '', trim($rawName));
$instructorId   = 0;
$specialization = null;

$currentSession    = null;
$upcomingSchedules = [];
$classOptions      = [];

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $pdo->exec("SET TIME ZONE 'Asia/Jakarta'");

        $stmtInst = $pdo->prepare("
            SELECT i.instructor_id, i.specialization, u.name
              FROM sooma_wellness.instructors i
              JOIN sooma_wellness.users u ON u.user_id = i.user_id
             WHERE i.user_id = :user_id
             LIMIT 1
        ");
        $stmtInst->execute([':user_id' => $userId]);
        $instData = $stmtInst->fetch(PDO::FETCH_ASSOC);

        if ($instData) {
            $instructorId   = (int) $instData['instructor_id'];
            $specialization = $instData['specialization'];
            $cleanDbName    = preg_replace('/^Coach\s+/i', '', trim((string) $instData['name']));
            $instructorName = 'Coach ' . $cleanDbName;
        }

        if ($instructorId > 0) {
            $stmtAttendees = $pdo->prepare("
                SELECT ba.attendee_name, ba.medical_notes, ba.not_pregnant
                  FROM sooma_wellness.booking_attendees ba
                  JOIN sooma_wellness.bookings b ON b.booking_id = ba.booking_id
                 WHERE b.schedule_id = :schedule_id
                   AND LOWER(COALESCE(b.booking_status, '')) NOT IN ('cancelled', 'expired', 'rejected')
                 ORDER BY ba.attendee_id ASC
            ");

            $fetchParticipants = function (int $scheduleId) use ($stmtAttendees): array {
                $stmtAttendees->execute([':schedule_id' => $scheduleId]);
                $rows = $stmtAttendees->fetchAll(PDO::FETCH_ASSOC);
                $list = [];
                foreach ($rows as $r) {
                    $note = trim((string) ($r['medical_notes'] ?? ''));
                    if (isset($r['not_pregnant']) && $r['not_pregnant'] === false) {
                        $note = $note !== '' ? ($note . ' (Pregnant)') : 'Pregnant';
                    }
                    $list[] = [
                        'name'         => (string) $r['attendee_name'],
                        'medical_note' => $note !== '' ? $note : '-',
                    ];
                }
                return $list;
            };

            // 1. Current Session
            $stmtCurrent = $pdo->prepare("
                SELECT cs.schedule_id,
                       mc.class_name,
                       cs.class_type,
                       cs.level,
                       cs.schedule_date,
                       cs.start_time,
                       cs.end_time,
                       cs.member_slot
                  FROM sooma_wellness.class_schedules cs
                  JOIN sooma_wellness.master_classes mc ON mc.class_id = cs.class_id
                 WHERE cs.instructor_id = :instructor_id
                   AND cs.schedule_date = CURRENT_DATE
                   AND CURRENT_TIME BETWEEN cs.start_time AND cs.end_time
                 ORDER BY cs.start_time ASC
                 LIMIT 1
            ");
            $stmtCurrent->execute([':instructor_id' => $instructorId]);
            $currRow = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

            if ($currRow) {
                $participants = $fetchParticipants((int) $currRow['schedule_id']);
                $roomLabel = trim((string) ($currRow['class_type'] ?? ''));
                if (!empty($currRow['level'])) {
                    $roomLabel .= ($roomLabel !== '' ? ' • ' : '') . $currRow['level'];
                }

                $currentSession = [
                    'class'        => (string) $currRow['class_name'],
                    'start'        => formatTimeDot((string) $currRow['start_time']),
                    'end'          => formatTimeDot((string) $currRow['end_time']),
                    'status'       => 'On Going',
                    'room'         => $roomLabel !== '' ? $roomLabel : 'Main Studio',
                    'capacity'     => (int) $currRow['member_slot'],
                    'participants' => $participants,
                ];
            }

            $stmtUpcoming = $pdo->prepare("
                SELECT cs.schedule_id,
                       mc.class_name,
                       cs.class_type,
                       cs.level,
                       cs.schedule_date,
                       cs.start_time,
                       cs.end_time,
                       cs.member_slot
                  FROM sooma_wellness.class_schedules cs
                  JOIN sooma_wellness.master_classes mc ON mc.class_id = cs.class_id
                 WHERE cs.instructor_id = :instructor_id
                   AND (
                        cs.schedule_date > CURRENT_DATE
                        OR (cs.schedule_date = CURRENT_DATE AND cs.start_time > CURRENT_TIME)
                   )
                 ORDER BY cs.schedule_date ASC, cs.start_time ASC
            ");
            $stmtUpcoming->execute([':instructor_id' => $instructorId]);
            $upRows = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC);

            foreach ($upRows as $row) {
                $schedId = (int) $row['schedule_id'];
                $upcomingSchedules[] = [
                    'id'           => $schedId,
                    'date'         => (string) $row['schedule_date'],
                    'start'        => formatTimeDot((string) $row['start_time']),
                    'end'          => formatTimeDot((string) $row['end_time']),
                    'class'        => (string) $row['class_name'],
                    'capacity'     => (int) $row['member_slot'],
                    'participants' => $fetchParticipants($schedId),
                ];
            }
        }

        $stmtClasses = $pdo->query("SELECT DISTINCT class_name FROM sooma_wellness.master_classes ORDER BY class_name ASC");
        if ($stmtClasses) {
            $classOptions = $stmtClasses->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    } catch (Throwable $ex) {
        error_log('[instructor-dashboard] ' . $ex->getMessage());
    }
}

if (empty($classOptions)) {
    $classOptions = ['Reformer Pilates', 'Morning Pilates', 'Mat Pilates', 'Yoga'];
}

$dayOptions = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$perPage    = 5;

$pageTitle  = 'Instructor Dashboard';
$bodyClass  = 'instructor-dashboard-page';
$headerType = 'instructor';
$headerLogo = 'black';

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="../instructors/assets/css/instructor.css">


<main class="instructor-page" data-per-page="<?= (int) $perPage ?>">

    <section class="current-session<?= $currentSession ? '' : ' current-session--empty' ?>" aria-labelledby="current-title">
        <span class="current-session__deco" aria-hidden="true"></span>

        <header class="section-head">
            <svg class="section-head__icon" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor"
                stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z" />
                <circle cx="12" cy="9.5" r="2.5" />
            </svg>
            <h2 class="section-head__title" id="current-title">Current Session</h2>
        </header>

        <?php if ($currentSession):
            $cCount = count($currentSession['participants']);
            $cCap   = (int) $currentSession['capacity'];
        ?>
            <p class="status-pill">
                <span class="status-pill__dot" aria-hidden="true"></span>
                <?= e($currentSession['status']) ?>
            </p>

            <div class="current-session__class">
                <h3 class="current-session__name"><?= e($currentSession['class']) ?></h3>
                <span class="current-session__divider" aria-hidden="true"></span>
                <p class="current-session__time"><?= e(formatTimeRange($currentSession['start'], $currentSession['end'], false)) ?></p>
            </div>

            <div class="current-session__meta">
                <p class="quota-chip">
                    <span><?= $cCount ?> / <strong><?= $cCap ?></strong> Participants</span>
                    <span class="quota-meter" role="img" aria-label="<?= $cCount ?> of <?= $cCap ?> seats filled">
                        <?php for ($i = 1; $i <= min($cCap, 10); $i++): ?>
                            <i class="<?= $i <= $cCount ? 'is-filled' : '' ?>"></i>
                        <?php endfor; ?>
                    </span>
                </p>

                <?php if (!empty($currentSession['room'])): ?>
                    <p class="room-chip">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 21V8l9-5 9 5v13" />
                            <path d="M9 21v-7h6v7" />
                        </svg>
                        <?= e($currentSession['room']) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="current-session__empty">There is no session in progress right now.</p>
        <?php endif; ?>
    </section>

    <header class="section-head section-head--schedule">
        <svg class="section-head__icon" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor"
            stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="5" width="18" height="16" rx="3" />
            <path d="M3 10h18M8 3v4M16 3v4" />
            <path d="M8 14h2M12 14h2M8 17.5h2" />
        </svg>
        <h2 class="section-head__title" id="schedule-title">Coming Soon Schedule</h2>
    </header>

    <div class="schedule-filters" role="group" aria-label="Filter schedule">
        <label class="filter-select filter-select--day">
            <i class="ph ph-calendar-blank filter-select__icon" aria-hidden="true"></i>
            <select id="filterDay" aria-label="Filter by day">
                <option value="">All Days</option>
                <?php foreach ($dayOptions as $day): ?>
                    <option value="<?= e(strtolower($day)) ?>"><?= e($day) ?></option>
                <?php endforeach; ?>
            </select>
            <i class="ph ph-caret-down filter-select__chevron" aria-hidden="true"></i>
        </label>

        <label class="filter-select filter-select--class">
            <i class="ph ph-tag filter-select__icon" aria-hidden="true"></i>
            <select id="filterClass" aria-label="Filter by class">
                <option value="">All Classes</option>
                <?php foreach ($classOptions as $class): ?>
                    <option value="<?= e(strtolower($class)) ?>"><?= e($class) ?></option>
                <?php endforeach; ?>
            </select>
            <i class="ph ph-caret-down filter-select__chevron" aria-hidden="true"></i>
        </label>
    </div>

    <section class="schedule-card" aria-labelledby="schedule-title">
        <div class="schedule-card__scroll">
            <table class="schedule-table">
                <colgroup>
                    <col style="width:30.2%">
                    <col style="width:32.4%">
                    <col style="width:24.3%">
                    <col style="width:13.1%">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Day &amp; Date</th>
                        <th scope="col">Time Session</th>
                        <th scope="col">Class</th>
                        <th scope="col">Participants</th>
                    </tr>
                </thead>
                <tbody id="scheduleBody">
                    <?php foreach ($upcomingSchedules as $s):
                        $count     = count($s['participants']);
                        $capacity  = (int) $s['capacity'];
                        $isFull    = $count >= $capacity;
                        $dateLabel = formatSessionDate($s['date']);
                        $timeLabel = formatTimeRange($s['start'], $s['end']);
                        $dayKey    = strtolower(date('l', strtotime($s['date'])));

                        $sessionData = [
                            'class'        => $s['class'],
                            'date'         => $dateLabel,
                            'time'         => $timeLabel,
                            'count'        => $count,
                            'capacity'     => $capacity,
                            'participants' => $s['participants'],
                        ];
                    ?>
                        <tr data-day="<?= e($dayKey) ?>" data-class="<?= e(strtolower($s['class'])) ?>">
                            <td><?= e($dateLabel) ?></td>
                            <td><?= e($timeLabel) ?></td>
                            <td><?= e($s['class']) ?></td>
                            <td>
                                <div class="participants-cell">
                                    <span class="quota-pill<?= $isFull ? ' quota-pill--full' : '' ?>"
                                        title="<?= $isFull ? 'Class is full' : ($capacity - $count) . ' seats left' ?>">
                                        <?= $count ?> / <?= $capacity ?>
                                    </span>
                                    <button type="button" class="icon-btn js-view-session"
                                        aria-haspopup="dialog" aria-controls="sessionDetailModal"
                                        aria-label="View participants: <?= e($s['class']) ?>, <?= e($dateLabel) ?>"
                                        title="View detail"
                                        data-session="<?= e(json_encode($sessionData, JSON_UNESCAPED_UNICODE)) ?>">
                                        <i class="ph-fill ph-eye" style="font-size: 26px;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr class="schedule-empty" id="scheduleEmpty" <?= empty($upcomingSchedules) ? '' : 'hidden' ?>>
                        <td colspan="4"><?= empty($upcomingSchedules) ? 'No upcoming schedules assigned yet.' : 'No sessions match your filter.' ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="schedule-pagination" id="pagination" aria-label="Schedule pagination">
            <button type="button" class="page-arrow" id="pagePrev" aria-label="Previous page">
                <i class="ph-bold ph-caret-left" style="font-size: 20px;" aria-hidden="true"></i>
            </button>
            <span class="page-numbers" id="pageNumbers"></span>
            <button type="button" class="page-arrow" id="pageNext" aria-label="Next page">
                <i class="ph-bold ph-caret-right" style="font-size: 20px;" aria-hidden="true"></i>
            </button>
        </nav>
    </section>
</main>

<div class="session-overlay" id="sessionDetailModal" aria-hidden="true">
    <div class="session-modal" role="dialog" aria-modal="true" aria-labelledby="sessionModalTitle" tabindex="-1">
        <header class="session-modal__header">
            <div>
                <p class="session-modal__eyebrow">Session Detail</p>
                <h2 class="session-modal__title" id="sessionModalTitle" data-field="class"></h2>
            </div>
            <button type="button" class="session-modal__close" aria-label="Close" data-close>&times;</button>
        </header>

        <div class="session-modal__body">
            <dl class="session-info">
                <div>
                    <dt>Class</dt>
                    <dd data-field="class"></dd>
                </div>
                <div>
                    <dt>Day &amp; Date</dt>
                    <dd data-field="date"></dd>
                </div>
                <div>
                    <dt>Time Session</dt>
                    <dd data-field="time"></dd>
                </div>
                <div>
                    <dt>Total Quota</dt>
                    <dd><span data-field="quota"></span></dd>
                </div>
            </dl>

            <section class="participant-section" aria-labelledby="participantsHeading">
                <h3 class="participant-section__title" id="participantsHeading">Registered Participants</h3>
                <ol class="participant-list" id="participantList"></ol>
                <p class="participant-section__empty" id="participantEmpty" hidden>No participants registered yet.</p>
            </section>
        </div>

        <footer class="session-modal__footer">
            <button type="button" class="btn-close-modal" data-close>Close</button>
        </footer>
    </div>
</div>

<script src="<?= e($basePath) ?>js/instructor.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>