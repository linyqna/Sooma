<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../login-regist/connection.php';
require_once __DIR__ . '/../includes/booking-service.php';

$pdo = bd_pdo();

try {
    $pdo->exec("
        ALTER TABLE sooma_wellness.user_packages
        ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'pending'
    ");
    $pdo->exec("
        ALTER TABLE sooma_wellness.payments
        ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL
    ");
} catch (Throwable $e) {
}

function pm_json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function pm_fetch_dashboard_data(PDO $pdo): array
{
    $stmtPur = $pdo->query("
        SELECT up.user_package_id,
               coalesce(nullif(trim(to_jsonb(up)->>'redeem_code'), ''), 'PKO-' || up.user_package_id) as order_id,
               coalesce(nullif(trim(to_jsonb(u)->>'name'), ''), nullif(trim(to_jsonb(u)->>'full_name'), ''), 'Customer') as customer_name,
               coalesce(to_jsonb(u)->>'email', '') as customer_email,
               coalesce(p.package_name, 'Class Package') as package_name,
               (coalesce(p.total_sessions, 1)::text || ' Sessions') as variant_label,
               coalesce(p.total_sessions, 1) as total_sessions,
               coalesce(p.active_days, 30) as active_days,
               coalesce(mc.class_name, 'All Classes') as category,
               coalesce(
                   nullif(to_jsonb(up)->>'purchased_at', ''),
                   nullif(to_jsonb(up)->>'created_at', ''),
                   nullif(to_jsonb(pay)->>'uploaded_at', ''),
                   clock_timestamp()::text
               ) as purchase_date,
               coalesce(pay.gross_amount, p.package_price, 0)::bigint as total_payment,
               pay.payment_id,
               pay.payment_proof,
               pay.verification_status,
               coalesce(to_jsonb(pay)->>'rejection_reason', '') as rejection_reason,
               coalesce(nullif(trim(to_jsonb(up)->>'status'), ''), 'pending') as package_status
          FROM sooma_wellness.user_packages up
          LEFT JOIN sooma_wellness.packages p ON p.package_id = up.package_id
          LEFT JOIN sooma_wellness.master_classes mc ON mc.class_id = p.class_id
          LEFT JOIN sooma_wellness.customers c ON c.customer_id = up.customer_id
          LEFT JOIN sooma_wellness.users u ON u.user_id = c.user_id
          LEFT JOIN sooma_wellness.payments pay ON pay.payment_id = up.payment_id
         ORDER BY up.user_package_id DESC
    ");
    $purchases = $stmtPur ? $stmtPur->fetchAll(PDO::FETCH_ASSOC) : [];

    $stmtVar = $pdo->query("
        SELECT p.package_id as id,
               coalesce(nullif(trim(to_jsonb(p)->>'package_code'), ''), 'PKG-' || lpad(p.package_id::text, 3, '0')) as code,
               p.package_name as name,
               (coalesce(p.total_sessions, 1)::text || ' Sessions') as variant,
               coalesce(mc.class_name, 'All Classes') as category,
               coalesce(p.total_sessions, 1) as sessions,
               coalesce(p.active_days, 30) as validity,
               coalesce(p.package_price, 0)::bigint as price,
               'active' as status
          FROM sooma_wellness.packages p
          LEFT JOIN sooma_wellness.master_classes mc ON mc.class_id = p.class_id
         ORDER BY p.package_id ASC
    ");
    $variants = $stmtVar ? $stmtVar->fetchAll(PDO::FETCH_ASSOC) : [];

    return [
        'purchases' => $purchases,
        'variants'  => $variants,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
    try {
        $data = pm_fetch_dashboard_data($pdo);
        pm_json_response(['ok' => true] + $data);
    } catch (Throwable $ex) {
        error_log('[packages-management-load] ' . $ex->getMessage());
        pm_json_response(['ok' => false, 'message' => 'Failed to load packages data: ' . $ex->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody  = file_get_contents('php://input');
    $jsonBody = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : null;
    $input    = is_array($jsonBody) ? $jsonBody : $_POST;

    $action = (string) ($input['action'] ?? '');

    if ($action === 'approve_purchase' || $action === 'reject_purchase') {
        $userPackageId = (int) ($input['user_package_id'] ?? $input['id'] ?? 0);
        $paymentId     = (int) ($input['payment_id'] ?? 0);
        $reason        = trim((string) ($input['reason'] ?? ''));

        if ($userPackageId <= 0 && $paymentId <= 0) {
            pm_json_response(['ok' => false, 'message' => 'Invalid package order ID.'], 400);
        }

        if ($action === 'reject_purchase' && $reason === '') {
            pm_json_response(['ok' => false, 'message' => 'Please provide a rejection reason.'], 422);
        }

        try {
            $pdo->beginTransaction();

            $sqlFind = "
                SELECT up.user_package_id, up.package_id, up.payment_id, coalesce(p.active_days, 30) as active_days
                  FROM sooma_wellness.user_packages up
                  LEFT JOIN sooma_wellness.packages p ON p.package_id = up.package_id
                 WHERE " . ($userPackageId > 0 ? "up.user_package_id = :ref_id" : "up.payment_id = :ref_id") . "
                 FOR UPDATE OF up
            ";
            $stmtFind = $pdo->prepare($sqlFind);
            $stmtFind->execute([':ref_id' => $userPackageId > 0 ? $userPackageId : $paymentId]);
            $row = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $pdo->rollBack();
                pm_json_response(['ok' => false, 'message' => 'Package order not found.'], 404);
            }

            $targetUpId  = (int) $row['user_package_id'];
            $targetPayId = (int) ($row['payment_id'] ?: $paymentId);
            $activeDays  = max(1, (int) ($row['active_days'] ?? 30));

            if ($action === 'approve_purchase') {
                if ($targetPayId > 0) {
                    try {
                        $pdo->prepare("
                            UPDATE sooma_wellness.payments
                               SET verification_status = 'verified',
                                   verified_at         = clock_timestamp(),
                                   rejection_reason    = NULL
                             WHERE payment_id          = :pid
                        ")->execute([':pid' => $targetPayId]);
                    } catch (Throwable $payEx) {
                        $pdo->prepare("
                            UPDATE sooma_wellness.payments
                               SET verification_status = 'verified'
                             WHERE payment_id          = :pid
                        ")->execute([':pid' => $targetPayId]);
                    }
                }

                $pdo->prepare("
                    UPDATE sooma_wellness.user_packages
                       SET status     = 'active',
                           expired_at = clock_timestamp() + (:days || ' days')::interval
                     WHERE user_package_id = :upid
                ")->execute([
                    ':days' => $activeDays,
                    ':upid' => $targetUpId,
                ]);
            } else {
                if ($targetPayId > 0) {
                    try {
                        $pdo->prepare("
                            UPDATE sooma_wellness.payments
                               SET verification_status = 'rejected',
                                   rejection_reason    = :reason
                             WHERE payment_id          = :pid
                        ")->execute([
                            ':reason' => $reason,
                            ':pid'    => $targetPayId,
                        ]);
                    } catch (Throwable $payEx) {
                        $pdo->prepare("
                            UPDATE sooma_wellness.payments
                               SET verification_status = 'rejected'
                             WHERE payment_id          = :pid
                        ")->execute([':pid' => $targetPayId]);
                    }
                }

                $pdo->prepare("
                    UPDATE sooma_wellness.user_packages
                       SET status = 'rejected'
                     WHERE user_package_id = :upid
                ")->execute([':upid' => $targetUpId]);
            }

            $pdo->commit();
            $updated = pm_fetch_dashboard_data($pdo);
            pm_json_response([
                'ok'      => true,
                'message' => $action === 'approve_purchase'
                    ? 'Payment approved. Customer package is now active.'
                    : 'Payment rejected.',
            ] + $updated);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[packages-management-verify] ' . $ex->getMessage());
            pm_json_response(['ok' => false, 'message' => 'Failed to update package status: ' . $ex->getMessage()], 500);
        }
    }
}

$pageTitle  = 'Packages Management';
$bodyClass  = 'admin-booking-page admin-packages-page';
$headerType = 'admin';
$headerLogo = 'black';
$basePath   = '../assets/';

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Nunito+Sans:wght@600&family=Poppins:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="./assets/css/booking-management.css">
<link rel="stylesheet" href="./assets/css/packages-management.css">

<main class="dashboard-page">

    <header class="page-header page-header--split">
        <div>
            <h1 class="dashboard-title">Packages Management</h1>
            <p class="dashboard-subtitle">Verify customer package purchases and manage studio package variants</p>
        </div>
        <button type="button" class="btn-checked btn-add-pkg" id="btnAddPackage" hidden>
            <i class="ph-bold ph-plus" aria-hidden="true"></i>
            <span>Add Package Variant</span>
        </button>
    </header>

    <div class="pkg-tabs" role="tablist" aria-label="Packages management sections">
        <button type="button" class="pkg-tab is-active" role="tab" aria-selected="true" data-tab="purchases">
            <i class="ph-fill ph-receipt" aria-hidden="true"></i>
            <span>Package Purchases</span>
            <span class="pkg-tab__badge" id="tabBadgeCheck">0</span>
        </button>
        <button type="button" class="pkg-tab" role="tab" aria-selected="false" data-tab="variants">
            <i class="ph-fill ph-package" aria-hidden="true"></i>
            <span>Package Variants</span>
        </button>
    </div>

    <div id="panelPurchases" class="pkg-panel">
        <section class="summary-grid" aria-label="Package purchase statistics">
            <article class="stat-card">
                <h2 class="stat-card__label">Total Purchases</h2>
                <p class="stat-card__value" id="kpiPurTotal">0</p>
                <p class="stat-card__trend stat-card__trend--up">
                    <i class="ph ph-trend-up" style="font-size: 24px;"></i>
                    <span><strong>All</strong> <span class="stat-card__note">Package orders</span></span>
                </p>
                <div class="stat-card__icon stat-card__icon--bookings">
                    <i class="ph-fill ph-shopping-bag" style="font-size: 26px;"></i>
                </div>
            </article>

            <article class="stat-card">
                <h2 class="stat-card__label">Check</h2>
                <p class="stat-card__value" id="kpiPurCheck">0</p>
                <p class="stat-card__trend stat-card__trend--up">
                    <i class="ph ph-magnifying-glass" style="font-size: 24px;"></i>
                    <span><strong>Waiting</strong> <span class="stat-card__note">Receipts to verify</span></span>
                </p>
                <div class="stat-card__icon stat-card__icon--pending">
                    <i class="ph-fill ph-magnifying-glass" style="font-size: 26px;"></i>
                </div>
            </article>

            <article class="stat-card">
                <h2 class="stat-card__label">Confirmed</h2>
                <p class="stat-card__value" id="kpiPurConfirmed">0</p>
                <p class="stat-card__trend stat-card__trend--up">
                    <i class="ph ph-trend-up" style="font-size: 24px;"></i>
                    <span><strong>Active</strong> <span class="stat-card__note">Approved packages</span></span>
                </p>
                <div class="stat-card__icon stat-card__icon--revenue">
                    <i class="ph-fill ph-check-circle" style="font-size: 26px;"></i>
                </div>
            </article>

            <article class="stat-card">
                <h2 class="stat-card__label">Rejected</h2>
                <p class="stat-card__value" id="kpiPurRejected">0</p>
                <p class="stat-card__trend stat-card__trend--down">
                    <i class="ph ph-trend-down" style="font-size: 24px;"></i>
                    <span><strong>Declined</strong> <span class="stat-card__note">Invalid receipts</span></span>
                </p>
                <div class="stat-card__icon stat-card__icon--rejected">
                    <i class="ph-fill ph-x-circle" style="font-size: 26px;"></i>
                </div>
            </article>
        </section>

        <h2 class="section-title" style="margin-top: 20px;">Filter &amp; Search Bar</h2>

        <div class="filter-bar" role="search">
            <label class="filter-control filter-control--search">
                <i class="ph ph-magnifying-glass filter-control__icon" aria-hidden="true"></i>
                <input type="search" id="purSearch" placeholder="Search order ID, name, package ..." autocomplete="off">
            </label>

            <div class="filter-control filter-control--date" id="purDateWrap">
                <i class="ph ph-calendar-blank filter-control__icon" aria-hidden="true"></i>
                <span class="filter-control__text" id="purDateText">Date</span>
                <button type="button" class="filter-control__clear" id="purDateClear" aria-label="Clear date filter" hidden>&times;</button>
                <input type="date" id="purDate" aria-label="Filter by purchase date">
            </div>

            <label class="filter-control filter-control--status" id="purStatusWrap">
                <i class="ph ph-tag filter-control__icon" aria-hidden="true"></i>
                <select id="purStatus" aria-label="Filter by payment status">
                    <option value="">Status</option>
                    <option value="check">Check</option>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="rejected">Rejected</option>
                </select>
                <i class="ph ph-caret-down filter-control__chevron" aria-hidden="true"></i>
            </label>
        </div>

        <section class="table-card" aria-label="Package purchases">
            <div class="table-card__scroll">
                <table class="bookings-table packages-table">
                    <colgroup>
                        <col style="width:12%">
                        <col style="width:16%">
                        <col style="width:20%">
                        <col style="width:14%">
                        <col style="width:14%">
                        <col style="width:15%">
                        <col style="width:9%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Order ID</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Package &amp; Variant</th>
                            <th scope="col">Purchase Date</th>
                            <th scope="col">Total Payment</th>
                            <th scope="col">Payment Receipt</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="purchasesBody"></tbody>
                </table>
            </div>

            <nav class="table-pagination" aria-label="Purchases pagination">
                <button type="button" class="page-arrow" id="purPagePrev" aria-label="Previous page">
                    <i class="ph-bold ph-caret-left" style="font-size: 20px;"></i>
                </button>
                <span class="page-numbers" id="purPageNumbers"></span>
                <button type="button" class="page-arrow" id="purPageNext" aria-label="Next page">
                    <i class="ph-bold ph-caret-right" style="font-size: 20px;"></i>
                </button>
            </nav>
        </section>
    </div>

    <div id="panelVariants" class="pkg-panel" hidden>
        <section class="summary-grid" aria-label="Package variant statistics">
            <article class="stat-card">
                <h2 class="stat-card__label">Total Variants</h2>
                <p class="stat-card__value" id="kpiTotal">0</p>
                <p class="stat-card__trend stat-card__trend--up">
                    <i class="ph ph-trend-up" style="font-size: 24px;"></i>
                    <span><strong>All</strong> <span class="stat-card__note">Package variants</span></span>
                </p>
                <div class="stat-card__icon stat-card__icon--bookings">
                    <i class="ph-fill ph-package" style="font-size: 26px;"></i>
                </div>
            </article>

            <article class="stat-card">
                <h2 class="stat-card__label">Active Packages</h2>
                <p class="stat-card__value" id="kpiActive">0</p>
                <p class="stat-card__trend stat-card__trend--up">
                    <i class="ph ph-trend-up" style="font-size: 24px;"></i>
                    <span><strong>Live</strong> <span class="stat-card__note">Available to buy</span></span>
                </p>
                <div class="stat-card__icon stat-card__icon--revenue">
                    <i class="ph-fill ph-check-circle" style="font-size: 26px;"></i>
                </div>
            </article>

            <article class="stat-card">
                <h2 class="stat-card__label">Reformer Pilates</h2>
                <p class="stat-card__value" id="kpiPilates">0</p>
                <p class="stat-card__trend stat-card__trend--up">
                    <i class="ph ph-sparkle" style="font-size: 24px;"></i>
                    <span><strong>Variants</strong> <span class="stat-card__note">Pilates plans</span></span>
                </p>
                <div class="stat-card__icon stat-card__icon--users">
                    <i class="ph-fill ph-barbell" style="font-size: 26px;"></i>
                </div>
            </article>

            <article class="stat-card">
                <h2 class="stat-card__label">Inactive / Draft</h2>
                <p class="stat-card__value" id="kpiInactive">0</p>
                <p class="stat-card__trend stat-card__trend--down">
                    <i class="ph ph-pause-circle" style="font-size: 24px;"></i>
                    <span><strong>Hidden</strong> <span class="stat-card__note">Not shown to users</span></span>
                </p>
                <div class="stat-card__icon stat-card__icon--rejected">
                    <i class="ph-fill ph-eye-slash" style="font-size: 26px;"></i>
                </div>
            </article>
        </section>

        <h2 class="section-title">Filter &amp; Search Bar</h2>

        <div class="filter-bar" role="search">
            <label class="filter-control filter-control--search">
                <i class="ph ph-magnifying-glass filter-control__icon" aria-hidden="true"></i>
                <input type="search" id="filterSearch" placeholder="Search package or variant ..." autocomplete="off">
            </label>

            <label class="filter-control filter-control--status" id="filterCategoryWrap">
                <i class="ph ph-barbell filter-control__icon" aria-hidden="true"></i>
                <select id="filterCategory" aria-label="Filter by class category">
                    <option value="">All Categories</option>
                    <option value="Reformer Pilates">Reformer Pilates</option>
                    <option value="Regular Yoga">Regular Yoga</option>
                    <option value="All Classes">All Classes</option>
                </select>
                <i class="ph ph-caret-down filter-control__chevron" aria-hidden="true"></i>
            </label>

            <label class="filter-control filter-control--status" id="filterStatusWrap">
                <i class="ph ph-tag filter-control__icon" aria-hidden="true"></i>
                <select id="filterStatus" aria-label="Filter by package status">
                    <option value="">Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <i class="ph ph-caret-down filter-control__chevron" aria-hidden="true"></i>
            </label>
        </div>

        <section class="table-card" aria-label="Packages">
            <div class="table-card__scroll">
                <table class="bookings-table packages-table">
                    <colgroup>
                        <col style="width:10%">
                        <col style="width:20%">
                        <col style="width:16%">
                        <col style="width:12%">
                        <col style="width:12%">
                        <col style="width:14%">
                        <col style="width:9%">
                        <col style="width:7%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Code</th>
                            <th scope="col">Package &amp; Variant</th>
                            <th scope="col">Category</th>
                            <th scope="col">Sessions</th>
                            <th scope="col">Validity</th>
                            <th scope="col">Price</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="packagesBody"></tbody>
                </table>
            </div>

            <nav class="table-pagination" aria-label="Packages pagination">
                <button type="button" class="page-arrow" id="pagePrev" aria-label="Previous page">
                    <i class="ph-bold ph-caret-left" style="font-size: 20px;"></i>
                </button>
                <span class="page-numbers" id="pageNumbers"></span>
                <button type="button" class="page-arrow" id="pageNext" aria-label="Next page">
                    <i class="ph-bold ph-caret-right" style="font-size: 20px;"></i>
                </button>
            </nav>
        </section>
    </div>
</main>

<div class="dash-modal-overlay" id="receiptModal" aria-hidden="true">
    <div class="dash-modal" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle" tabindex="-1">
        <header class="dash-modal__header">
            <div>
                <p class="dash-modal__eyebrow">Verify Package Payment</p>
                <h2 class="dash-modal__title" id="receiptModalTitle"></h2>
            </div>
            <button type="button" class="dash-modal__close" aria-label="Close" data-close>&times;</button>
        </header>

        <div class="dash-modal__body">
            <dl class="info-grid info-grid--compact">
                <div>
                    <dt>Order ID</dt>
                    <dd id="recOrderId"></dd>
                </div>
                <div>
                    <dt>Customer</dt>
                    <dd id="recCustomer"></dd>
                </div>
                <div>
                    <dt>Package</dt>
                    <dd id="recPackage"></dd>
                </div>
                <div>
                    <dt>Total Payment</dt>
                    <dd class="info-grid__amount" id="recAmount"></dd>
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
                <textarea id="rejectionReason" class="receipt-feedback__input" rows="3"
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

<div class="dash-modal-overlay" id="packageModal" aria-hidden="true">
    <div class="dash-modal" role="dialog" aria-modal="true" aria-labelledby="packageModalTitle" tabindex="-1">
        <header class="dash-modal__header">
            <div>
                <p class="dash-modal__eyebrow" id="packageModalEyebrow">New Variant</p>
                <h2 class="dash-modal__title" id="packageModalTitle">Add Package Variant</h2>
            </div>
            <button type="button" class="dash-modal__close" aria-label="Close" data-close>&times;</button>
        </header>

        <form id="packageForm" novalidate>
            <input type="hidden" id="pkgId" value="">
            <div class="dash-modal__body">
                <div class="pkg-form-grid">
                    <div class="pkg-field pkg-field--full">
                        <label for="pkgName" class="pkg-label">Package Name</label>
                        <input type="text" id="pkgName" class="pkg-input" placeholder="e.g., Reformer Starter Pack" required>
                    </div>

                    <div class="pkg-field">
                        <label for="pkgVariant" class="pkg-label">Variant Label</label>
                        <input type="text" id="pkgVariant" class="pkg-input" placeholder="e.g., 5 Sessions" required>
                    </div>

                    <div class="pkg-field">
                        <label for="pkgCategory" class="pkg-label">Class Category</label>
                        <select id="pkgCategory" class="pkg-input" required>
                            <option value="Reformer Pilates">Reformer Pilates</option>
                            <option value="Regular Yoga">Regular Yoga</option>
                            <option value="All Classes">All Classes</option>
                        </select>
                    </div>

                    <div class="pkg-field">
                        <label for="pkgSessions" class="pkg-label">Total Sessions</label>
                        <input type="number" id="pkgSessions" class="pkg-input" min="1" max="100" placeholder="5" required>
                    </div>

                    <div class="pkg-field">
                        <label for="pkgValidity" class="pkg-label">Validity (Days)</label>
                        <input type="number" id="pkgValidity" class="pkg-input" min="7" max="365" placeholder="30" required>
                    </div>

                    <div class="pkg-field">
                        <label for="pkgPrice" class="pkg-label">Price (Rp)</label>
                        <input type="number" id="pkgPrice" class="pkg-input" min="10000" step="5000" placeholder="550000" required>
                    </div>

                    <div class="pkg-field">
                        <label for="pkgStatus" class="pkg-label">Status</label>
                        <select id="pkgStatus" class="pkg-input" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="pkg-field pkg-field--full">
                        <label for="pkgDesc" class="pkg-label">Description / Notes</label>
                        <textarea id="pkgDesc" class="pkg-input pkg-input--area" rows="2" placeholder="Optional notes shown on package card..."></textarea>
                    </div>
                </div>
            </div>

            <footer class="dash-modal__footer">
                <button type="button" class="btn-secondary" data-close>Cancel</button>
                <button type="submit" class="btn-checked" id="btnSavePackage">Save Variant</button>
            </footer>
        </form>
    </div>
</div>

<div class="dash-modal-overlay" id="deleteModal" aria-hidden="true">
    <div class="dash-modal dash-modal--sm" role="alertdialog" aria-modal="true" aria-labelledby="deleteModalTitle" tabindex="-1">
        <header class="dash-modal__header">
            <div>
                <p class="dash-modal__eyebrow">Confirm Action</p>
                <h2 class="dash-modal__title" id="deleteModalTitle">Delete Item</h2>
            </div>
            <button type="button" class="dash-modal__close" aria-label="Close" data-close>&times;</button>
        </header>

        <div class="dash-modal__body">
            <p class="pkg-delete-text">
                Are you sure you want to delete <strong id="deleteTargetName"></strong>? This action cannot be undone.
            </p>
        </div>

        <footer class="dash-modal__footer">
            <button type="button" class="btn-secondary" data-close>Cancel</button>
            <button type="button" class="btn-reject" id="btnConfirmDelete">Delete</button>
        </footer>
    </div>
</div>

<p class="dash-toast" id="dashToast" role="status" aria-live="polite" hidden></p>

<script src="./assets/js/packages-management.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>