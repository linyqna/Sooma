document.addEventListener('DOMContentLoaded', () => {
    const paymentOptions = document.querySelectorAll('.payment-option');
    const paymentRadios = document.querySelectorAll(
        '.payment-option input[type="radio"]'
    );

    function updatePaymentSelection() {
        paymentOptions.forEach(option => {
            option.classList.remove('selected');
        });

        const checkedRadio = document.querySelector(
            '.payment-option input[type="radio"]:checked'
        );

        if (checkedRadio) {
            checkedRadio.closest('.payment-option')
                .classList.add('selected');
        }
    }

    paymentRadios.forEach(radio => {
        radio.addEventListener('change', updatePaymentSelection);
    });

    updatePaymentSelection();
});


document.addEventListener('DOMContentLoaded', () => {
    const tncModal = document.getElementById('tnc-modal');
    const openTncBtn = document.getElementById('bd-open-tnc');
    const acceptTncBtn = document.getElementById('tnc-accept');
    const agreeCheckbox = document.getElementById('bd-agree');

    if (!tncModal || !openTncBtn || !acceptTncBtn || !agreeCheckbox) {
        return;
    }

    function openTnc() {
        tncModal.classList.add('is-active');
        tncModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        acceptTncBtn.focus();
    }

    function closeTnc() {
        tncModal.classList.remove('is-active');
        tncModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        openTncBtn.focus();
    }

    openTncBtn.addEventListener('click', function (e) {
        e.preventDefault();
        openTnc();
    });

    acceptTncBtn.addEventListener('click', function () {
        agreeCheckbox.checked = true;

        agreeCheckbox.dispatchEvent(new Event('change', {
            bubbles: true
        }));

        closeTnc();
    });
});


