<?php
$pageTitle = 'Instructor Dashboard';
$bodyClass = 'instructor-dashboard-page';
$headerType = 'instructor';
$headerLogo = 'black';
$basePath = '../assets/';


$instructorName = 'Coach Grace';

require_once __DIR__ . '/../includes/header.php';

$perPage = 2;

$currentSession = [
    'class'        => 'Mat Pilates',
    'start'        => '10.00',
    'end'          => '15.00',
    'status'       => 'On Going',
    'room'         => 'Room A — Main Studio',
    'capacity'     => 10,
    'participants' => [
        ['name' => 'Aretha Jeka',   'medical_note' => '-'],
        ['name' => 'Rosie Pearson', 'medical_note' => 'Knee hurts'],
        ['name' => 'Maya Lestari',  'medical_note' => '-'],
    ],
];

$upcomingSchedules = [
    [
        'id' => 101,
        'date' => '2026-09-18',
        'start' => '14.00',
        'end' => '15.00',
        'class' => 'Reformer Pilates',
        'capacity' => 4,
        'participants' => [
            ['name' => 'Aretha Jeka',      'medical_note' => '-'],
            ['name' => 'Rosie Pearson',    'medical_note' => 'Knee hurts'],
            ['name' => 'Darrell Caldwell', 'medical_note' => '-'],
            ['name' => 'Neona Aisnova',    'medical_note' => 'Back Pain & Asthma'],
        ],
    ],
    [
        'id' => 102,
        'date' => '2026-09-17',
        'start' => '08.00',
        'end' => '09.00',
        'class' => 'Morning Pilates',
        'capacity' => 8,
        'participants' => [
            ['name' => 'Alan Cain',       'medical_note' => 'ACL'],
            ['name' => 'Alfred Murray',   'medical_note' => '-'],
            ['name' => 'Citra Anindya',   'medical_note' => 'Lower back stiffness'],
            ['name' => 'Bima Prasetyo',   'medical_note' => '-'],
        ],
    ],
    [
        'id' => 103,
        'date' => '2026-09-19',
        'start' => '09.00',
        'end' => '10.00',
        'class' => 'Yoga',
        'capacity' => 10,
        'participants' => [
            ['name' => 'Dimas Wicaksono', 'medical_note' => 'Shoulder injury'],
            ['name' => 'Salsabila Putri', 'medical_note' => '-'],
        ],
    ],
    [
        'id' => 104,
        'date' => '2026-09-20',
        'start' => '08.00',
        'end' => '09.00',
        'class' => 'Mat Pilates',
        'capacity' => 4,
        'participants' => [
            ['name' => 'Aretha Jeka',   'medical_note' => '-'],
            ['name' => 'Maya Lestari',  'medical_note' => '-'],
            ['name' => 'Rosie Pearson', 'medical_note' => 'Knee hurts'],
        ],
    ],
];

$dayOptions = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$classOptions = ['Reformer Pilates', 'Morning Pilates', 'Mat Pilates', 'Yoga'];


function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

function formatTimeRange(string $start, string $end, bool $withZone = true): string
{
    return $start . ' - ' . $end . ($withZone ? ' WIB' : '');
}
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="stylesheet" href="./assets/css/instructor.css">

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
                        $count      = count($s['participants']);
                        $capacity   = (int) $s['capacity'];
                        $isFull     = $count >= $capacity;
                        $dateLabel  = formatSessionDate($s['date']);
                        $timeLabel  = formatTimeRange($s['start'], $s['end']);
                        $dayKey     = strtolower(date('l', strtotime($s['date'])));

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
                                        <i class="ph-fill ph-eye" style="font-size: 26px;"></i> </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr class="schedule-empty" id="scheduleEmpty" hidden>
                        <td colspan="4">No sessions match your filter.</td>
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

<script src="./assets/js/instructor.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>