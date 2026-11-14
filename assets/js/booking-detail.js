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

    // buka modal saat link T&C diklik
    openTncBtn.addEventListener('click', function (e) {
        e.preventDefault();
        openTnc();
    });

    // tutup modal hanya melalui tombol I Understand
    acceptTncBtn.addEventListener('click', function () {
        agreeCheckbox.checked = true;

        agreeCheckbox.dispatchEvent(new Event('change', {
            bubbles: true
        }));

        closeTnc();
    });
});
