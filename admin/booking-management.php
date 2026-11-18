<?php
$pageTitle  = 'Booking Management';
$bodyClass  = 'admin-booking-page';
$headerType = 'admin';
$headerLogo = 'black';
$basePath   = '../assets/';

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Nunito+Sans:wght@600&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="./assets/css/booking-management.css">

<main class="dashboard-page" data-verify-endpoint="" data-delete-endpoint="">

    <header class="page-header">
        <h1 class="dashboard-title">Booking Management</h1>
        <p class="dashboard-subtitle">Manage and track all studio reservations and attendee logs</p>
    </header>

    <section class="summary-grid" aria-label="Booking statistics">
        <article class="stat-card">
            <h2 class="stat-card__label">Total Bookings</h2>
            <p class="stat-card__value">118</p>
            <p class="stat-card__trend stat-card__trend--up">
                <i class="ph ph-trend-up" style="font-size: 24px;"></i>
                <span>
                    <strong>1.6%</strong>
                    <span class="stat-card__note">Up from past week</span>
                </span>
            </p>
            <div class="stat-card__icon stat-card__icon--bookings">
                <i class="ph-fill ph-package" style="font-size: 26px;"></i>
            </div>
        </article>

        <article class="stat-card">
            <h2 class="stat-card__label">Pending</h2>
            <p class="stat-card__value">12</p>
            <p class="stat-card__trend stat-card__trend--down">
                <i class="ph ph-trend-down" style="font-size: 24px;"></i>
                <span>
                    <strong>4.3%</strong>
                    <span class="stat-card__note">Down from past week</span>
                </span>
            </p>
            <div class="stat-card__icon stat-card__icon--pending">
                <i class="ph-fill ph-clock-counter-clockwise" style="font-size: 26px;"></i>
            </div>
        </article>

        <article class="stat-card">
            <h2 class="stat-card__label">Confirmed</h2>
            <p class="stat-card__value">106</p>
            <p class="stat-card__trend stat-card__trend--up">
                <i class="ph ph-trend-up" style="font-size: 24px;"></i>
                <span>
                    <strong>1.8%</strong>
                    <span class="stat-card__note">Up from past week</span>
                </span>
            </p>
            <div class="stat-card__icon stat-card__icon--revenue">
                <i class="ph-fill ph-chart-line-up" style="font-size: 26px;"></i>
            </div>
        </article>

        <article class="stat-card">
            <h2 class="stat-card__label">Completed</h2>
            <p class="stat-card__value">118</p>
            <p class="stat-card__trend stat-card__trend--up">
                <i class="ph ph-trend-up" style="font-size: 24px;"></i>
                <span>
                    <strong>1.8%</strong>
                    <span class="stat-card__note">Up from past week</span>
                </span>
            </p>
            <div class="stat-card__icon stat-card__icon--completed">
                <i class="ph-fill ph-check" style="font-size: 26px;"></i>
            </div>
        </article>
    </section>

    <h2 class="section-title">Filter &amp; Search Bar</h2>

    <div class="filter-bar" role="search">
        <label class="filter-control filter-control--search">
            <i class="ph ph-magnifying-glass filter-control__icon" aria-hidden="true"></i>
            <input type="search" id="filterSearch" placeholder="Search name, id ..." autocomplete="off"
                aria-label="Search by booking ID or customer name">
        </label>

        <div class="filter-control filter-control--date">
            <i class="ph ph-calendar-blank filter-control__icon" aria-hidden="true"></i>
            <span class="filter-control__text" id="filterDateText">Date</span>
            <button type="button" class="filter-control__clear" id="filterDateClear" aria-label="Clear date filter" hidden>&times;</button>
            <input type="date" id="filterDate" aria-label="Filter by date">
        </div>

        <label class="filter-control filter-control--status">
            <i class="ph ph-tag filter-control__icon" aria-hidden="true"></i>
            <select id="filterStatus" aria-label="Filter by payment status">
                <option value="">Status</option>
                <option value="check">Check</option>
                <option value="pending">Pending</option>
                <option value="confirmed">Confirmed</option>
                <option value="rejected">Rejected</option>
            </select>
            <i class="ph ph-caret-down filter-control__chevron" aria-hidden="true"></i>
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
                                    <i class="ph-fill ph-eye" style="font-size: 24px;"></i>
                                </button>
                                <button type="button" class="icon-btn js-delete" data-id="BK-001" title="Delete">
                                    <i class="ph-fill ph-trash" style="font-size: 23px;"></i>
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
                                    <i class="ph-fill ph-eye" style="font-size: 24px;"></i>
                                </button>
                                <button type="button" class="icon-btn js-delete" data-id="BK-002" title="Delete">
                                    <i class="ph-fill ph-trash" style="font-size: 23px;"></i>
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
                                    <i class="ph-fill ph-eye" style="font-size: 24px;"></i>
                                </button>
                                <button type="button" class="icon-btn js-delete" data-id="BK-003" title="Delete">
                                    <i class="ph-fill ph-trash" style="font-size: 23px;"></i>
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
                                    <i class="ph-fill ph-eye" style="font-size: 24px;"></i>
                                </button>
                                <button type="button" class="icon-btn js-delete" data-id="BK-004" title="Delete">
                                    <i class="ph-fill ph-trash" style="font-size: 23px;"></i>
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
                                    <i class="ph-fill ph-eye" style="font-size: 24px;"></i>
                                </button>
                                <button type="button" class="icon-btn js-delete" data-id="BK-005" title="Delete">
                                    <i class="ph-fill ph-trash" style="font-size: 23px;"></i>
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
                                    <i class="ph-fill ph-eye" style="font-size: 24px;"></i>
                                </button>
                                <button type="button" class="icon-btn js-delete" data-id="BK-006" title="Delete">
                                    <i class="ph-fill ph-trash" style="font-size: 23px;"></i>
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
                <i class="ph-bold ph-caret-left" style="font-size: 20px;" aria-hidden="true"></i>
            </button>
            <span class="page-numbers" id="pageNumbers"></span>
            <button type="button" class="page-arrow" id="pageNext" aria-label="Next page">
                <i class="ph-bold ph-caret-right" style="font-size: 20px;" aria-hidden="true"></i>
            </button>
        </nav>
    </section>
