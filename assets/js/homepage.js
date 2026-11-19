const navToggle = document.querySelector('.nav-toggle');
const mainNav = document.querySelector('.main-nav');

if (navToggle && mainNav) {
  navToggle.addEventListener('click', () => {
    const isOpen = mainNav.classList.toggle('is-open');
    navToggle.setAttribute('aria-expanded', String(isOpen));
  });

  mainNav.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      mainNav.classList.remove('is-open');
      navToggle.setAttribute('aria-expanded', 'false');
    });
  });
}

const sections = document.querySelectorAll(
  '#home, #about, #classes, #offers, #timetable, #contact'
);

const navLinks = document.querySelectorAll('.main-nav a');

function updateActiveNav() {
  let currentSection = 'home';

  sections.forEach(section => {
    if (window.scrollY >= section.offsetTop - 150) {
      currentSection = section.id;
    }
  });

  navLinks.forEach(link => {
    const isActive = link.getAttribute('href') === `#${currentSection}`;

    link.classList.toggle('active', isActive);

    if (isActive) {
      link.setAttribute('aria-current', 'location');
    } else {
      link.removeAttribute('aria-current');
    }
  });
}

if (sections.length && navLinks.length) {
  window.addEventListener('scroll', updateActiveNav);
  updateActiveNav();
}

const gallery = document.querySelector('.studio-gallery');

if (gallery) {
  const prev = gallery.querySelector('.gallery-prev');
  const next = gallery.querySelector('.gallery-next');
  const main = gallery.querySelector('.gallery-main');
  const left = gallery.querySelector('.gallery-left');
  const right = gallery.querySelector('.gallery-right');

  const images = [
  '../assets/images/gallery-main.png',
  '../assets/images/gallery-left.png',
  '../assets/images/gallery-right.png'
];

  let current = 0;

  function renderGallery() {
    main.src = images[current];
    left.src = images[(current + images.length - 1) % images.length];
    right.src = images[(current + 1) % images.length];
  }

  prev?.addEventListener('click', () => {
    current = (current - 1 + images.length) % images.length;
    renderGallery();
  });

  next?.addEventListener('click', () => {
    current = (current + 1) % images.length;
    renderGallery();
  });
}

/* ---- Pop-up Claim Voucher Modal ---- */
const voucherModal = document.getElementById('voucher-modal');
const voucherClose = document.getElementById('voucher-close');
const voucherStatusEl = document.getElementById('voucher-modal-status');
const voucherTitleEl = document.getElementById('voucher-modal-title');
const voucherDetailEl = document.getElementById('voucher-modal-detail');
const voucherLabelEl = document.getElementById('voucher-label-text');
const voucherCodeEl = document.getElementById('voucher-code-text');
const voucherNoteEl = document.getElementById('voucher-note-text');
const voucherClaimBtn = document.getElementById('voucher-claim-btn');
const openOfferButtons = document.querySelectorAll('.js-open-offer');

if (voucherModal && openOfferButtons.length) {
  let pendingVoucherCode = '';
  let isVoucherClaimed = false;

  function renderUnclaimedState(title, detail, code) {
    pendingVoucherCode = code;
    isVoucherClaimed = false;

    voucherStatusEl.textContent = 'Exclusive Offer';
    voucherTitleEl.textContent = title;
    voucherDetailEl.textContent = detail;
    voucherLabelEl.textContent = 'Tekan tombol Claim untuk mendapatkan kode voucher:';

    voucherCodeEl.textContent = '••••••••';
    voucherCodeEl.classList.add('is-masked');

    voucherClaimBtn.textContent = 'Claim';
    voucherClaimBtn.classList.remove('is-claimed');
    voucherNoteEl.innerHTML =
      'Kode voucher dapat digunakan pada metode <strong>Drop-in Payment</strong> saat pemesanan kelas.';
  }

  function renderClaimedState(code) {
    isVoucherClaimed = true;

    voucherStatusEl.textContent = 'Offer Claimed';
    voucherLabelEl.textContent = 'Your voucher:';

    voucherCodeEl.textContent = code;
    voucherCodeEl.classList.remove('is-masked');

    voucherClaimBtn.textContent = 'Copy';
    voucherClaimBtn.classList.add('is-claimed');
    voucherNoteEl.innerHTML =
      'Kode berhasil diklaim & disalin! Masukkan pada kolom <strong>Input voucher</strong> di <strong>Drop-in Payment</strong>.';
  }

  function closeVoucherModal() {
    voucherModal.classList.remove('is-open');
    voucherModal.setAttribute('aria-hidden', 'true');
  }

  // 1. Klik "Claim Offer Now" di kartu -> hanya membuka pop-up (belum diklaim)
  openOfferButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const code = btn.dataset.code || '';
      const title = btn.dataset.title || 'Special Offer';
      const detail = btn.dataset.detail || '';

      const claimedList = JSON.parse(localStorage.getItem('claimedVouchers') || '[]');
      renderUnclaimedState(title, detail, code);

      // Jika user sebelumnya sudah pernah klik Claim untuk promo ini, langsung tampilkan kodenya
      if (claimedList.includes(code)) {
        renderClaimedState(code);
      }

      voucherModal.classList.add('is-open');
      voucherModal.setAttribute('aria-hidden', 'false');
    });
  });

  // 2. Klik tombol "Claim" di dalam pop-up -> baru klaim, tampilkan kode, & salin
  voucherClaimBtn?.addEventListener('click', () => {
    if (!pendingVoucherCode) return;

    if (!isVoucherClaimed) {
      const claimedList = JSON.parse(localStorage.getItem('claimedVouchers') || '[]');
      if (!claimedList.includes(pendingVoucherCode)) {
        claimedList.push(pendingVoucherCode);
        localStorage.setItem('claimedVouchers', JSON.stringify(claimedList));
      }

      renderClaimedState(pendingVoucherCode);

      if (navigator.clipboard) {
        navigator.clipboard.writeText(pendingVoucherCode).catch(() => {});
      }
      voucherClaimBtn.textContent = 'Claimed & Copied!';
      setTimeout(() => {
        voucherClaimBtn.textContent = 'Copy';
      }, 2000);
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(pendingVoucherCode).then(() => {
        voucherClaimBtn.textContent = 'Copied!';
        setTimeout(() => {
          voucherClaimBtn.textContent = 'Copy';
        }, 2000);
      });
    }
  });

  voucherClose?.addEventListener('click', closeVoucherModal);

  voucherModal.addEventListener('click', e => {
    if (e.target === voucherModal) closeVoucherModal();
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && voucherModal.classList.contains('is-open')) {
      closeVoucherModal();
    }
  });
}