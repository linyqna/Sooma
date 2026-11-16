(function () {
    'use strict';

    var root   = document.querySelector('.dashboard-page');
    var dataEl = document.getElementById('dashboard-data');
    if (!root || !dataEl) return;

    var DATA = {};
    try {
        DATA = JSON.parse(dataEl.textContent || '{}');
    } catch (err) {
        console.warn('[admin-booking.js] Data booking tidak valid:', err);
        return;
    }

    var bookings     = DATA.bookings || {};
    var statusLabels = DATA.statusLabels || { check: 'Check', pending: 'Pending', confirmed: 'Confirmed', rejected: 'Rejected' };
    var PER_PAGE     = DATA.perPage || 6;
    var verifyUrl    = root.getAttribute('data-verify-endpoint') || '';
    var deleteUrl    = root.getAttribute('data-delete-endpoint') || '';

    var MONTHS_ID = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    function $(id) { return document.getElementById(id); }

    function formatIsoDate(iso) {
        var p = (iso || '').split('-');
        if (p.length !== 3) return iso;
        return parseInt(p[2], 10) + ' ' + MONTHS_ID[parseInt(p[1], 10) - 1] + ' ' + p[0];
    }

    var toastTimer = null;
    function toast(message, isError) {
        var el = $('dashToast');
        if (!el) return;
        el.textContent = message;
        el.classList.toggle('is-error', !!isError);
        el.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.hidden = true; }, 3200);
    }

    function postToEndpoint(url, fields) {
        if (!url) return Promise.resolve(true);

        var body = new FormData();
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        body.append('csrf_token', DATA.csrf || '');

        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json().catch(function () { return { success: true }; });
            })
            .then(function (json) { return json.success !== false; })
            .catch(function (err) {
                console.warn('[admin-booking.js] Request gagal:', err);
                return false;
            });
    }

    var tbody       = $('bookingsBody');
    var emptyRow    = $('bookingsEmpty');
    var searchInput = $('filterSearch');
    var dateInput   = $('filterDate');
    var dateText    = $('filterDateText');
    var dateClear   = $('filterDateClear');
    var statusSel   = $('filterStatus');
    var pageNumbers = $('pageNumbers');
    var pagePrev    = $('pagePrev');
    var pageNext    = $('pageNext');
    var pagination  = $('pagination');

    var state = { q: '', date: '', status: '', page: 1 };

    function allRows() {
        return tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr[data-id]')) : [];
    }

    function matches(row) {
        if (state.status && row.getAttribute('data-status') !== state.status) return false;
        if (state.date && row.getAttribute('data-date') !== state.date) return false;
        if (state.q) {
            var id   = row.getAttribute('data-id').toLowerCase();
            var name = row.getAttribute('data-name') || '';
            if (id.indexOf(state.q) === -1 && name.indexOf(state.q) === -1) return false;
        }
        return true;
    }

    function pageList(current, total) {
        if (total <= 5) {
            var all = [];
            for (var i = 1; i <= total; i++) all.push(i);
            return all;
        }
        var set = {};
        [1, 2, current - 1, current, current + 1, total].forEach(function (n) {
            if (n >= 1 && n <= total) set[n] = true;
        });
        var nums = Object.keys(set).map(Number).sort(function (a, b) { return a - b; });
        var out = [];
        nums.forEach(function (n, idx) {
            if (idx && n - nums[idx - 1] > 1) out.push('…');
            out.push(n);
        });
        return out;
    }

    function renderPagination(totalPages) {
        if (!pageNumbers) return;
        pageNumbers.textContent = '';

        pageList(state.page, totalPages).forEach(function (item) {
            if (item === '…') {
                var dots = document.createElement('span');
                dots.className = 'page-ellipsis';
                dots.textContent = '…';
                pageNumbers.appendChild(dots);
                return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'page-btn' + (item === state.page ? ' is-current' : '');
            btn.textContent = String(item);
            btn.setAttribute('data-page', String(item));
            btn.setAttribute('aria-label', 'Page ' + item);
            if (item === state.page) btn.setAttribute('aria-current', 'page');
            pageNumbers.appendChild(btn);
        });

        if (pagePrev) pagePrev.disabled = state.page <= 1;
        if (pageNext) pageNext.disabled = state.page >= totalPages;
    }

    function render() {
        var rows     = allRows();
        var filtered = rows.filter(matches);
        var totalPages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
        state.page = Math.min(Math.max(state.page, 1), totalPages);

        var start = (state.page - 1) * PER_PAGE;
        var visible = filtered.slice(start, start + PER_PAGE);

        rows.forEach(function (row) {
            row.hidden = visible.indexOf(row) === -1;
            row.classList.remove('is-last-visible');
        });
        if (visible.length) visible[visible.length - 1].classList.add('is-last-visible');

        if (emptyRow) emptyRow.hidden = filtered.length > 0;
        if (pagination) pagination.hidden = filtered.length === 0;

        renderPagination(totalPages);
    }

    function resetPageAndRender() {
        state.page = 1;
        render();
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            state.q = searchInput.value.trim().toLowerCase().replace(/^#/, '');
            resetPageAndRender();
        });
    }

    if (statusSel) {
        statusSel.addEventListener('change', function () {
            state.status = statusSel.value;
            statusSel.parentNode.classList.toggle('is-active', !!state.status);
            resetPageAndRender();
        });
    }

    function setDate(iso) {
        state.date = iso || '';
        if (dateInput) dateInput.value = state.date;
        if (dateText)  dateText.textContent = state.date ? formatIsoDate(state.date) : 'Date';
        if (dateClear) dateClear.hidden = !state.date;
        if (dateInput && dateInput.parentNode) dateInput.parentNode.classList.toggle('is-active', !!state.date);
        resetPageAndRender();
    }

    if (dateInput) {
        dateInput.addEventListener('change', function () { setDate(dateInput.value); });
        dateInput.addEventListener('click', function () {
            if (typeof dateInput.showPicker === 'function') {
                try { dateInput.showPicker(); } catch (e) { }
            }
        });
    }
    if (dateClear) {
        dateClear.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setDate('');
        });
    }

    if (pagePrev) pagePrev.addEventListener('click', function () { state.page--; render(); });
    if (pageNext) pageNext.addEventListener('click', function () { state.page++; render(); });
    if (pageNumbers) {
        pageNumbers.addEventListener('click', function (e) {
            var btn = e.target.closest('.page-btn');
            if (!btn) return;
            state.page = parseInt(btn.getAttribute('data-page'), 10);
            render();
        });
    }

    var openOverlay = null;
    var lastFocus   = null;
    var pressedOnBackdrop = false;

    function openModal(overlay, trigger) {
        if (openOverlay) closeModal(openOverlay, true);

        lastFocus = trigger || document.activeElement;
        var scrollbar = window.innerWidth - document.documentElement.clientWidth;
        if (scrollbar > 0) document.body.style.paddingRight = scrollbar + 'px';
        document.body.classList.add('dash-modal-open');

        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        var body = overlay.querySelector('.dash-modal__body');
        if (body) body.scrollTop = 0;
        overlay.querySelector('.dash-modal').focus({ preventScroll: true });
        openOverlay = overlay;
    }

    function closeModal(overlay, keepFocusState) {
        if (!overlay || !overlay.classList.contains('is-open')) return;
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        openOverlay = null;

        if (keepFocusState) return;
        document.body.classList.remove('dash-modal-open');
        document.body.style.paddingRight = '';
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus({ preventScroll: true });
    }

    [$('receiptModal'), $('detailModal')].forEach(function (overlay) {
        if (!overlay) return;

        overlay.querySelectorAll('[data-close]').forEach(function (btn) {
            btn.addEventListener('click', function () { closeModal(overlay); });
        });

        overlay.addEventListener('mousedown', function (e) { pressedOnBackdrop = (e.target === overlay); });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay && pressedOnBackdrop) closeModal(overlay);
            pressedOnBackdrop = false;
        });
    });

    document.addEventListener('keydown', function (e) {
        if (!openOverlay) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            closeModal(openOverlay);
            return;
        }
        if (e.key === 'Tab') {
            var modal = openOverlay.querySelector('.dash-modal');
            var focusable = Array.prototype.filter.call(
                modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
                function (el) { return !el.disabled && !el.hidden; }
            );
            if (!focusable.length) return;
            var first = focusable[0];
            var last  = focusable[focusable.length - 1];

            if (e.shiftKey && (document.activeElement === first || document.activeElement === modal)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    function fillFields(overlay, item) {
        overlay.querySelectorAll('[data-field]').forEach(function (el) {
            var v = item[el.getAttribute('data-field')];
            el.textContent = (v === undefined || v === null) ? '' : v;
        });
    }

    var receiptModal    = $('receiptModal');
    var receiptImg      = $('receiptImage');
    var receiptEmpty    = $('receiptEmpty');
    var receiptNote     = $('receiptNote');
    var reasonInput     = $('rejectionReason');
    var reasonError     = $('rejectionError');
    var btnReject       = $('btnReject');
    var btnApprove      = $('btnApprove');
    var btnCloseReceipt = $('btnCloseReceipt');
    var activeId        = null;

    function showReceiptEmpty(message) {
        if (receiptImg) { receiptImg.hidden = true; receiptImg.removeAttribute('src'); }
        if (receiptEmpty) { receiptEmpty.textContent = message; receiptEmpty.hidden = false; }
    }

    function openReceiptModal(id, trigger) {
        var item = bookings[id];
        if (!item || !receiptModal) return;
        activeId = id;

        fillFields(receiptModal, item);

        if (item.receipt) {
            receiptEmpty.hidden = true;
            receiptImg.hidden = false;
            receiptImg.alt = 'Payment receipt of ' + item.name + ' (' + item.display_id + ')';
            receiptImg.onerror = function () { showReceiptEmpty('The receipt image could not be loaded.'); };
            receiptImg.src = item.receipt;
        } else {
            showReceiptEmpty('No payment receipt has been uploaded yet.');
        }

        if (reasonInput) {
            reasonInput.value = item.admin_note || item.rejection_reason || '';
            reasonInput.classList.remove('is-invalid');
        }
        if (reasonError) reasonError.hidden = true;

        var confirmed = item.status === 'confirmed';
        var isPending = item.status === 'pending' || !item.receipt;

        if (confirmed) {
            if (reasonInput) reasonInput.disabled = true;
            if (btnReject) btnReject.hidden = true;
            if (btnApprove) btnApprove.hidden = true;
            if (btnCloseReceipt) btnCloseReceipt.hidden = false;

            receiptNote.hidden = false;
            receiptNote.textContent = 'This payment has already been confirmed.';
        } else {
            if (reasonInput) reasonInput.disabled = false;
            if (btnCloseReceipt) btnCloseReceipt.hidden = true;

            if (btnReject) {
                btnReject.hidden = false;
                btnReject.disabled = isPending;
                btnReject.classList.remove('is-loading');
                btnReject.textContent = 'Reject';
            }

            if (btnApprove) {
                btnApprove.hidden = false;
                btnApprove.disabled = isPending;
                btnApprove.classList.remove('is-loading');
                btnApprove.textContent = 'Approve';
            }

            receiptNote.hidden = !isPending;
            receiptNote.textContent = isPending ? 'Waiting for the customer to upload a receipt.' : '';
        }

        openModal(receiptModal, trigger);
    }

    function setBookingStatus(id, status, note) {
        if (bookings[id]) {
            bookings[id].status = status;
            if (note !== undefined) bookings[id].admin_note = note;
        }

        var row = tbody.querySelector('tr[data-id="' + id + '"]');
        if (!row) return;
        row.setAttribute('data-status', status);

        var badge = row.querySelector('.status-badge');
        if (badge) {
            badge.className = 'status-badge status-badge--' + status + ' js-open-receipt';
            badge.textContent = statusLabels[status] || status;
            badge.setAttribute('aria-label', 'Payment receipt ' + id + ': ' + (statusLabels[status] || status));
        }
    }

    if (btnApprove) {
        btnApprove.addEventListener('click', function () {
            if (!activeId || btnApprove.disabled) return;
            var id = activeId;
            var note = reasonInput ? reasonInput.value.trim() : '';

            if (reasonInput) reasonInput.classList.remove('is-invalid');
            if (reasonError) reasonError.hidden = true;

            btnApprove.disabled = true;
            btnApprove.classList.add('is-loading');
            btnApprove.textContent = 'Saving…';
            if (btnReject) btnReject.disabled = true;

            postToEndpoint(verifyUrl, { booking_id: id, status: 'confirmed', note: note }).then(function (ok) {
                btnApprove.classList.remove('is-loading');
                btnApprove.textContent = 'Approve';

                if (!ok) {
                    btnApprove.disabled = false;
                    if (btnReject) btnReject.disabled = false;
                    toast('Failed to update payment status. Please try again.', true);
                    return;
                }
                setBookingStatus(id, 'confirmed', note);
                render();
                closeModal(receiptModal);
                toast(bookings[id].display_id + ' payment approved.');
            });
        });
    }

    if (btnReject) {
        btnReject.addEventListener('click', function () {
            if (!activeId || btnReject.disabled) return;
            var id = activeId;
            var reason = reasonInput ? reasonInput.value.trim() : '';

            if (!reason) {
                if (reasonInput) {
                    reasonInput.classList.add('is-invalid');
                    reasonInput.focus();
                }
                if (reasonError) reasonError.hidden = false;
                return;
            }

            if (reasonInput) reasonInput.classList.remove('is-invalid');
            if (reasonError) reasonError.hidden = true;

            btnReject.disabled = true;
            btnReject.classList.add('is-loading');
            btnReject.textContent = 'Rejecting…';
            if (btnApprove) btnApprove.disabled = true;

            postToEndpoint(verifyUrl, { booking_id: id, status: 'rejected', reason: reason }).then(function (ok) {
                btnReject.classList.remove('is-loading');
                btnReject.textContent = 'Reject';

                if (!ok) {
                    btnReject.disabled = false;
                    if (btnApprove) btnApprove.disabled = false;
                    toast('Failed to reject payment. Please try again.', true);
                    return;
                }
                setBookingStatus(id, 'rejected', reason);
                render();
                closeModal(receiptModal);
                toast(bookings[id].display_id + ' payment rejected.');
            });
        });
    }

    var detailModal  = $('detailModal');
    var detailStatus = $('detailStatus');

    function openDetailModal(id, trigger) {
        var item = bookings[id];
        if (!item || !detailModal) return;

        fillFields(detailModal, item);
        if (detailStatus) {
            detailStatus.className = 'status-badge status-badge--static status-badge--' + item.status;
            detailStatus.textContent = statusLabels[item.status] || item.status;
        }
        openModal(detailModal, trigger);
    }

    function deleteBooking(id, trigger) {
        var item = bookings[id];
        if (!item) return;
        if (!window.confirm('Delete booking ' + item.display_id + ' (' + item.name + ')? This cannot be undone.')) return;

        trigger.disabled = true;
        postToEndpoint(deleteUrl, { booking_id: id }).then(function (ok) {
            trigger.disabled = false;
            if (!ok) {
                toast('Failed to delete the booking. Please try again.', true);
                return;
            }
            var row = tbody.querySelector('tr[data-id="' + id + '"]');
            if (row) row.remove();
            delete bookings[id];
            render();
            toast(item.display_id + ' deleted.');
        });
    }

    if (tbody) {
        tbody.addEventListener('click', function (e) {
            var receiptBtn = e.target.closest('.js-open-receipt');
            if (receiptBtn) { openReceiptModal(receiptBtn.getAttribute('data-id'), receiptBtn); return; }

            var viewBtn = e.target.closest('.js-view-detail');
            if (viewBtn) { openDetailModal(viewBtn.getAttribute('data-id'), viewBtn); return; }

            var delBtn = e.target.closest('.js-delete');
            if (delBtn) { deleteBooking(delBtn.getAttribute('data-id'), delBtn); }
        });
    }

    render();
})();