</main>

<!-- Pop-up Modal: Verify Payment Receipt (Identik dengan Admin Dashboard) -->
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
                <div>
                    <dt>Booking ID</dt>
                    <dd data-field="display_id"></dd>
                </div>
                <div>
                    <dt>Customer</dt>
                    <dd data-field="name"></dd>
                </div>
                <div>
                    <dt>Total Payment</dt>
                    <dd class="info-grid__amount" data-field="amount"></dd>
                </div>
            </dl>

            <figure class="receipt-figure">
                <img id="receiptImage" src="" alt="Payment receipt" hidden>
                <p class="receipt-figure__empty" id="receiptEmpty" hidden>No payment receipt has been uploaded yet.</p>
            </figure>

            <div class="receipt-feedback" id="receiptFeedback">
                <label for="rejectionReason" class="receipt-feedback__label">
                    Admin Note / Rejection Reason <span class="receipt-feedback__hint">(Required if rejected)</span>
                </label>
                <textarea
                    id="rejectionReason"
                    class="receipt-feedback__input"
                    rows="3"
                    placeholder="e.g., Nominal transfer tidak sesuai, bukti buram, atau salah rekening tujuan..."></textarea>
                <p class="receipt-feedback__error" id="rejectionError" hidden>Please provide a reason before rejecting this payment.</p>
            </div>
        </div>

        <footer class="dash-modal__footer">
            <p class="dash-modal__note" id="receiptNote" hidden></p>
            <button type="button" class="btn-secondary" id="btnCloseReceipt" data-close hidden>Close</button>
            <button type="button" class="btn-reject" id="btnReject">Reject</button>
            <button type="button" class="btn-checked" id="btnApprove">Approve</button>
        </footer>
    </div>
