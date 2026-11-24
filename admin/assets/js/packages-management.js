(function () {
    'use strict';

    var purchases = [
        {
            id: 101,
            order_id: 'PKO-20261124-01',
            customer: 'Tiara Pasha',
            package_name: 'Reformer Starter Pack',
            variant: '4 Sessions',
            date_iso: '2026-11-24',
            date_display: '24 Nov 2026',
            amount: 460000,
            status: 'check',
            receipt_url: 'https://placehold.co/480x640/faf8f5/3e322b?text=Bukti+Transfer+BCA%0ARp460.000',
            admin_note: ''
        },
        {
            id: 102,
            order_id: 'PKO-20261123-08',
            customer: 'Ratih Ramadhani',
            package_name: 'Sooma All-Access',
            variant: '10 Sessions',
            date_iso: '2026-11-23',
            date_display: '23 Nov 2026',
            amount: 1100000,
            status: 'check',
            receipt_url: 'https://placehold.co/480x640/faf8f5/3e322b?text=Bukti+Transfer+BCA%0ARp1.100.000',
            admin_note: ''
        },
        {
            id: 103,
            order_id: 'PKO-20261122-04',
            customer: 'Lucia Torres',
            package_name: 'Yoga Flow Pass',
            variant: '5 Sessions',
            date_iso: '2026-11-22',
            date_display: '22 Nov 2026',
            amount: 500000,
            status: 'confirmed',
            receipt_url: 'https://placehold.co/480x640/faf8f5/3e322b?text=Bukti+Transfer+BCA%0ARp500.000',
            admin_note: 'Approved by admin'
        },
        {
            id: 104,
            order_id: 'PKO-20261121-02',
            customer: 'Fitriani',
            package_name: 'Reformer Monthly Plan',
            variant: '8 Sessions',
            date_iso: '2026-11-21',
            date_display: '21 Nov 2026',
            amount: 880000,
            status: 'pending',
            receipt_url: '',
            admin_note: ''
        },
        {
            id: 105,
            order_id: 'PKO-20261120-05',
            customer: 'Selvy',
            package_name: 'Reformer Starter Pack',
            variant: '4 Sessions',
            date_iso: '2026-11-20',
            date_display: '20 Nov 2026',
            amount: 460000,
            status: 'rejected',
            receipt_url: 'https://placehold.co/480x640/faf8f5/3e322b?text=Bukti+Buram',
            admin_note: 'Nominal transfer tidak sesuai.'
        }
    ];

    var packages = [
        { id: 1, code: 'PKG-001', name: 'Reformer Starter Pack', variant: '4 Sessions', category: 'Reformer Pilates', sessions: 4, validity: 30, price: 460000, status: 'active', description: 'Ideal for beginners trying Reformer Pilates.' },
        { id: 2, code: 'PKG-002', name: 'Reformer Monthly Plan', variant: '8 Sessions', category: 'Reformer Pilates', sessions: 8, validity: 45, price: 880000, status: 'active', description: 'Best value for 2x/week Pilates routine.' },
        { id: 3, code: 'PKG-003', name: 'Reformer Intensive', variant: '12 Sessions', category: 'Reformer Pilates', sessions: 12, validity: 60, price: 1260000, status: 'active', description: 'Full commitment package with 2-month validity.' },
        { id: 4, code: 'PKG-004', name: 'Yoga Flow Pass', variant: '5 Sessions', category: 'Regular Yoga', sessions: 5, validity: 30, price: 500000, status: 'active', description: 'Flexible pass for all Regular Yoga classes.' },
        { id: 5, code: 'PKG-005', name: 'Yoga Unlimited', variant: '10 Sessions', category: 'Regular Yoga', sessions: 10, validity: 45, price: 920000, status: 'inactive', description: 'Seasonal promo pack (currently paused).' },
        { id: 6, code: 'PKG-006', name: 'Sooma All-Access', variant: '10 Sessions', category: 'All Classes', sessions: 10, validity: 60, price: 1100000, status: 'active', description: 'Valid for both Reformer Pilates and Regular Yoga.' }
    ];

    var perPage = 5;
    var purPage = 1;
    var varPage = 1;
    var activeReceiptId = null;
    var deleteContext = null; 

    var toastEl = document.getElementById('dashToast');
    var toastTimer = null;

    function showToast(msg, isError) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.classList.toggle('is-error', Boolean(isError));
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3000);
    }

    function formatRupiah(num) {
        return 'Rp' + Number(num || 0).toLocaleString('id-ID');
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('dash-modal-open');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('dash-modal-open');
    }

    var tabBtns = document.querySelectorAll('.pkg-tab');
    var panelPurchases = document.getElementById('panelPurchases');
    var panelVariants = document.getElementById('panelVariants');
    var btnAddPackage = document.getElementById('btnAddPackage');

    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.dataset.tab;
            tabBtns.forEach(function (b) {
                var active = b === btn;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panelPurchases.hidden = target !== 'purchases';
            panelVariants.hidden = target !== 'variants';
            btnAddPackage.hidden = target !== 'variants';
        });
    });

    var purBody = document.getElementById('purchasesBody');
    var purSearch = document.getElementById('purSearch');
    var purDate = document.getElementById('purDate');
    var purDateWrap = document.getElementById('purDateWrap');
    var purDateText = document.getElementById('purDateText');
    var purDateClear = document.getElementById('purDateClear');
    var purStatus = document.getElementById('purStatus');
    var purStatusWrap = document.getElementById('purStatusWrap');
    var purPagePrev = document.getElementById('purPagePrev');
    var purPageNext = document.getElementById('purPageNext');
    var purPageNumbers = document.getElementById('purPageNumbers');

    var receiptModal = document.getElementById('receiptModal');
    var rejectionReason = document.getElementById('rejectionReason');
    var rejectionError = document.getElementById('rejectionError');
    var btnApprove = document.getElementById('btnApprove');
    var btnReject = document.getElementById('btnReject');
    var btnCloseReceipt = document.getElementById('btnCloseReceipt');
    var receiptNote = document.getElementById('receiptNote');
    var receiptFeedback = document.getElementById('receiptFeedback');

    function renderPurchases() {
        var checkCount = purchases.filter(function (p) { return p.status === 'check'; }).length;
        document.getElementById('kpiPurTotal').textContent = purchases.length;
        document.getElementById('kpiPurCheck').textContent = checkCount;
        document.getElementById('kpiPurConfirmed').textContent = purchases.filter(function (p) { return p.status === 'confirmed'; }).length;
        document.getElementById('kpiPurRejected').textContent = purchases.filter(function (p) { return p.status === 'rejected'; }).length;
        document.getElementById('tabBadgeCheck').textContent = checkCount;

        var q = (purSearch.value || '').trim().toLowerCase();
        var d = purDate.value;
        var st = purStatus.value;

        var list = purchases.filter(function (p) {
            var matchQ = !q || p.order_id.toLowerCase().indexOf(q) !== -1 ||
                p.customer.toLowerCase().indexOf(q) !== -1 ||
                p.package_name.toLowerCase().indexOf(q) !== -1;
            var matchD = !d || p.date_iso === d;
            var matchS = !st || p.status === st;
            return matchQ && matchD && matchS;
        });

        var totalPages = Math.max(1, Math.ceil(list.length / perPage));
        if (purPage > totalPages) purPage = totalPages;
        var pageItems = list.slice((purPage - 1) * perPage, purPage * perPage);

        if (!pageItems.length) {
            purBody.innerHTML = '<tr class="bookings-empty"><td colspan="7">No package purchases found.</td></tr>';
        } else {
            var labels = { check: 'Check', pending: 'Pending', confirmed: 'Confirmed', rejected: 'Rejected' };
            purBody.innerHTML = pageItems.map(function (p, idx) {
                var isLast = idx === pageItems.length - 1 ? ' class="is-last-visible"' : '';
                return '<tr' + isLast + '>' +
                    '<td>' + escapeHtml(p.order_id) + '</td>' +
                    '<td>' + escapeHtml(p.customer) + '</td>' +
                    '<td><div class="pkg-name-cell">' +
                        '<span class="pkg-name-cell__title">' + escapeHtml(p.package_name) + '</span>' +
                        '<span class="pkg-name-cell__variant">' + escapeHtml(p.variant) + '</span>' +
                    '</div></td>' +
                    '<td>' + escapeHtml(p.date_display) + '</td>' +
                    '<td class="pkg-price-cell">' + formatRupiah(p.amount) + '</td>' +
                    '<td><button type="button" class="status-badge status-badge--' + p.status + ' js-open-receipt" data-id="' + p.id + '">' +
                        (labels[p.status] || p.status) + '</button></td>' +
                    '<td><div class="row-actions">' +
                        '<button type="button" class="icon-btn js-open-receipt" data-id="' + p.id + '" title="Verify receipt"><i class="ph-fill ph-eye" style="font-size: 22px;"></i></button>' +
                        '<button type="button" class="icon-btn js-delete-purchase" data-id="' + p.id + '" title="Delete order"><i class="ph-fill ph-trash" style="font-size: 21px;"></i></button>' +
                    '</div></td>' +
                '</tr>';
            }).join('');
        }

        purPagePrev.disabled = purPage <= 1;
        purPageNext.disabled = purPage >= totalPages;
        var html = '';
        for (var i = 1; i <= totalPages; i++) {
            html += '<button type="button" class="page-btn' + (i === purPage ? ' is-current' : '') + '" data-pur-page="' + i + '">' + i + '</button>';
        }
        purPageNumbers.innerHTML = html;
    }

    function openReceiptModal(id) {
        var item = purchases.find(function (p) { return p.id === id; });
        if (!item) return;
        activeReceiptId = id;

        document.getElementById('receiptModalTitle').textContent = item.order_id;
        document.getElementById('recOrderId').textContent = item.order_id;
        document.getElementById('recCustomer').textContent = item.customer;
        document.getElementById('recPackage').textContent = item.package_name + ' (' + item.variant + ')';
        document.getElementById('recAmount').textContent = formatRupiah(item.amount);

        var img = document.getElementById('receiptImage');
        var empty = document.getElementById('receiptEmpty');
        if (item.receipt_url) {
            img.src = item.receipt_url;
            img.hidden = false;
            empty.hidden = true;
        } else {
            img.hidden = true;
            empty.hidden = false;
        }

        rejectionReason.value = item.admin_note || '';
        rejectionReason.classList.remove('is-invalid');
        rejectionError.hidden = true;

        var isActionable = item.status === 'check' || item.status === 'pending';
        btnApprove.hidden = !isActionable;
        btnReject.hidden = !isActionable;
        receiptFeedback.hidden = !isActionable;
        btnCloseReceipt.hidden = isActionable;

        if (!isActionable && item.admin_note) {
            receiptNote.textContent = 'Note: ' + item.admin_note;
            receiptNote.hidden = false;
        } else {
            receiptNote.hidden = true;
        }

        openModal(receiptModal);
    }

    btnApprove.addEventListener('click', function () {
        var item = purchases.find(function (p) { return p.id === activeReceiptId; });
        if (item) {
            item.status = 'confirmed';
            item.admin_note = 'Approved by admin';
            closeModal(receiptModal);
            renderPurchases();
            showToast('Package purchase ' + item.order_id + ' approved!');
        }
    });

    btnReject.addEventListener('click', function () {
        var reason = (rejectionReason.value || '').trim();
        if (!reason) {
            rejectionReason.classList.add('is-invalid');
            rejectionError.hidden = false;
            rejectionReason.focus();
            return;
        }
        var item = purchases.find(function (p) { return p.id === activeReceiptId; });
        if (item) {
            item.status = 'rejected';
            item.admin_note = reason;
            closeModal(receiptModal);
            renderPurchases();
            showToast('Package purchase ' + item.order_id + ' rejected.');
        }
    });

    purBody.addEventListener('click', function (e) {
        var recBtn = e.target.closest('.js-open-receipt');
        if (recBtn) {
            openReceiptModal(parseInt(recBtn.dataset.id, 10));
            return;
        }
        var delBtn = e.target.closest('.js-delete-purchase');
        if (delBtn) {
            var id = parseInt(delBtn.dataset.id, 10);
            var item = purchases.find(function (p) { return p.id === id; });
            if (item) {
                deleteContext = { type: 'purchase', id: id };
                document.getElementById('deleteModalTitle').textContent = 'Delete Purchase';
                document.getElementById('deleteTargetName').textContent = item.order_id + ' (' + item.customer + ')';
                openModal(deleteModal);
            }
        }
    });

    purSearch.addEventListener('input', function () { purPage = 1; renderPurchases(); });
    purDate.addEventListener('change', function () {
        var val = purDate.value;
        purDateWrap.classList.toggle('is-active', Boolean(val));
        purDateText.textContent = val || 'Date';
        purDateClear.hidden = !val;
        purPage = 1;
        renderPurchases();
    });
    purDateClear.addEventListener('click', function () {
        purDate.value = '';
        purDateWrap.classList.remove('is-active');
        purDateText.textContent = 'Date';
        purDateClear.hidden = true;
        purPage = 1;
        renderPurchases();
    });
    purStatus.addEventListener('change', function () {
        purStatusWrap.classList.toggle('is-active', Boolean(purStatus.value));
        purPage = 1;
        renderPurchases();
    });
    purPagePrev.addEventListener('click', function () { if (purPage > 1) { purPage--; renderPurchases(); } });
    purPageNext.addEventListener('click', function () { purPage++; renderPurchases(); });
    purPageNumbers.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-pur-page]');
        if (btn) { purPage = parseInt(btn.dataset.purPage, 10); renderPurchases(); }
    });

    var tbody = document.getElementById('packagesBody');
    var filterSearch = document.getElementById('filterSearch');
    var filterCategory = document.getElementById('filterCategory');
    var filterStatus = document.getElementById('filterStatus');
    var filterCategoryWrap = document.getElementById('filterCategoryWrap');
    var filterStatusWrap = document.getElementById('filterStatusWrap');
    var pagePrev = document.getElementById('pagePrev');
    var pageNext = document.getElementById('pageNext');
    var pageNumbers = document.getElementById('pageNumbers');
    var packageModal = document.getElementById('packageModal');
    var deleteModal = document.getElementById('deleteModal');
    var packageForm = document.getElementById('packageForm');
    var btnConfirmDelete = document.getElementById('btnConfirmDelete');

    function renderVariants() {
        document.getElementById('kpiTotal').textContent = packages.length;
        document.getElementById('kpiActive').textContent = packages.filter(function (p) { return p.status === 'active'; }).length;
        document.getElementById('kpiPilates').textContent = packages.filter(function (p) { return p.category === 'Reformer Pilates'; }).length;
        document.getElementById('kpiInactive').textContent = packages.filter(function (p) { return p.status === 'inactive'; }).length;

        var q = (filterSearch.value || '').trim().toLowerCase();
        var cat = filterCategory.value;
        var st = filterStatus.value;

        var list = packages.filter(function (p) {
            var matchQ = !q || p.name.toLowerCase().indexOf(q) !== -1 || p.variant.toLowerCase().indexOf(q) !== -1 || p.code.toLowerCase().indexOf(q) !== -1;
            var matchCat = !cat || p.category === cat;
            var matchSt = !st || p.status === st;
            return matchQ && matchCat && matchSt;
        });

        var totalPages = Math.max(1, Math.ceil(list.length / perPage));
        if (varPage > totalPages) varPage = totalPages;
        var pageItems = list.slice((varPage - 1) * perPage, varPage * perPage);

        if (!pageItems.length) {
            tbody.innerHTML = '<tr class="bookings-empty"><td colspan="8">No package variants found.</td></tr>';
        } else {
            tbody.innerHTML = pageItems.map(function (p, idx) {
                var isLast = idx === pageItems.length - 1 ? ' class="is-last-visible"' : '';
                var badgeClass = p.status === 'active' ? 'status-badge--confirmed' : 'status-badge--rejected';
                return '<tr' + isLast + '>' +
                    '<td>' + escapeHtml(p.code) + '</td>' +
                    '<td><div class="pkg-name-cell">' +
                        '<span class="pkg-name-cell__title">' + escapeHtml(p.name) + '</span>' +
                        '<span class="pkg-name-cell__variant">' + escapeHtml(p.variant) + '</span>' +
                    '</div></td>' +
                    '<td>' + escapeHtml(p.category) + '</td>' +
                    '<td>' + p.sessions + ' Sessions</td>' +
                    '<td>' + p.validity + ' Days</td>' +
                    '<td class="pkg-price-cell">' + formatRupiah(p.price) + '</td>' +
                    '<td><button type="button" class="status-badge ' + badgeClass + ' js-toggle-status" data-id="' + p.id + '">' +
                        (p.status === 'active' ? 'Active' : 'Inactive') + '</button></td>' +
                    '<td><div class="row-actions">' +
                        '<button type="button" class="icon-btn js-edit" data-id="' + p.id + '" title="Edit variant"><i class="ph-fill ph-pencil-simple" style="font-size: 21px;"></i></button>' +
                        '<button type="button" class="icon-btn js-delete" data-id="' + p.id + '" title="Delete variant"><i class="ph-fill ph-trash" style="font-size: 21px;"></i></button>' +
                    '</div></td>' +
                '</tr>';
            }).join('');
        }

        pagePrev.disabled = varPage <= 1;
        pageNext.disabled = varPage >= totalPages;
        var html = '';
        for (var i = 1; i <= totalPages; i++) {
            html += '<button type="button" class="page-btn' + (i === varPage ? ' is-current' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
        pageNumbers.innerHTML = html;
    }

    btnAddPackage.addEventListener('click', function () {
        document.getElementById('pkgId').value = '';
        document.getElementById('packageModalEyebrow').textContent = 'New Variant';
        document.getElementById('packageModalTitle').textContent = 'Add Package Variant';
        document.getElementById('btnSavePackage').textContent = 'Save Variant';
        packageForm.reset();
        document.getElementById('pkgStatus').value = 'active';
        openModal(packageModal);
    });

    packageForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!packageForm.checkValidity()) { packageForm.reportValidity(); return; }

        var idVal = document.getElementById('pkgId').value;
        var payload = {
            name: document.getElementById('pkgName').value.trim(),
            variant: document.getElementById('pkgVariant').value.trim(),
            category: document.getElementById('pkgCategory').value,
            sessions: parseInt(document.getElementById('pkgSessions').value, 10) || 1,
            validity: parseInt(document.getElementById('pkgValidity').value, 10) || 30,
            price: parseInt(document.getElementById('pkgPrice').value, 10) || 0,
            status: document.getElementById('pkgStatus').value,
            description: document.getElementById('pkgDesc').value.trim()
        };

        if (idVal) {
            var idx = packages.findIndex(function (p) { return p.id === parseInt(idVal, 10); });
            if (idx !== -1) {
                packages[idx] = Object.assign({}, packages[idx], payload);
                showToast('Package variant updated.');
            }
        } else {
            var nextId = packages.length ? Math.max.apply(null, packages.map(function (p) { return p.id; })) + 1 : 1;
            packages.unshift(Object.assign({ id: nextId, code: 'PKG-' + String(nextId).padStart(3, '0') }, payload));
            varPage = 1;
            showToast('New package variant added.');
        }

        closeModal(packageModal);
        renderVariants();
    });

    tbody.addEventListener('click', function (e) {
        var editBtn = e.target.closest('.js-edit');
        if (editBtn) {
            var pkg = packages.find(function (p) { return p.id === parseInt(editBtn.dataset.id, 10); });
            if (!pkg) return;
            document.getElementById('pkgId').value = pkg.id;
            document.getElementById('packageModalEyebrow').textContent = pkg.code;
            document.getElementById('packageModalTitle').textContent = 'Edit Package Variant';
            document.getElementById('btnSavePackage').textContent = 'Update Variant';
            document.getElementById('pkgName').value = pkg.name;
            document.getElementById('pkgVariant').value = pkg.variant;
            document.getElementById('pkgCategory').value = pkg.category;
            document.getElementById('pkgSessions').value = pkg.sessions;
            document.getElementById('pkgValidity').value = pkg.validity;
            document.getElementById('pkgPrice').value = pkg.price;
            document.getElementById('pkgStatus').value = pkg.status;
            document.getElementById('pkgDesc').value = pkg.description || '';
            openModal(packageModal);
            return;
        }
        var delBtn = e.target.closest('.js-delete');
        if (delBtn) {
            var id = parseInt(delBtn.dataset.id, 10);
            var target = packages.find(function (p) { return p.id === id; });
            if (target) {
                deleteContext = { type: 'variant', id: id };
                document.getElementById('deleteModalTitle').textContent = 'Delete Variant';
                document.getElementById('deleteTargetName').textContent = target.name + ' (' + target.variant + ')';
                openModal(deleteModal);
            }
            return;
        }
        var toggleBtn = e.target.closest('.js-toggle-status');
        if (toggleBtn) {
            var item = packages.find(function (p) { return p.id === parseInt(toggleBtn.dataset.id, 10); });
            if (item) {
                item.status = item.status === 'active' ? 'inactive' : 'active';
                renderVariants();
                showToast('Status changed to ' + item.status + '.');
            }
        }
    });

    btnConfirmDelete.addEventListener('click', function () {
        if (!deleteContext) return;
        if (deleteContext.type === 'purchase') {
            purchases = purchases.filter(function (p) { return p.id !== deleteContext.id; });
            renderPurchases();
            showToast('Package order deleted.');
        } else {
            packages = packages.filter(function (p) { return p.id !== deleteContext.id; });
            renderVariants();
            showToast('Package variant deleted.');
        }
        deleteContext = null;
        closeModal(deleteModal);
    });

    document.querySelectorAll('.dash-modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.closest('[data-close]')) closeModal(overlay);
        });
    });

    filterSearch.addEventListener('input', function () { varPage = 1; renderVariants(); });
    filterCategory.addEventListener('change', function () {
        filterCategoryWrap.classList.toggle('is-active', Boolean(filterCategory.value));
        varPage = 1;
        renderVariants();
    });
    filterStatus.addEventListener('change', function () {
        filterStatusWrap.classList.toggle('is-active', Boolean(filterStatus.value));
        varPage = 1;
        renderVariants();
    });
    pagePrev.addEventListener('click', function () { if (varPage > 1) { varPage--; renderVariants(); } });
    pageNext.addEventListener('click', function () { varPage++; renderVariants(); });
    pageNumbers.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-page]');
        if (btn) { varPage = parseInt(btn.dataset.page, 10); renderVariants(); }
    });

    renderPurchases();
    renderVariants();
})();