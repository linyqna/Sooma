<?php
$pageTitle  = 'Admin Dashboard';
$bodyClass  = 'admin-dashboard-page';
$headerType = 'admin';
$headerLogo = 'black';
$basePath   = '../assets/';

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Nunito+Sans:wght@600&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="./assets/css/admin-dashboard.css">

<main class="dashboard-page" data-verify-endpoint="" data-delete-endpoint="">

    <h1 class="dashboard-title">Dashboard</h1>

    <section class="summary-grid" aria-label="Summary statistics">
        <article class="stat-card">
            <h2 class="stat-card__label">Total User</h2>
            <p class="stat-card__value">1,240</p>
            <p class="stat-card__trend stat-card__trend--up">
                <img src="<?= $basePath ?>icons/dashboard-trend-up.svg" alt="" width="24" height="24">
                <span>
                    <strong>8.5%</strong>
                    <span class="stat-card__note">Up from yesterday</span>
                </span>
            </p>
            <img class="stat-card__icon" src="<?= $basePath ?>icons/dashboard-icon-users.svg" alt="" width="50" height="50">
        </article>

        <article class="stat-card">
            <h2 class="stat-card__label">Active Bookings</h2>
            <p class="stat-card__value">350</p>
            <p class="stat-card__trend stat-card__trend--up">
                <img src="<?= $basePath ?>icons/dashboard-trend-up.svg" alt="" width="24" height="24">
                <span>
                    <strong>1.3%</strong>
                    <span class="stat-card__note">Up from past week</span>
                </span>
            </p>
            <img class="stat-card__icon" src="<?= $basePath ?>icons/dashboard-icon-bookings.svg" alt="" width="50" height="50">
        </article>

        <article class="stat-card">
            <h2 class="stat-card__label">Total Revenue</h2>
            <p class="stat-card__value">Rp 5.000.000</p>
            <p class="stat-card__trend stat-card__trend--up">
                <img src="<?= $basePath ?>icons/dashboard-trend-up.svg" alt="" width="24" height="24">
                <span>
                    <strong>1.8%</strong>
                    <span class="stat-card__note">Up from yesterday</span>
                </span>
            </p>
            <img class="stat-card__icon" src="<?= $basePath ?>icons/dashboard-icon-revenue.svg" alt="" width="50" height="50">
        </article>

        <article class="stat-card">
            <h2 class="stat-card__label">Pending Payment</h2>
            <p class="stat-card__value">12</p>
            <p class="stat-card__trend stat-card__trend--down">
                <img src="<?= $basePath ?>icons/dashboard-trend-down.svg" alt="" width="24" height="24">
                <span>
                    <strong>4.3%</strong>
                    <span class="stat-card__note">Down from yesterday</span>
                </span>
            </p>
            <img class="stat-card__icon" src="<?= $basePath ?>icons/dashboard-icon-pending.svg" alt="" width="50" height="50">
        </article>
    </section>

    <section class="sales-card" aria-labelledby="sales-title">
        <header class="sales-card__header">
            <h2 class="sales-card__title" id="sales-title">Sales Details</h2>

            <label class="visually-hidden" for="salesMonth">Select month</label>
            <select class="month-select" id="salesMonth">
                <option value="October" selected>October</option>
                <option value="September">September</option>
                <option value="August">August</option>
            </select>
        </header>

        <div class="sales-card__chart">
            <canvas id="salesChart" role="img" aria-label="Daily sales for the selected month"></canvas>
            <p class="sales-card__fallback" id="chartFallback" hidden>The chart could not be loaded. Please check your internet connection.</p>
        </div>
    </section>

    <h2 class="section-title">Filter &amp; Search Bar</h2>

    <div class="filter-bar" role="search">
        <label class="filter-control filter-control--search">
            <span class="filter-control__emoji" aria-hidden="true">🔍</span>
            <input type="search" id="filterSearch" placeholder="Search name, id ..." autocomplete="off"
                   aria-label="Search by booking ID or customer name">
        </label>

        <div class="filter-control filter-control--date">
            <span class="filter-control__emoji" aria-hidden="true">📅</span>
            <span class="filter-control__text" id="filterDateText">Date</span>
            <button type="button" class="filter-control__clear" id="filterDateClear" aria-label="Clear date filter" hidden>&times;</button>
            <input type="date" id="filterDate" aria-label="Filter by date">
        </div>

        <label class="filter-control filter-control--status">
            <span class="filter-control__emoji" aria-hidden="true">🏷️</span>
            <select id="filterStatus" aria-label="Filter by payment status">
                <option value="">All Status</option>
                <option value="check">Check</option>
                <option value="pending">Pending</option>
                <option value="confirmed">Confirmed</option>
            </select>
        </label>
    </div>

    <section class="table-card" aria-label="Bookings">
        <div class="table-card__scroll">
            <table class="bookings-table">
                <colgroup>
                    <col style="width:11.97%">
                    <col style="width:14.71%">
                    <col style="width:13.72%">
                    <col style="width:13.57%">
                    <col style="width:20.2%">
                    <col style="width:17.07%">
                    <col style="width:8.76%">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Booking ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Class &amp; Level</th>
                        <th scope="col">Date</th>
                        <th scope="col">Medical Note</th>
                        <th scope="col">Payment Receipt</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody id="bookingsBody">
                    <tr data-id="BK-001" data-name="arestha jakra" data-date="2026-10-30" data-status="check">
                        <td>BK-001</td>
                        <td>Arestha Jakra</td>
                        <td>Yoga<br>Beginner</td>
                        <td>30 Okt 2026<br>13.00</td>
                        <td>-</td>
                        <td>
                            <button type="button" class="status-badge status-badge--check js-open-receipt" data-id="BK-001" aria-haspopup="dialog">Check</button>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn js-view-detail" data-id="BK-001" aria-haspopup="dialog" title="View detail">
                                    <img src="<?= $basePath ?>icons/dashboard-action-view.svg" alt="" width="24" height="24">
                                </button>
                                <a class="icon-btn" href="booking-edit.php?id=BK-001" title="Edit">
                                    <img src="<?= $basePath ?>icons/dashboard-action-edit.svg" alt="" width="23" height="24">
                                </a>
                                <button type="button" class="icon-btn js-delete" data-id="BK-001" title="Delete">
                                    <img src="<?= $basePath ?>icons/dashboard-action-delete.svg" alt="" width="22" height="23">
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr data-id="BK-002" data-name="rosie pearson" data-date="2026-10-30" data-status="pending">
                        <td>BK-002</td>
                        <td>Rosie Pearson</td>
                        <td>Reformer Pilates<br>Beginner</td>
                        <td>30 Okt 2026<br>15.40</td>
                        <td>Knee hurts</td>
                        <td>
                            <button type="button" class="status-badge status-badge--pending js-open-receipt" data-id="BK-002" aria-haspopup="dialog">Pending</button>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn js-view-detail" data-id="BK-002" aria-haspopup="dialog" title="View detail">
                                    <img src="<?= $basePath ?>icons/dashboard-action-view.svg" alt="" width="24" height="24">
                                </button>
                                <a class="icon-btn" href="booking-edit.php?id=BK-002" title="Edit">
                                    <img src="<?= $basePath ?>icons/dashboard-action-edit.svg" alt="" width="23" height="24">
                                </a>
                                <button type="button" class="icon-btn js-delete" data-id="BK-002" title="Delete">
                                    <img src="<?= $basePath ?>icons/dashboard-action-delete.svg" alt="" width="22" height="23">
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr data-id="BK-003" data-name="darrell caldwell" data-date="2026-10-30" data-status="pending">
                        <td>BK-003</td>
                        <td>Darrell Caldwell</td>
                        <td>Reformer Pilates<br>Beginner</td>
                        <td>30 Okt 2026<br>15.40</td>
                        <td>-</td>
                        <td>
                            <button type="button" class="status-badge status-badge--pending js-open-receipt" data-id="BK-003" aria-haspopup="dialog">Pending</button>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn js-view-detail" data-id="BK-003" aria-haspopup="dialog" title="View detail">
                                    <img src="<?= $basePath ?>icons/dashboard-action-view.svg" alt="" width="24" height="24">
                                </button>
                                <a class="icon-btn" href="booking-edit.php?id=BK-003" title="Edit">
                                    <img src="<?= $basePath ?>icons/dashboard-action-edit.svg" alt="" width="23" height="24">
                                </a>
                                <button type="button" class="icon-btn js-delete" data-id="BK-003" title="Delete">
                                    <img src="<?= $basePath ?>icons/dashboard-action-delete.svg" alt="" width="22" height="23">
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr data-id="BK-004" data-name="neona aisnova" data-date="2026-10-29" data-status="confirmed">
                        <td>BK-004</td>
                        <td>Neona Aisnova</td>
                        <td>Yoga<br>Beginner</td>
                        <td>29 Okt 2026<br>11.00</td>
                        <td>Back Pain &amp; Asthma</td>
                        <td>
                            <button type="button" class="status-badge status-badge--confirmed js-open-receipt" data-id="BK-004" aria-haspopup="dialog">Confirmed</button>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn js-view-detail" data-id="BK-004" aria-haspopup="dialog" title="View detail">
                                    <img src="<?= $basePath ?>icons/dashboard-action-view.svg" alt="" width="24" height="24">
                                </button>
                                <a class="icon-btn" href="booking-edit.php?id=BK-004" title="Edit">
                                    <img src="<?= $basePath ?>icons/dashboard-action-edit.svg" alt="" width="23" height="24">
                                </a>
                                <button type="button" class="icon-btn js-delete" data-id="BK-004" title="Delete">
                                    <img src="<?= $basePath ?>icons/dashboard-action-delete.svg" alt="" width="22" height="23">
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr data-id="BK-005" data-name="alan cain" data-date="2026-10-29" data-status="confirmed">
                        <td>BK-005</td>
                        <td>Alan Cain</td>
                        <td>Reformer Pilates<br>Beginner</td>
                        <td>29 Okt 2026<br>16.00</td>
                        <td>ACL</td>
                        <td>
                            <button type="button" class="status-badge status-badge--confirmed js-open-receipt" data-id="BK-005" aria-haspopup="dialog">Confirmed</button>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn js-view-detail" data-id="BK-005" aria-haspopup="dialog" title="View detail">
                                    <img src="<?= $basePath ?>icons/dashboard-action-view.svg" alt="" width="24" height="24">
                                </button>
                                <a class="icon-btn" href="booking-edit.php?id=BK-005" title="Edit">
                                    <img src="<?= $basePath ?>icons/dashboard-action-edit.svg" alt="" width="23" height="24">
                                </a>
                                <button type="button" class="icon-btn js-delete" data-id="BK-005" title="Delete">
                                    <img src="<?= $basePath ?>icons/dashboard-action-delete.svg" alt="" width="22" height="23">
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr data-id="BK-006" data-name="alfred murray" data-date="2026-10-29" data-status="confirmed">
                        <td>BK-006</td>
                        <td>Alfred Murray</td>
                        <td>Reformer Pilates<br>Beginner</td>
                        <td>29 Okt 2026<br>18.00</td>
                        <td>-</td>
                        <td>
                            <button type="button" class="status-badge status-badge--confirmed js-open-receipt" data-id="BK-006" aria-haspopup="dialog">Confirmed</button>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn js-view-detail" data-id="BK-006" aria-haspopup="dialog" title="View detail">
                                    <img src="<?= $basePath ?>icons/dashboard-action-view.svg" alt="" width="24" height="24">
                                </button>
                                <a class="icon-btn" href="booking-edit.php?id=BK-006" title="Edit">
                                    <img src="<?= $basePath ?>icons/dashboard-action-edit.svg" alt="" width="23" height="24">
                                </a>
                                <button type="button" class="icon-btn js-delete" data-id="BK-006" title="Delete">
                                    <img src="<?= $basePath ?>icons/dashboard-action-delete.svg" alt="" width="22" height="23">
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr class="bookings-empty" id="bookingsEmpty" hidden>
                        <td colspan="7">No bookings found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="table-pagination" id="pagination" aria-label="Bookings pagination">
            <button type="button" class="page-arrow" id="pagePrev" aria-label="Previous page">
                <img src="<?= $basePath ?>icons/dashboard-page-prev.svg" alt="" width="16" height="16">
            </button>
            <span class="page-numbers" id="pageNumbers"></span>
            <button type="button" class="page-arrow" id="pageNext" aria-label="Next page">
                <img src="<?= $basePath ?>icons/dashboard-page-next.svg" alt="" width="16" height="16">
            </button>
        </nav>
    </section>