document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('bd-form');
    const voucherInput = document.getElementById('bd-voucher-input');
    const applyBtn = document.getElementById('bd-apply-voucher');
    const removeBtn = document.getElementById('bd-remove-voucher');
    const feedbackEl = document.getElementById('bd-voucher-feedback');
    const hiddenCodeInput = document.getElementById('bd-voucher-code');

    const subtotalEl = document.getElementById('bd-subtotal');
    const totalEl = document.getElementById('bd-total');
    const appliedRow = document.getElementById('bd-voucher-applied');
    const appliedBadge = document.getElementById('bd-voucher-badge');
    const appliedDesc = document.getElementById('bd-voucher-desc');
    const discountEl = document.getElementById('bd-voucher-discount');

    if (!voucherInput || !applyBtn || !subtotalEl || !totalEl) {
        return;
    }

    const startTimeStr = form ? (form.dataset.startTime || '') : '';
    const birthdateStr = form ? (form.dataset.birthdate || '') : '';
    const isPrivateAccepted = form ? form.dataset.privateAccepted === '1' : false;

    const VOUCHERS = {
        GENZ100: {
            title: 'Calling All Gen Z',
            description: 'Calling All Gen Z — Promo Rp100.000 / Session',
            type: 'per_person',
            price: 100000
        },
        SUMMER400: {
            title: 'Summer Day Out',
            description: 'Summer Day Out — Paket Rp400.000 / 4 Orang',
            type: 'bundle_4',
            price: 400000
        },
        HAPPYHOUR135: {
            title: 'Happy Hour Promo',
            description: 'Happy Hour Promo — Rp135.000 / Session',
            type: 'per_person',
            price: 135000
        }
    };

    let activeCode = null;
    let lastUnitPrice = 120000;
    let lastAttendeeCount = 1;
    let lastIsPackage = false;
    let isCurrentVoucherEligible = true;

    function formatRp(num) {
        return '<em>Rp</em>' + Math.max(0, num).toLocaleString('en-US');
    }

    function parseStartMinutes(timeStr) {
        const match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(timeStr.trim());
        if (!match) return null;
        return parseInt(match[1], 10) * 60 + parseInt(match[2], 10);
    }

    function validateVoucherRules(code, attendeeCount, unitPrice) {
        if (!VOUCHERS[code]) {
            return 'Kode voucher tidak valid atau tidak berlaku.';
        }

        if (code === 'SUMMER400') {
            if (!isPrivateAccepted) {
                return 'Voucher Summer Day Out hanya bisa digunakan jika Anda sudah melakukan Request Private dan telah di-accept oleh admin.';
            }
            if (attendeeCount < 4 || attendeeCount % 4 !== 0) {
                return 'Voucher Summer Day Out hanya berlaku jika jumlah peserta (termasuk attendee) berjumlah 4 atau kelipatan 4 (saat ini: ' + attendeeCount + ' peserta).';
            }
            return null;
        }

        if (code === 'GENZ100') {
            const yearMatch = /(19\d{2}|20\d{2})/.exec(birthdateStr.trim());
            const birthYear = yearMatch ? parseInt(yearMatch[1], 10) : 0;
            if (birthYear < 1997 || birthYear > 2012) {
                return 'Voucher Calling All Gen Z hanya dapat dipakai oleh customer dengan tahun lahir 1997 - 2012.';
            }
            if (unitPrice < 100000) {
                return 'Voucher Calling All Gen Z hanya berlaku untuk session dengan harga Rp100.000 ke atas.';
            }
            return null;
        }

        if (code === 'HAPPYHOUR135') {
            const startMins = parseStartMinutes(startTimeStr);
            const isSlot1 = startMins !== null && startMins >= 11 * 60 && startMins <= 14 * 60;
            const isSlot2 = startMins !== null && startMins >= 15 * 60 && startMins <= 17 * 60;
            if (!isSlot1 && !isSlot2) {
                return 'Voucher Happy Hour hanya berlaku untuk reservasi kelas pada pukul 11.00 - 14.00 atau 15.00 - 17.00.';
            }
            return null;
        }

        return null;
    }

    function computeTotal(unitPrice, attendeeCount, isPackage = false) {
        if (isPackage) {
            return 0;
        }
        const subtotal = unitPrice * attendeeCount;
        if (activeCode && VOUCHERS[activeCode] && isCurrentVoucherEligible) {
            const voucher = VOUCHERS[activeCode];
            if (voucher.type === 'bundle_4') {
                return (attendeeCount / 4) * voucher.price;
            }
            return voucher.price * attendeeCount;
        }
        return subtotal;
    }

    function calculateAndRender() {
        const subtotal = lastUnitPrice * lastAttendeeCount;
        subtotalEl.innerHTML = formatRp(subtotal);

        if (lastIsPackage) {
            isCurrentVoucherEligible = true;
            if (appliedRow) appliedRow.hidden = true;
            totalEl.innerHTML = formatRp(0);
            if (hiddenCodeInput) hiddenCodeInput.value = '';
            return;
        }

        if (activeCode && VOUCHERS[activeCode]) {
            const ruleError = validateVoucherRules(activeCode, lastAttendeeCount, lastUnitPrice);
            if (ruleError) {
                isCurrentVoucherEligible = false;
                appliedRow.hidden = true;
                totalEl.innerHTML = formatRp(subtotal);
                if (hiddenCodeInput) hiddenCodeInput.value = '';

                feedbackEl.textContent = ruleError;
                feedbackEl.className = 'bd-voucher__feedback is-error';
                return;
            }

            isCurrentVoucherEligible = true;
            const voucher = VOUCHERS[activeCode];
            const finalTotal = computeTotal(lastUnitPrice, lastAttendeeCount, false);
            const diff = subtotal - finalTotal;

            appliedRow.hidden = false;
            appliedBadge.textContent = activeCode;
            appliedDesc.textContent = voucher.description;

            if (diff > 0) {
                discountEl.innerHTML = '-' + formatRp(diff);
            } else if (diff < 0) {
                discountEl.textContent = 'Tarif Promo';
            } else {
                discountEl.innerHTML = formatRp(0);
            }

            feedbackEl.textContent = 'Voucher "' + voucher.title + '" berhasil digunakan.';
            feedbackEl.className = 'bd-voucher__feedback is-success';

            totalEl.innerHTML = formatRp(finalTotal);
            if (hiddenCodeInput) hiddenCodeInput.value = activeCode;
        } else {
            isCurrentVoucherEligible = true;
            appliedRow.hidden = true;
            totalEl.innerHTML = formatRp(subtotal);
            if (hiddenCodeInput) hiddenCodeInput.value = '';
        }
    }

    window.bdRefreshVoucher = function (unitPrice, attendeeCount, isPackage) {
        lastUnitPrice = unitPrice;
        lastAttendeeCount = attendeeCount;
        lastIsPackage = Boolean(isPackage);
        calculateAndRender();
    };

    window.bdGetFinalTotal = function (unitPrice, attendeeCount) {
        return computeTotal(unitPrice, attendeeCount, lastIsPackage);
    };

    window.bdIsVoucherValid = function () {
        if (lastIsPackage || !activeCode) return true;
        return validateVoucherRules(activeCode, lastAttendeeCount, lastUnitPrice) === null;
    };

    function applyVoucher() {
        if (lastIsPackage) {
            feedbackEl.textContent = 'Voucher hanya berlaku untuk metode Drop-in Payment.';
            feedbackEl.className = 'bd-voucher__feedback is-error';
            return;
        }

        const code = voucherInput.value.trim().toUpperCase();

        if (!code) {
            feedbackEl.textContent = 'Silakan masukkan kode voucher terlebih dahulu.';
            feedbackEl.className = 'bd-voucher__feedback is-error';
            return;
        }

        const ruleError = validateVoucherRules(code, lastAttendeeCount, lastUnitPrice);
        if (ruleError) {
            feedbackEl.textContent = ruleError;
            feedbackEl.className = 'bd-voucher__feedback is-error';
            return;
        }

        activeCode = code;
        voucherInput.value = code;
        voucherInput.disabled = true;
        applyBtn.hidden = true;
        removeBtn.hidden = false;

        calculateAndRender();
        document.dispatchEvent(new Event('bd:voucher-changed'));
    }

    function removeVoucher() {
        activeCode = null;
        isCurrentVoucherEligible = true;
        voucherInput.value = '';
        voucherInput.disabled = false;
        applyBtn.hidden = false;
        removeBtn.hidden = true;
        feedbackEl.textContent = '';
        feedbackEl.className = 'bd-voucher__feedback';

        calculateAndRender();
        document.dispatchEvent(new Event('bd:voucher-changed'));
    }

    applyBtn.addEventListener('click', applyVoucher);
    removeBtn.addEventListener('click', removeVoucher);

    voucherInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            applyVoucher();
        }
    });

    if (form) {
        const checkedMethod = form.querySelector('input[name="payment_method"]:checked');
        lastUnitPrice = parseInt(form.dataset.price, 10) || 120000;
        lastAttendeeCount = document.querySelectorAll('[data-attendee]').length || 1;
        lastIsPackage = checkedMethod ? checkedMethod.value === 'package' : false;
        calculateAndRender();
    }
});