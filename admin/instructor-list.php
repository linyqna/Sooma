<?php
declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['user_id'])
    || strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'admin'
) {
    header('Location: ../login-regist/auth/login.php');
    exit;
}

require_once __DIR__ . '/../login-regist/connection.php';

$pageTitle  = 'Instructor List';
$bodyClass  = 'admin-dashboard-page instructor-list-page';
$headerType = 'admin';
$headerLogo = 'black';
$basePath   = '../assets/';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

function il_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const IL_MONTHS_ID = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

function il_initial(string $name): string
{
    $first = mb_strtoupper(mb_substr(trim($name), 0, 1));
    return $first !== '' ? $first : '?';
}

function il_render_options(array $options, ?string $selected = null): void
{
    foreach ($options as $value => $label) {
        $value = is_int($value) ? $label : $value;
        printf(
            '<option value="%s"%s>%s</option>',
            il_esc((string) $value),
            ((string) $value === (string) $selected) ? ' selected' : '',
            il_esc((string) $label)
        );
    }
}

function il_render_card(array $i, string $basePath): void
{
    $photoFile = trim((string) ($i['photo'] ?? ''));
    $photoUrl  = $photoFile !== '' ? './assets/images/' . ltrim($photoFile, '/') : '';
    $name      = (string) ($i['name'] ?? '');
    ?>
    <li class="instructor-list__item">
        <button type="button" class="instructor-card" aria-haspopup="dialog"
            aria-label="Coach <?= il_esc($name) ?>, <?= il_esc($i['title'] ?? '') ?>. Open profile"
            data-id="<?= il_esc($i['id'] ?? '') ?>"
            data-name="<?= il_esc($name) ?>"
            data-title="<?= il_esc($i['title'] ?? '') ?>"
            data-speciality="<?= il_esc($i['speciality'] ?? '') ?>"
            data-joined="<?= il_esc($i['joined'] ?? '') ?>"
            data-whatsapp="<?= il_esc($i['whatsapp'] ?? '') ?>"
            data-email="<?= il_esc($i['email'] ?? '') ?>"
            data-password="<?= il_esc($i['password'] ?? '') ?>"
            data-sessions="<?= il_esc((string) ($i['sessions'] ?? 0)) ?>"
            data-color-name="<?= il_esc($i['color_name'] ?? '') ?>"
            data-color-hex="<?= il_esc($i['color_hex'] ?? '#f4d66d') ?>"
            data-status="<?= il_esc($i['status'] ?? 'active-part') ?>"
            data-photo="<?= il_esc($photoUrl) ?>">
            <figure class="instructor-photo instructor-card__photo" data-photo-frame data-initial="<?= il_esc(il_initial($name)) ?>">
                <img<?= $photoUrl !== '' ? ' src="' . il_esc($photoUrl) . '"' : '' ?> alt="" loading="lazy" draggable="false">
            </figure>
            <span class="instructor-card__name">Coach <?= il_esc($name) ?></span>
            <span class="instructor-card__title"><?= il_esc($i['title'] ?? '') ?></span>
        </button>
    </li>
    <?php
}

$titles = ['Yoga Teacher', 'Pilates Teacher'];

$statuses = [
    'active-full' => 'Active (Full-time)',
    'active-part' => 'Active (Part-time)',
    'inactive'    => 'Inactive',
];

$instructors = [];
$loadError   = false;

