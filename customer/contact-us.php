<?php

declare(strict_types=1);

$headerType = 'customer';
$headerLogo = 'black';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

foreach ([__DIR__ . '/../login-regist/.env'] as $envFile) {
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (!isset($_ENV[$k])) {
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }
        break;
    }
}

$web3formsKey = $_ENV['WEB3FORMS_KEY'] ?? getenv('WEB3FORMS_KEY') ?: '';

$page_intro = 'Do you have questions about classes, reservations, or need recommendations for the right package? Our team is here to help you.';

$contact_info = [
    ['label' => 'Hours',    'value' => '24 / 7 for emails',        'icon' => 'ph-fill ph-clock',    'icon_w' => 53, 'icon_h' => 53, 'href' => ''],
    ['label' => 'Location', 'value' => 'Jl. Ciliwung no.62, Blitar', 'icon' => 'ph-fill ph-map-pin',  'icon_w' => 47, 'icon_h' => 60, 'href' => ''],
    ['label' => 'Email',    'value' => 'soomawellness@gmail.com',  'icon' => 'ph-fill ph-envelope', 'icon_w' => 53, 'icon_h' => 42, 'href' => 'mailto:soomawellness@gmail.com'],
];

$socials = [
    ['name' => 'Instagram', 'href' => 'https://www.instagram.com/soomawellness', 'icon' => 'ph-fill ph-instagram-logo'],
    ['name' => 'TikTok',    'href' => 'https://www.tiktok.com/@soomawellness',   'icon' => 'ph-fill ph-tiktok-logo'],
    ['name' => 'WhatsApp',  'href' => 'https://wa.me/6200000000000',             'icon' => 'ph-fill ph-whatsapp-logo'],
];

$fields = [
    'fullname' => ['label' => 'Full Name',    'type' => 'text',     'placeholder' => 'Enter your full name',    'autocomplete' => 'name'],
    'email'    => ['label' => 'Email',        'type' => 'email',    'placeholder' => 'Enter your email',        'autocomplete' => 'email'],
    'phone'    => ['label' => 'Phone Number', 'type' => 'tel',      'placeholder' => 'Enter your phone number', 'autocomplete' => 'tel'],
    'message'  => ['label' => 'Message',      'type' => 'textarea', 'placeholder' => 'Enter your message',      'autocomplete' => 'off'],
];

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$current_page = 'contact-us.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact Us — Sooma Wellness</title>
    <meta name="description" content="<?= e($page_intro) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caladea:wght@700&family=Montserrat:wght@400;500;600;700;800&family=Montserrat+Alternates:wght@400&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="../assets/css/header.css">
    <link rel="stylesheet" href="../assets/css/contact-us.css">
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>

