<?php
$headerType = 'customer';
$headerLogo = 'black';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../login-regist/connection.php';

$user_avatar = $_SESSION['user']['avatar_url'] ?? 'assets/images/users/emilia-bonnita.png';
$userId = $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? ($_SESSION['user']['user_id'] ?? ($_SESSION['user']['id'] ?? null));

$schedules = [];

if ($userId && isset($pdo) && $pdo instanceof PDO) {
    try {
        $pdo->exec("SET search_path TO sooma_wellness, public");

        $sql = "
            SELECT
                b.booking_id,
                b.booking_status,
                b.is_rescheduled,
                mc.class_name,
                cs.level,
                cs.class_type,
                u_coach.name AS coach_name,
                COALESCE(cs_resched.schedule_date, cs.schedule_date) AS active_date,
                COALESCE(cs_resched.start_time, cs.start_time) AS active_start_time,
                cs.member_slot,
                COUNT(ba.attendee_id) AS attendee_count
            FROM bookings b
            JOIN customers c ON b.customer_id = c.customer_id
            JOIN class_schedules cs ON b.schedule_id = cs.schedule_id
            JOIN master_classes mc ON cs.class_id = mc.class_id
            JOIN instructors i ON cs.instructor_id = i.instructor_id
            JOIN users u_coach ON i.user_id = u_coach.user_id
            LEFT JOIN class_schedules cs_resched ON b.reschedule_schedule_id = cs_resched.schedule_id
            LEFT JOIN booking_attendees ba ON b.booking_id = ba.booking_id
            WHERE c.user_id = :user_id
            GROUP BY
                b.booking_id,
                b.booking_status,
                b.is_rescheduled,
                mc.class_name,
                cs.level,
                cs.class_type,
                u_coach.name,
                cs_resched.schedule_date,
                cs.schedule_date,
                cs_resched.start_time,
                cs.start_time,
                cs.member_slot
            ORDER BY active_date DESC, active_start_time ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');

        foreach ($dbRows as $r) {
            $rawStatus = strtolower((string) ($r['booking_status'] ?? 'confirmed'));
            $isPast = (!empty($r['active_date']) && $r['active_date'] < $today);

            if ($rawStatus === 'cancelled') {
                $tabStatus = 'history';
                $result = 'cancelled';
            } elseif ($rawStatus === 'completed' || $isPast) {
                $tabStatus = 'history';
                $result = 'completed';
            } else {
                $tabStatus = 'upcoming';
                $result = null;
            }

            $classLabel = trim((string) $r['class_name'] . (!empty($r['level']) ? ' ' . $r['level'] : ''));
            $dateFormatted = !empty($r['active_date']) ? date('d M Y', strtotime((string) $r['active_date'])) : '-';
            $timeFormatted = !empty($r['active_start_time']) ? date('H.i', strtotime((string) $r['active_start_time'])) . ' WIB' : '';
            $dateLabel = trim($dateFormatted . ' ' . $timeFormatted);
            $countText = (int) ($r['attendee_count'] ?? 0) . ' / ' . (int) ($r['member_slot'] ?? 0);

            $item = [
                'id'          => (int) $r['booking_id'],
                'date_time'   => $dateLabel,
                'coach'       => $r['coach_name'] ?? '-',
                'class_name'  => $classLabel,
                'type'        => $r['class_type'] ?: 'Public',
                'participant' => $countText,
                'status'      => $tabStatus,
            ];

            if ($result !== null) {
                $item['result'] = $result;
            }

            $schedules[] = $item;
        }
    } catch (Throwable $e) {
        error_log('My Schedule SELECT error: ' . $e->getMessage());
        $schedules = [];
    }
}

$profile_menu = [
    ['label' => 'My profile',     'href' => 'profile.php',             'icon' => 'ph ph-user-circle'],
    ['label' => 'My transaction', 'href' => 'profile-transaction.php', 'icon' => 'ph ph-receipt'],
    ['label' => 'My schedule',    'href' => 'profile-schedule.php',    'icon' => 'ph ph-calendar-blank'],
    ['label' => 'My package',     'href' => 'profile-package.php',     'icon' => 'ph ph-package'],
];

$tabs = [
    'upcoming' => 'Upcoming',
    'history'  => 'History',
];

$per_page = 2;

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function split_date_time(string $date_time): array
{
    if (preg_match('/^(.*\d{4})\s+(.+)$/u', trim($date_time), $m)) {
        return [$m[1], $m[2]];
    }
    return [$date_time, ''];
}

function schedule_url(string $tab, int $page = 1): string
{
    $query = ['tab' => $tab];
    if ($page > 1) {
        $query['page'] = $page;
    }
    return '?' . http_build_query($query);
}

$tab = $_GET['tab'] ?? 'upcoming';
if (!is_string($tab) || !array_key_exists($tab, $tabs)) {
    $tab = 'upcoming';
}

$filtered = array_values(array_filter($schedules, fn(array $s): bool => $s['status'] === $tab));
$total_pages = max(1, (int) ceil(count($filtered) / $per_page));

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 1]]);
$page = min((int) $page, $total_pages);

$rows = array_slice($filtered, ($page - 1) * $per_page, $per_page);

