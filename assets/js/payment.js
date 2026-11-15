(function () {
  'use strict';

  var root = document.getElementById('pay-root');
  if (!root) return;

  var STATUS = {
    waiting:   'Waiting Payment',
    verifying: 'Waiting Verification',
    expired:   'Payment Expired',
    confirmed: 'Booking Confirmed'
  };
  var MAX_BYTES = parseInt(root.dataset.max, 10) || 5 * 1024 * 1024;
  var ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

  var status = root.dataset.status || 'waiting';
  var badge = document.getElementById('pay-badge');
  var badgeText = document.getElementById('pay-badge-text');
  var countdown = document.getElementById('pay-countdown');
  var doneLink = document.getElementById('pay-done-link');
  var message = document.getElementById('pay-message');

  var form = document.getElementById('pay-upload-form');          // null pada mode Package
  var fileInput = document.getElementById('pay-receipt');
  var uploadBtn = document.getElementById('pay-upload-btn');
  var preview = document.getElementById('pay-preview');
  var thumb = document.getElementById('pay-thumb');
  var fileName = document.getElementById('pay-file-name');
  var fileSize = document.getElementById('pay-file-size');
  var removeBtn = document.getElementById('pay-remove');
  var submitBtn = document.getElementById('pay-submit');

  var objectUrl = null;
  var timer = null;

  /* ---------------- Util ---------------- */
  function pad(n) { return String(n).padStart(2, '0'); }
  function fmtTime(s) { return pad(Math.floor(s / 60)) + ':' + pad(s % 60); }
  function fmtSize(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  function say(text, type) {
    if (!message) return;
    message.textContent = text || '';
    message.className = 'pay-message' + (type ? ' is-' + type : '');
  }

  function lockUpload() {
    if (fileInput) fileInput.disabled = true;
    if (uploadBtn) {
      uploadBtn.classList.add('is-disabled');
      uploadBtn.setAttribute('aria-disabled', 'true');
      uploadBtn.tabIndex = -1;
    }
    if (submitBtn) submitBtn.hidden = true;
    if (removeBtn) removeBtn.hidden = true;
    document.querySelectorAll('.pay-package-box button').forEach(function (b) { b.disabled = true; });
  }

  function setStatus(next) {
    status = next;
    root.dataset.status = next;
    badge.className = 'pay-badge pay-badge--' + next;
    badgeText.textContent = STATUS[next] || '';

    if (next !== 'waiting') lockUpload();
    if (next === 'expired') {
      stopTimer();
      countdown.textContent = '00:00';
      countdown.classList.remove('is-warning');
    }
    if (next === 'verifying' || next === 'confirmed') {
      stopTimer();
      countdown.textContent = '--:--';
      countdown.classList.remove('is-warning');
      if (doneLink) doneLink.hidden = false;
    }
  }

  function startTimer() {
    var endAt = Date.now() + (parseInt(root.dataset.remaining, 10) || 0) * 1000;
    function tick() {
      var s = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
      countdown.textContent = fmtTime(s);
      countdown.classList.toggle('is-warning', s > 0 && s <= 300);
      if (s <= 0) {
        setStatus('expired');
        say('Payment time has expired. Please create a new booking.', 'error');
      }
    }
    tick();
    if (status === 'waiting') timer = setInterval(tick, 500);
  }
  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

  function clearPreview() {
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
    if (fileInput) fileInput.value = '';
    if (preview) preview.hidden = true;
    if (submitBtn) submitBtn.hidden = true;
    if (thumb) thumb.innerHTML = '<i class="ph ph-file"></i>';
  }

  function showPreview(file) {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    thumb.innerHTML = '';
    if (file.type.indexOf('image/') === 0) {
      objectUrl = URL.createObjectURL(file);
      var img = document.createElement('img');
      img.src = objectUrl;
      img.alt = '';
      thumb.appendChild(img);
    } else {
      thumb.innerHTML = '<i class="ph ph-file-pdf"></i>';
    }
    fileName.textContent = file.name;
    fileSize.textContent = fmtSize(file.size);
    preview.hidden = false;
    removeBtn.hidden = false;
    submitBtn.hidden = false;
  }

  function onFileChosen() {
    var file = fileInput.files && fileInput.files[0];
    if (!file) { clearPreview(); return; }
    var isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
    if (ALLOWED.indexOf(file.type) === -1 && !isPdf) {
      clearPreview();
      say('Only JPG, PNG, WEBP or PDF files are allowed.', 'error');
      return;
    }
    if (file.size > MAX_BYTES) {
      clearPreview();
      say('File is too large. Maximum size is ' + fmtSize(MAX_BYTES) + '.', 'error');
      return;
    }
    say('', '');
    showPreview(file);
  }

  function onSubmit(e) {
    if (status !== 'waiting') { e.preventDefault(); return; }
    if (!fileInput.files || !fileInput.files[0]) {
      e.preventDefault();
      say('Please choose a payment receipt first.', 'error');
      return;
    }
    if (!window.fetch || !window.FormData) return;      // biarkan submit biasa
    e.preventDefault();

    submitBtn.disabled = true;
    submitBtn.querySelector('span').textContent = 'Uploading...';

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().catch(function () { return { ok: false, message: 'Unexpected server response.' }; })
          .then(function (data) { data.httpOk = res.ok; return data; });
      })
      .then(function (data) {
        if (data.ok && data.httpOk) {
          setStatus('verifying');
          fileSize.textContent = 'Submitted';
          say(data.message || 'Receipt uploaded.', 'success');
        } else {
          if (data.status === 'expired') setStatus('expired');
          say(data.message || 'Upload failed. Please try again.', 'error');
          submitBtn.disabled = false;
          submitBtn.querySelector('span').textContent = 'Submit Receipt';
        }
      })
      .catch(function () {
        say('Network error. Please check your connection and try again.', 'error');
        submitBtn.disabled = false;
        submitBtn.querySelector('span').textContent = 'Submit Receipt';
      });
  }

  if (form && status === 'waiting') {
    fileInput.addEventListener('change', onFileChosen);
    removeBtn.addEventListener('click', function () { clearPreview(); say('', ''); });
    form.addEventListener('submit', onSubmit);
    uploadBtn.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && status === 'waiting') {
        e.preventDefault();
        fileInput.click();
      }
    });
  }

  if (status === 'waiting') {
    startTimer();
  } else {
    setStatus(status);
    if (status === 'expired') countdown.textContent = '00:00';
  }
})();