</main>

<div class="dash-modal-overlay" id="receiptModal" aria-hidden="true">
    <div class="dash-modal" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle" tabindex="-1">
        <header class="dash-modal__header">
            <div>
                <p class="dash-modal__eyebrow">Verify Payment Receipt</p>
                <h2 class="dash-modal__title" id="receiptModalTitle" data-field="display_id"></h2>
            </div>
            <button type="button" class="dash-modal__close" aria-label="Close" data-close>&times;</button>
        </header>

        <div class="dash-modal__body">
            <dl class="info-grid info-grid--compact">
                <div><dt>Booking ID</dt><dd data-field="display_id"></dd></div>
                <div><dt>Customer</dt><dd data-field="name"></dd></div>
                <div><dt>Total Payment</dt><dd class="info-grid__amount" data-field="amount"></dd></div>
            </dl>

            <figure class="receipt-figure">
                <img id="receiptImage" src="" alt="Payment receipt" hidden>
                <p class="receipt-figure__empty" id="receiptEmpty" hidden>No payment receipt has been uploaded yet.</p>
            </figure>
        </div>

        <footer class="dash-modal__footer">
            <p class="dash-modal__note" id="receiptNote" hidden></p>
            <button type="button" class="btn-secondary" data-close>Close</button>
            <button type="button" class="btn-checked" id="btnChecked">Checked</button>
        </footer>
    </div>