$sidebar_current = basename($_SERVER['SCRIPT_NAME'] ?? 'profile-schedule.php');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$current_page = '';
$navbar_avatar = $user_avatar;
$navbar_profile_href = 'profile.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Schedule — Sooma Wellness</title>
    <meta name="robots" content="noindex">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Montserrat+Alternates:wght@400&family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="../assets/css/header.css">
    <link rel="stylesheet" href="../assets/css/profile-schedule.css">
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>

<body>

    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="schedule-page">

        <aside class="menu-card" aria-labelledby="menu-title">
            <h1 class="menu-card__title" id="menu-title">My Schedule</h1>

            <nav aria-label="Profile menu">
                <ul class="menu-list">
                    <?php foreach ($profile_menu as $item):
                        $is_active = basename($item['href']) === $sidebar_current;
                    ?>
                        <li class="menu-list__item<?= $is_active ? ' active' : '' ?>">
                            <a href="<?= e($item['href']) ?>" <?= $is_active ? 'aria-current="page"' : '' ?>>
                                <span class="menu-list__icon">
                                    <span class="menu-list__icon">
                                        <i class="<?= e($item['icon']) ?>" aria-hidden="true"></i>
                                    </span>
                                </span>
                                <span class="menu-list__label"><?= e($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </aside>

        <div class="schedule-content">

            <nav class="schedule-tabs" aria-label="Schedule filter">
                <?php $i = 0;
                foreach ($tabs as $key => $label): ?>
                    <?php if ($i++ > 0): ?><span class="schedule-tabs__sep" aria-hidden="true"></span><?php endif; ?>
                    <a class="schedule-tabs__item<?= $key === $tab ? ' active' : '' ?>"
                        href="<?= e(schedule_url($key)) ?>"
                        <?= $key === $tab ? 'aria-current="true"' : '' ?>>
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <section class="schedule-card" aria-label="<?= e($tabs[$tab]) ?> classes">
                <div class="schedule-card__scroll">
                    <table class="schedule-table">
                        <colgroup>
                            <col style="width:164px">
                            <col style="width:130px">
                            <col style="width:186px">
                            <col style="width:184px">
                            <col style="width:95px">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Schedule</th>
                                <th scope="col">Coach</th>
                                <th scope="col">Class &amp; Level</th>
                                <th scope="col">Participant</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td class="schedule-table__empty" colspan="5">
                                        <?= $tab === 'upcoming' ? 'You have no upcoming classes.' : 'No class history yet.' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($rows as $row):
                                [$date_part, $time_part] = split_date_time($row['date_time']);
                            ?>
                                <tr>
                                    <td>
                                        <?= e($date_part) ?><?php if ($time_part !== ''): ?><br><?= e($time_part) ?><?php endif; ?>
                                    </td>
                                    <td><?= e($row['coach']) ?></td>
                                    <td><?= e($row['class_name']) ?></td>
                                    <td>
                                        <?= e($row['type']) ?><br><?= e($row['participant']) ?>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] === 'upcoming'): ?>
                                            <form class="action-cell" id="form-booking-<?= (int) $row['id'] ?>" method="post" action="timetable.php">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <input type="hidden" name="action" id="action-input-<?= (int) $row['id'] ?>" value="">

                                                <button class="btn-reschedule" type="submit" onclick="document.getElementById('action-input-<?= (int) $row['id'] ?>').value='reschedule';">
                                                    Reschedule
                                                </button>

                                                <button class="btn-cancel" type="button" onclick="confirmCancel('form-booking-<?= (int) $row['id'] ?>', 'action-input-<?= (int) $row['id'] ?>')">
                                                    Cancel
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="status-label status-label--<?= e($row['result'] ?? 'completed') ?>">
                                                <?= e(ucfirst($row['result'] ?? 'completed')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav class="pagination" aria-label="Pagination">
                        <?php if ($page > 1): ?>
                            <a class="pagination__arrow" href="<?= e(schedule_url($tab, $page - 1)) ?>" aria-label="Previous page">
                                <img src="assets/icons/pagination-prev.svg" alt="" width="8" height="15">
                            </a>
                        <?php else: ?>
                            <span class="pagination__arrow is-disabled" aria-disabled="true">
                                <img src="assets/icons/pagination-prev.svg" alt="" width="8" height="15">
                            </span>
                        <?php endif; ?>

                        <?php for ($n = 1; $n <= $total_pages; $n++): ?>
                            <?php if ($n === $page): ?>
                                <span class="pagination__page is-current" aria-current="page"><?= $n ?></span>
                            <?php else: ?>
                                <a class="pagination__page" href="<?= e(schedule_url($tab, $n)) ?>"
                                    aria-label="Page <?= $n ?>"><?= $n ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a class="pagination__arrow" href="<?= e(schedule_url($tab, $page + 1)) ?>" aria-label="Next page">
                                <img src="assets/icons/pagination-next.svg" alt="" width="8" height="15">
                            </a>
                        <?php else: ?>
                            <span class="pagination__arrow is-disabled" aria-disabled="true">
                                <img src="assets/icons/pagination-next.svg" alt="" width="8" height="15">
                            </span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>

        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>

</body>

</html>