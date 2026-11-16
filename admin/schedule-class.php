<?php
session_start();

$headerType = 'admin';
$pageTitle = 'Schedule & Class';
$bodyClass = 'admin-page';

$pageCss = 'schedule-class.css';
$pageCssBase = 'assets/';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../login-regist/connection.php';

$pdo->exec("SET search_path TO sooma_wellness");

$masterClasses = $pdo->query("SELECT class_id, class_name, price FROM master_classes ORDER BY class_id")->fetchAll(PDO::FETCH_ASSOC);
$instructorsList = $pdo->query("
    SELECT i.instructor_id, u.name 
    FROM instructors i 
    JOIN users u ON i.user_id = u.user_id 
    ORDER BY u.name
")->fetchAll(PDO::FETCH_ASSOC);

$coachColors = [
    'Bintari' => '#A3B2A1',
    'Grace'   => '#FF9F50',
    'Alifa'   => '#F4D66D',
    'Bunga'   => '#A98BC4',
    'Ulung'   => '#EFA2A8',
    'Vanes'   => '#65AFA5'
];
?>
<main class="admin-main">
    <section class="schedule-heading">
        <div>
            <h1>Schedule &amp; Class</h1>

            <div class="week-controls">
                <button
                    class="round-button"
                    id="previousWeek"
                    aria-label="Previous week"
                    type="button">
                    &lt;
                </button>

                <strong id="weekLabel"></strong>

                <button
                    class="round-button"
                    id="nextWeek"
                    aria-label="Next week"
                    type="button">
                    &gt;
                </button>
            </div>
        </div>
    </section>

    <section class="calendar-shell" aria-label="Weekly schedule calendar">
        <div class="time-column">
            <div class="time-column-spacer"></div>
            <div class="time-labels" id="timeLabels"></div>
        </div>

        <div class="calendar-scroll">
            <div class="calendar-grid">
                <div class="calendar-days" id="calendarDays"></div>
                <div class="calendar-body" id="calendarBody"></div>
            </div>
        </div>
    </section>
</main>

<footer class="admin-footer">
    <div class="admin-footer-main">
        <div class="footer-brand-section">
            <a class="footer-logo" href="../index.php">
                <img
                    src="../assets/images/sooma-logo-black.png"
                    alt="Sooma Wellness">
            </a>

            <div class="footer-info">
                <h3>Location</h3>
                <p>Jl. Ciliwung no.62, Blitar</p>

                <h3>Operating Hours</h3>
                <p>
                    Tue &amp; Fri: 16:30 WIB<br>
                    Wed: 08:00 &amp; 16:30 WIB<br>
                    Sat: 16:00 WIB | Sun: 08:00 WIB
                </p>
            </div>
        </div>

        <div class="footer-quick-links">
            <h3>Quick Link</h3>
            <a href="../index.php">Home</a>
            <a href="schedule-class.php">Schedule &amp; Class</a>
            <a href="booking.php">Booking</a>
            <a href="instructors.php">Instructors</a>
        </div>
    </div>

    <div class="admin-footer-bottom">
        <span>© 2026 Sooma Wellness. All rights reserved.</span>

        <div class="footer-legal-links">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Cookie Settings</a>
        </div>
    </div>
</footer>

<div class="modal-backdrop" id="scheduleModal" hidden>
    <section
        class="schedule-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="modalTitle">

        <button
            class="modal-close"
            id="closeScheduleModal"
            type="button"
            aria-label="Close">
            &times;
        </button>

        <h2 id="modalTitle">Add New Schedule</h2>
        <p class="modal-date" id="modalDate"></p>

        <form
            id="scheduleForm"
            action="booking-schedule.php"
            method="POST">

            <input
                type="hidden"
                name="scheduleDate"
                id="scheduleDate">

            <input type="hidden" name="scheduleId" id="scheduleId">

            <label class="form-field">
                <span>Class Name</span>
                <select name="classId" id="classSelect" required>
                    <?php foreach ($masterClasses as $mc): ?>
                        <option value="<?= (int)$mc['class_id'] ?>" data-price="<?= (int)$mc['price'] ?>">
                            <?= htmlspecialchars($mc['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="form-field">
                <span>Class Type</span>
                <select name="classType" required>
                    <option value="Group">Group</option>
                    <option value="Private">Private</option>
                </select>
            </label>

            <label class="form-field">
                <span>Instructor</span>
                <select name="instructorId" required>
                    <?php foreach ($instructorsList as $inst): ?>
                        <option value="<?= (int)$inst['instructor_id'] ?>">
                            Coach <?= htmlspecialchars($inst['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="form-field">
                <span>Member Slot</span>
                <input
                    name="memberSlot"
                    type="number"
                    min="1"
                    max="30"
                    value="4"
                    required>
            </label>

            <div class="form-field-row">
                <label class="form-field">
                    <span>Start Time</span>
                    <input
                        name="startTime"
                        type="time"
                        value="10:00"
                        required>
                </label>

                <label class="form-field">
                    <span>End Time</span>
                    <input
                        name="endTime"
                        type="time"
                        value="11:00"
                        required>
                </label>
            </div>

            <label class="form-field">
                <span>Level</span>
                <select name="level" required>
                    <option value="Beginner">Beginner</option>
                    <option value="Intermediate">Intermediate</option>
                    <option value="Advanced">Advanced</option>
                </select>
            </label>

            <label class="form-field">
                <span>Price (Rp.)</span>
                <input
                    name="price"
                    id="classPriceInput"
                    type="number"
                    min="0"
                    step="1000"
                    value="<?= !empty($masterClasses) ? (int)$masterClasses[0]['price'] : 100000 ?>"
                    readonly>
            </label>

            <p class="form-error" id="formError" role="alert" hidden></p>

            <div class="modal-actions">
                <button
                    class="cancel-button"
                    id="cancelSchedule"
                    type="button">
                    Cancel
                </button>

                <button class="save-button" type="submit" id="saveSchedule">
                    Save
                </button>
            </div>
        </form>
    </section>
</div>
<div class="schedule-toast" id="scheduleToast" role="status" aria-live="polite"></div>

<script src="assets/js/schedule-class.js"></script>
</body>

</html>