</div>

<div class="dash-modal-overlay" id="detailModal" aria-hidden="true">
    <div class="dash-modal" role="dialog" aria-modal="true" aria-labelledby="detailModalTitle" tabindex="-1">
        <header class="dash-modal__header">
            <div>
                <p class="dash-modal__eyebrow">Booking Detail</p>
                <h2 class="dash-modal__title" id="detailModalTitle" data-field="display_id"></h2>
            </div>
            <button type="button" class="dash-modal__close" aria-label="Close" data-close>&times;</button>
        </header>

        <div class="dash-modal__body">
            <dl class="info-grid">
                <div><dt>Booking ID</dt><dd data-field="display_id"></dd></div>
                <div><dt>Customer Name</dt><dd data-field="name"></dd></div>
                <div><dt>Class &amp; Level</dt><dd data-field="class_level"></dd></div>
                <div><dt>Schedule</dt><dd data-field="schedule"></dd></div>
                <div><dt>Medical Note</dt><dd data-field="medical"></dd></div>
                <div><dt>Coach</dt><dd data-field="coach"></dd></div>
                <div>
                    <dt>Payment Status</dt>
                    <dd><span class="status-badge status-badge--static" id="detailStatus"></span></dd>
                </div>
            </dl>
        </div>

        <footer class="dash-modal__footer">
            <button type="button" class="btn-secondary" data-close>Close</button>
        </footer>
    </div>