try {
    $sql = "
        SELECT
            i.coach_code                                AS id,
            u.name                                      AS name,
            COALESCE(i.title, '')                       AS title,
            COALESCE(i.specialization, '')              AS speciality,
            TO_CHAR(i.joined_date, 'YYYY-MM-DD')        AS joined,
            COALESCE(u.phone_number, '')                AS whatsapp,
            u.email                                     AS email,
            COALESCE(sc.session_count, 0)               AS sessions,
            COALESCE(i.color_name, '')                  AS color_name,
            COALESCE(i.color_hex, '#F4D66D')            AS color_hex,
            COALESCE(i.status, 'active-part')           AS status,
            COALESCE(i.photo, '')                       AS photo
        FROM sooma_wellness.instructors i
        JOIN sooma_wellness.users u
            ON i.user_id = u.user_id
        LEFT JOIN (
            SELECT cs.instructor_id, COUNT(*) AS session_count
            FROM sooma_wellness.class_schedules cs
            WHERE EXTRACT(MONTH FROM cs.schedule_date) = EXTRACT(MONTH FROM CURRENT_DATE)
              AND EXTRACT(YEAR  FROM cs.schedule_date) = EXTRACT(YEAR  FROM CURRENT_DATE)
            GROUP BY cs.instructor_id
        ) sc ON sc.instructor_id = i.instructor_id
        WHERE i.coach_code IS NOT NULL
        ORDER BY LOWER(u.name) ASC, i.instructor_id ASC
    ";

    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $instructors[] = [
            'id'         => (string) $row['id'],
            'name'       => (string) $row['name'],
            'title'      => (string) $row['title'],
            'speciality' => (string) $row['speciality'],
            'joined'     => (string) $row['joined'],
            'whatsapp'   => (string) $row['whatsapp'],
            'email'      => (string) $row['email'],
            'password'   => '',
            'sessions'   => (int) $row['sessions'],
            'color_name' => (string) $row['color_name'],
            'color_hex'  => (string) $row['color_hex'],
            'status'     => (string) $row['status'],
            'photo'      => (string) $row['photo'],
        ];
    }
} catch (PDOException $e) {
    error_log('Instructor list query failed: ' . $e->getMessage());
    $loadError = true;
}

$pageData = [
    'csrf'         => $csrfToken,
    'statusLabels' => $statuses,
];

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Nunito+Sans:wght@600&family=Playfair+Display:wght@400;700&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="./assets/css/admin-dashboard.css">
<link rel="stylesheet" href="./assets/css/instructor-list.css">

<main class="dashboard-page instructor-page" data-save-endpoint="instructor-action.php" data-delete-endpoint="instructor-action.php">

    <h1 class="dashboard-title">Instructor List</h1>
    <p class="instructor-subtitle">Display and manage all data related to instructors affiliated with Sooma Wellness</p>

    <h2 class="section-title">Filter &amp; Search Bar</h2>

    <div class="filter-bar" role="search">
        <label class="filter-control filter-control--search">
            <span class="filter-control__emoji" aria-hidden="true">🔍</span>
            <input type="search" id="filterSearch" placeholder="Search name, id ..." autocomplete="off"
                aria-label="Search by instructor name or ID">
        </label>

        <label class="filter-control filter-control--status">
            <select id="filterTitle" aria-label="Filter by title">
                <option value="">Title</option>
                <?php il_render_options($titles); ?>
            </select>
        </label>
    </div>

    <p class="visually-hidden" id="resultCount" role="status" aria-live="polite"></p>

    <div class="instructor-board">
        <button type="button" class="instructor-add-btn" id="openAdd" aria-haspopup="dialog" aria-label="Add instructor">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v16M4 12h16"/></svg>
        </button>

        <div class="instructor-scroller" tabindex="0" role="region" aria-label="Instructor cards">
            <ul class="instructor-list" id="instructorList">
                <?php foreach ($instructors as $instructor) { il_render_card($instructor, $basePath); } ?>
            </ul>
            <?php if ($loadError): ?>
                <p class="instructor-empty" role="alert">Unable to load instructors right now. Please refresh the page or try again later.</p>
            <?php endif; ?>
            <p class="instructor-empty" id="emptyState" hidden>No instructors match your search. Try another name, ID, or title.</p>
        </div>
    </div>
</main>