</div>

<!-- Pop-up Modal: Booking Detail (Identik dengan Admin Dashboard) -->
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
                <div>
                    <dt>Booking ID</dt>
                    <dd data-field="display_id"></dd>
                </div>
                <div>
                    <dt>Customer Name</dt>
                    <dd data-field="name"></dd>
                </div>
                <div>
                    <dt>Class &amp; Level</dt>
                    <dd data-field="class_level"></dd>
                </div>
                <div>
                    <dt>Schedule</dt>
                    <dd data-field="schedule"></dd>
                </div>
                <div>
                    <dt>Medical Note</dt>
                    <dd data-field="medical"></dd>
                </div>
                <div>
                    <dt>Coach</dt>
                    <dd data-field="coach"></dd>
                </div>
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
        "statusLabels": {
            "check": "Check",
            "pending": "Pending",
            "confirmed": "Confirmed",
            "rejected": "Rejected"
        },
        "csrf": "demo-token",
        "bookings": {
            "BK-001": {
                "id": "BK-001",
                "display_id": "#BK-001",
                "name": "Arestha Jakra",
                "class_level": "Yoga — Beginner",
                "schedule": "30 Okt 2026, 13.00 WIB",
                "medical": "-",
                "coach": "Coach Grace",
                "status": "check",
                "receipt": "../assets/images/receipts/sample-receipt.png",
                "amount": "Rp 250.000"
            },
            "BK-002": {
                "id": "BK-002",
                "display_id": "#BK-002",
                "name": "Rosie Pearson",
                "class_level": "Reformer Pilates — Beginner",
                "schedule": "30 Okt 2026, 15.40 WIB",
                "medical": "Knee hurts",
                "coach": "Coach Bintari",
                "status": "pending",
                "receipt": "../assets/images/receipts/sample-receipt.png",
                "amount": "Rp 250.000"
            },
            "BK-003": {
                "id": "BK-003",
                "display_id": "#BK-003",
                "name": "Darrell Caldwell",
                "class_level": "Reformer Pilates — Beginner",
                "schedule": "30 Okt 2026, 15.40 WIB",
                "medical": "-",
                "coach": "Coach Bintari",
                "status": "pending",
                "receipt": "",
                "amount": "Rp 250.000"
            },
            "BK-004": {
                "id": "BK-004",
                "display_id": "#BK-004",
                "name": "Neona Aisnova",
                "class_level": "Yoga — Beginner",
                "schedule": "29 Okt 2026, 11.00 WIB",
                "medical": "Back Pain & Asthma",
                "coach": "Coach Alifa",
                "status": "confirmed",
                "receipt": "../assets/images/receipts/sample-receipt.png",
                "amount": "Rp 250.000"
            },
            "BK-005": {
                "id": "BK-005",
                "display_id": "#BK-005",
                "name": "Alan Cain",
                "class_level": "Reformer Pilates — Beginner",
                "schedule": "29 Okt 2026, 16.00 WIB",
                "medical": "ACL",
                "coach": "Coach Grace",
                "status": "confirmed",
                "receipt": "../assets/images/receipts/sample-receipt.png",
                "amount": "Rp 250.000"
            },
            "BK-006": {
                "id": "BK-006",
                "display_id": "#BK-006",
                "name": "Alfred Murray",
                "class_level": "Reformer Pilates — Beginner",
                "schedule": "29 Okt 2026, 18.00 WIB",
                "medical": "-",
                "coach": "Coach Grace",
                "status": "confirmed",
                "receipt": "../assets/images/receipts/sample-receipt.png",
                "amount": "Rp 250.000"
            }
        }
    }
</script>
<script src="./assets/js/admin-booking.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>