<body>

    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="contact-page">

        <section class="contact-info" aria-labelledby="contact-title">
            <h1 class="contact-info__title" id="contact-title">Contact us</h1>
            <p class="contact-info__intro"><?= e($page_intro) ?></p>

            <ul class="contact-list">
                <?php foreach ($contact_info as $item): ?>
                    <li class="contact-item">
                        <span class="contact-item__icon">
                            <i class="<?= e($item['icon']) ?>" aria-hidden="true"></i>
                        </span>

                        <div class="contact-item__text">
                            <strong class="contact-item__label"><?= e($item['label']) ?></strong>
                            <?php if ($item['href'] !== ''): ?>
                                <a class="contact-item__value" href="<?= e($item['href']) ?>"><?= e($item['value']) ?></a>
                            <?php else: ?>
                                <span class="contact-item__value"><?= e($item['value']) ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="socials">
                <h2 class="socials__title">Connect with us on social media</h2>
                <ul class="socials__list">
                    <?php foreach ($socials as $social): ?>
                        <li>
                            <a class="socials__link" href="<?= e($social['href']) ?>"
                                target="_blank" rel="noopener noreferrer"
                                aria-label="Sooma Wellness on <?= e($social['name']) ?>">
                                <i class="<?= e($social['icon']) ?>" aria-hidden="true"></i>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <section class="contact-form-card" id="contact-form" aria-label="Send us a message">
            <form class="contact-form" id="contactForm" action="https://api.web3forms.com/submit" method="POST" novalidate
                data-access-key="<?= e($web3formsKey) ?>">

                <div class="form-alert" id="formAlert" role="status" hidden></div>

                <div class="hp-field" aria-hidden="true">
                    <label>Leave this empty <input type="text" name="botcheck" tabindex="-1" autocomplete="off"></label>
                </div>

                <?php foreach ($fields as $name => $field):
                    $input_id = 'field-' . $name;
                    $error_id = $input_id . '-error';
                ?>
                    <div class="form-field" data-field-wrap="<?= e($name) ?>">
                        <label class="form-field__label" for="<?= e($input_id) ?>"><?= e($field['label']) ?></label>

                        <?php if ($field['type'] === 'textarea'): ?>
                            <textarea class="form-field__control" id="<?= e($input_id) ?>" name="<?= e($name) ?>"
                                placeholder="<?= e($field['placeholder']) ?>" required maxlength="2000"></textarea>
                        <?php else: ?>
                            <input class="form-field__control" id="<?= e($input_id) ?>" name="<?= e($name) ?>"
                                type="<?= e($field['type']) ?>"
                                placeholder="<?= e($field['placeholder']) ?>"
                                autocomplete="<?= e($field['autocomplete']) ?>" required>
                        <?php endif; ?>

                        <p class="form-field__error" id="<?= e($error_id) ?>" hidden></p>
                    </div>
                <?php endforeach; ?>

                <button class="btn-submit" id="submitBtn" type="submit">
                    Submit <i class="icon-arrow" aria-hidden="true"></i>
                </button>
            </form>
        </section>

    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>

    <script>
    (function () {
        const form      = document.getElementById('contactForm');
        const alertBox  = document.getElementById('formAlert');
        const submitBtn = document.getElementById('submitBtn');
        if (!form) return;

        const accessKey = (form.dataset.accessKey || '').trim();

        function showAlert(type, message) {
            alertBox.className = 'form-alert form-alert--' + type;
            alertBox.textContent = message;
            alertBox.hidden = false;
        }

        function setFieldError(name, message) {
            const wrap  = form.querySelector('[data-field-wrap="' + name + '"]');
            const input = form.querySelector('[name="' + name + '"]');
            const errEl = document.getElementById('field-' + name + '-error');
            if (!wrap || !input || !errEl) return;

            if (message) {
                wrap.classList.add('has-error');
                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', errEl.id);
                errEl.textContent = message;
                errEl.hidden = false;
            } else {
                wrap.classList.remove('has-error');
                input.removeAttribute('aria-invalid');
                input.removeAttribute('aria-describedby');
                errEl.textContent = '';
                errEl.hidden = true;
            }
        }

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            alertBox.hidden = true;

            const fullname = form.fullname.value.trim();
            const email    = form.email.value.trim();
            const phone    = form.phone.value.trim();
            const message  = form.message.value.trim();
            const botcheck = form.botcheck ? form.botcheck.value.trim() : '';

            if (botcheck !== '') {
                form.reset();
                showAlert('success', 'Thank you! Your message has been sent.');
                return;
            }

            let hasError = false;
            setFieldError('fullname', '');
            setFieldError('email', '');
            setFieldError('phone', '');
            setFieldError('message', '');

            if (!fullname) {
                setFieldError('fullname', 'Please enter your full name.');
                hasError = true;
            } else if (fullname.length > 100) {
                setFieldError('fullname', 'Your name is too long (max 100 characters).');
                hasError = true;
            }

            if (!email) {
                setFieldError('email', 'Please enter your email.');
                hasError = true;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                setFieldError('email', 'Please enter a valid email address.');
                hasError = true;
            }

            if (!phone) {
                setFieldError('phone', 'Please enter your phone number.');
                hasError = true;
            } else if (!/^\+?[0-9\s\-()]{8,20}$/.test(phone)) {
                setFieldError('phone', 'Please enter a valid phone number.');
                hasError = true;
            }

            if (!message) {
                setFieldError('message', 'Please enter your message.');
                hasError = true;
            } else if (message.length > 2000) {
                setFieldError('message', 'Your message is too long (max 2000 characters).');
                hasError = true;
            }

            if (hasError) {
                showAlert('error', 'Please check the highlighted fields and try again.');
                return;
            }

            if (!accessKey) {
                showAlert('error', 'WEB3FORMS_KEY belum terbaca dari file .env.');
                return;
            }

            const originalBtnHtml = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending...';

            try {
                const res = await fetch('https://api.web3forms.com/submit', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        access_key: accessKey,
                        subject: 'New Contact Message from ' + fullname,
                        from_name: 'Sooma Wellness Website',
                        name: fullname,
                        email: email,
                        replyto: email,
                        phone: phone,
                        message: message
                    })
                });

                const result = await res.json();
                if (res.ok && result.success) {
                    form.reset();
                    showAlert('success', 'Thank you! Your message has been sent.');
                } else {
                    showAlert('error', result.message || 'Sorry, we could not send your message right now.');
                }
            } catch (err) {
                showAlert('error', 'Network error. Please check your internet connection and try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            }
        });
    })();
    </script>

</body>

</html>