<div class="dash-modal-overlay" id="detailModal" aria-hidden="true">
    <section class="instructor-modal instructor-modal--detail" role="dialog" aria-modal="true" aria-labelledby="detailName" tabindex="-1">
        <figure class="instructor-photo instructor-modal__photo" id="detailPhoto" data-photo-frame data-initial="?">
            <img alt="" draggable="false">
            <label class="detail-photo__trigger" for="detailPhotoInput" aria-label="Upload or change instructor photo">
                <span class="detail-photo__badge">
                    <i class="ph ph-camera" aria-hidden="true"></i>
                    <span id="detailPhotoLabel">Upload Photo</span>
                </span>
            </label>
            <input class="visually-hidden" type="file" id="detailPhotoInput" name="photo" form="detailForm" accept="image/jpeg,image/png,image/webp">
        </figure>

        <form class="instructor-modal__content" id="detailForm" method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="coach_id" value="">
            <input type="hidden" name="joined" value="">

            <div class="detail-toolbar">
                <button type="button" class="link-btn" id="editToggle" aria-pressed="false">
                    <i class="ph-fill ph-pencil-simple" aria-hidden="true"></i>
                    <span id="editToggleLabel">Edit Profile</span>
                </button>
            </div>

            <header class="detail-heading">
                <div class="coach-name-row">
                    <h2 class="coach-name" id="detailName"><strong>Coach</strong> <span class="view-only" data-view="name"></span></h2>
                    <input class="field-input edit-only" type="text" name="name" required maxlength="60" aria-label="Instructor name" placeholder="Name" autocomplete="off">
                </div>
                <div class="detail-meta">
                    <p class="coach-title view-only" data-view="title"></p>
                    <select class="field-input edit-only" name="title" required aria-label="Title">
                        <?php il_render_options($titles); ?>
                    </select>
                    <input class="field-input edit-only" type="text" name="speciality" required maxlength="80" aria-label="Speciality" placeholder="Speciality" autocomplete="off">
                </div>
            </header>

            <hr class="detail-divider">

            <dl class="profile-grid">
                <div class="profile-item">
                    <dt>Coach ID</dt>
                    <dd data-view="id"></dd>
                </div>
                <div class="profile-item">
                    <dt>Joined</dt>
                    <dd data-view="joined"></dd>
                </div>

                <div class="profile-item">
                    <dt>WhatsApp</dt>
                    <dd>
                        <span class="view-only" data-view="whatsapp"></span>
                        <input class="field-input edit-only" type="tel" name="whatsapp" required inputmode="tel" pattern="^(\+62|62|0)[0-9]{8,13}$" title="Use an Indonesian number, e.g. 081234567890" aria-label="WhatsApp" autocomplete="off">
                    </dd>
                </div>
                <div class="profile-item">
                    <dt>Session Count <small>/month</small></dt>
                    <dd data-view="sessions"></dd>
                </div>

                <div class="profile-item">
                    <dt>Email</dt>
                    <dd>
                        <span class="view-only" data-view="email"></span>
                        <input class="field-input edit-only" type="email" name="email" required aria-label="Email" autocomplete="off">
                    </dd>
                </div>
                <div class="profile-item">
                    <dt>Color</dt>
                    <dd>
                        <span class="view-only color-view">
                            <span data-view="colorName"></span>
                            <span class="color-swatch" id="detailSwatch" aria-hidden="true"></span>
                        </span>
                        <span class="edit-only color-field">
                            <input class="field-input" type="text" name="color_name" required maxlength="24" aria-label="Color name" autocomplete="off">
                            <input class="color-picker" type="color" name="color_hex" aria-label="Pick color">
                        </span>
                    </dd>
                </div>

                <div class="profile-item">
                    <dt>Password</dt>
                    <dd class="pw-row">
                        <span class="view-only" data-view="password"></span>
                        <input class="field-input edit-only" type="password" name="password" minlength="6" aria-label="Password" placeholder="Leave blank to keep current" autocomplete="new-password">
                        <button type="button" class="icon-btn" id="togglePassword" aria-pressed="false" aria-label="Show password">
                            <i class="ph ph-eye" id="togglePasswordIcon" aria-hidden="true"></i>
                        </button>
                    </dd>
                </div>
                <div class="profile-item">
                    <dt>Schedule</dt>
                    <dd>
                        <a class="link-muted" id="scheduleLink" href="schedule-class.php">
                            view schedule
                            <i class="ph ph-eye" aria-hidden="true"></i>
                        </a>
                    </dd>
                </div>

                <div class="profile-item">
                    <dt>Status</dt>
                    <dd>
                        <span class="view-only" data-view="status"></span>
                        <select class="field-input edit-only" name="status" required aria-label="Status">
                            <?php il_render_options($statuses); ?>
                        </select>
                    </dd>
                </div>
            </dl>

            <div class="instructor-modal__actions">
                <button type="button" class="btn-delete" id="detailDelete">Delete</button>
                <button type="submit" class="btn-save" id="detailSave" disabled title="Click Edit Profile to make changes">Save</button>
            </div>
        </form>
    </section>