</div>

<p class="dash-toast" id="dashToast" role="status" aria-live="polite" hidden></p>

<script type="application/json" id="dashboard-data">
{
  "perPage": 6,
  "statusLabels": { "check": "Check", "pending": "Pending", "confirmed": "Confirmed" },
  "csrf": "demo-token",
  "bookings": {
    "BK-001": { "id": "BK-001", "display_id": "#BK-001", "name": "Arestha Jakra", "class_level": "Yoga — Beginner", "schedule": "30 Okt 2026, 13.00 WIB", "medical": "-", "coach": "Coach Grace", "status": "check", "receipt": "../assets/images/receipts/sample-receipt.png", "amount": "Rp 250.000" },
    "BK-002": { "id": "BK-002", "display_id": "#BK-002", "name": "Rosie Pearson", "class_level": "Reformer Pilates — Beginner", "schedule": "30 Okt 2026, 15.40 WIB", "medical": "Knee hurts", "coach": "Coach Bintari", "status": "pending", "receipt": "../assets/images/receipts/sample-receipt.png", "amount": "Rp 250.000" },
    "BK-003": { "id": "BK-003", "display_id": "#BK-003", "name": "Darrell Caldwell", "class_level": "Reformer Pilates — Beginner", "schedule": "30 Okt 2026, 15.40 WIB", "medical": "-", "coach": "Coach Bintari", "status": "pending", "receipt": "", "amount": "Rp 250.000" },
    "BK-004": { "id": "BK-004", "display_id": "#BK-004", "name": "Neona Aisnova", "class_level": "Yoga — Beginner", "schedule": "29 Okt 2026, 11.00 WIB", "medical": "Back Pain & Asthma", "coach": "Coach Alifa", "status": "confirmed", "receipt": "../assets/images/receipts/sample-receipt.png", "amount": "Rp 250.000" },
    "BK-005": { "id": "BK-005", "display_id": "#BK-005", "name": "Alan Cain", "class_level": "Reformer Pilates — Beginner", "schedule": "29 Okt 2026, 16.00 WIB", "medical": "ACL", "coach": "Coach Grace", "status": "confirmed", "receipt": "../assets/images/receipts/sample-receipt.png", "amount": "Rp 250.000" },
    "BK-006": { "id": "BK-006", "display_id": "#BK-006", "name": "Alfred Murray", "class_level": "Reformer Pilates — Beginner", "schedule": "29 Okt 2026, 18.00 WIB", "medical": "-", "coach": "Coach Grace", "status": "confirmed", "receipt": "../assets/images/receipts/sample-receipt.png", "amount": "Rp 250.000" }
  },
  "chart": {
    "default": "October",
    "months": {
      "October": { "days": 10, "values": [1200000, 1500000, 1100000, 1850000, 2100000, 1950000, 2400000, 2200000, 2800000, 3100000] },
      "September": { "days": 10, "values": [900000, 1100000, 1400000, 1300000, 1700000, 1600000, 1900000, 2100000, 2000000, 2300000] },
      "August": { "days": 10, "values": [800000, 950000, 1200000, 1150000, 1450000, 1600000, 1500000, 1850000, 1900000, 2100000] }
    }
  }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="./assets/js/dashboard.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>