</div>

<div class="dash-modal-overlay" id="addModal" aria-hidden="true">
    <section class="instructor-modal instructor-modal--add" role="dialog" aria-modal="true" aria-labelledby="addTitle" tabindex="-1">
        <form class="instructor-add-layout" id="addForm" method="post" enctype="multipart/form-data">
            <div class="instructor-add-layout__left">
                <h2 class="instructor-add-title" id="addTitle">Add Instructor</h2>
                <label class="upload-box" id="uploadBox" for="addPhoto">
                    <img class="upload-box__preview" id="uploadPreview" alt="Photo preview" hidden>
                    <span class="upload-box__hint">
                        <i class="ph ph-upload-simple" aria-hidden="true"></i>
                        <span>Upload Photo</span>
                    </span>
                </label>
                <input class="visually-hidden" type="file" id="addPhoto" name="photo" accept="image/jpeg,image/png,image/webp">
            </div>

            <div class="instructor-add-layout__right">
                <div class="meta-row">
                    <div>
                        <p class="meta-label">Coach ID</p>
                        <p class="meta-value" id="addCoachId">#</p>
                        <input type="hidden" name="coach_id" id="addCoachIdInput" value="">
                    </div>
                    <div>
                        <p class="meta-label">Joined</p>
                        <p class="meta-value" id="addJoined"></p>
                        <input type="hidden" name="joined" id="addJoinedInput" value="">
                    </div>
                </div>

                <div class="form-field">
                    <label for="addName">Name</label>
                    <input class="field-input" id="addName" type="text" name="name" placeholder="Enter name" required maxlength="60" autocomplete="off">
                </div>
                <div class="form-field">
                    <label for="addTitleSelect">Title</label>
                    <select class="field-input" id="addTitleSelect" name="title" required>
                        <option value="" disabled selected>Title</option>
                        <?php il_render_options($titles); ?>
                    </select>
                </div>
                <div class="form-field">
                    <label for="addSpeciality">Speciality</label>
                    <input class="field-input" id="addSpeciality" type="text" name="speciality" placeholder="Enter speciality" required maxlength="80" autocomplete="off">
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label for="addWhatsapp">WhatsApp</label>
                        <input class="field-input" id="addWhatsapp" type="tel" name="whatsapp" placeholder="Enter number" required inputmode="tel" pattern="^(\+62|62|0)[0-9]{8,13}$" title="Use an Indonesian number, e.g. 081234567890" autocomplete="off">
                    </div>
                    <div class="form-field">
                        <label for="addStatus">Status</label>
                        <select class="field-input" id="addStatus" name="status" required>
                            <option value="" disabled selected>Active Status</option>
                            <?php il_render_options($statuses); ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label for="addEmail">Email</label>
                        <input class="field-input" id="addEmail" type="email" name="email" placeholder="Enter email" required autocomplete="off">
                    </div>
                    <div class="form-field">
                        <label for="addColorName">Color</label>
                        <div class="color-field">
                            <input class="field-input" id="addColorName" type="text" name="color_name" placeholder="Enter color name" required maxlength="24" autocomplete="off">
                            <input class="color-picker" id="addColorHex" type="color" name="color_hex" value="#f4d66d" aria-label="Pick color">
                        </div>
                    </div>
                </div>

                <div class="form-row form-row--end">
                    <div class="form-field">
                        <label for="addPassword">Password</label>
                        <input class="field-input" id="addPassword" type="password" name="password" placeholder="Enter password" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-delete" id="addDelete">Delete</button>
                        <button type="submit" class="btn-save" id="addSave">Save</button>
                    </div>
                </div>
            </div>
        </form>
    </section>
</div>

<template id="instructorCardTemplate">
    <?php il_render_card([], $basePath); ?>
</template>

<p class="dash-toast" id="dashToast" role="status" aria-live="polite" hidden></p>

<script type="application/json" id="instructor-data">
<?= json_encode($pageData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
<script src="./assets/js/instructor-list.js"></script>
</body>
</html>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>