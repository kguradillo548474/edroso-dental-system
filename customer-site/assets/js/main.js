/**
 * Edroso Dental Clinic — customer site
 */

/** Display: +63 949 180 5825 — pass stored +639491805825 or partial input. */
function escapeHtml(str) {
    if (str == null) {
        return '';
    }
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

/** Build tel: href from stored clinic contact (digits only, +63…). */
function telHrefFromContact(raw) {
    const digits = String(raw || '').replace(/\D/g, '');
    if (!digits) {
        return '';
    }
    let d = digits;
    if (d.startsWith('0')) {
        d = '63' + d.slice(1);
    }
    if (!d.startsWith('63')) {
        d = '63' + d;
    }
    return 'tel:+' + d;
}

function publicClinicSettingsUrl() {
    const path = window.location.pathname || '';
    return path.includes('/portal/') ? '../../api/settings.php?public=1' : '../api/settings.php?public=1';
}

function formatPHPhone(raw) {
    if (raw == null || raw === '') {
        return '';
    }
    let digits = String(raw).replace(/\D/g, '');
    if (!digits) {
        return '';
    }
    if (digits.startsWith('09')) {
        digits = '63' + digits.slice(1);
    }
    if (digits.length === 10 && digits.startsWith('9')) {
        digits = '63' + digits;
    }
    digits = digits.slice(0, 12);
    if (digits.length <= 2) {
        return '+' + digits;
    }
    if (digits.length <= 5) {
        return '+' + digits.slice(0, 2) + ' ' + digits.slice(2);
    }
    if (digits.length <= 8) {
        return '+' + digits.slice(0, 2) + ' ' + digits.slice(2, 5) + ' ' + digits.slice(5);
    }
    return '+' + digits.slice(0, 2) + ' ' + digits.slice(2, 5) + ' ' + digits.slice(5, 8) + ' ' + digits.slice(8);
}

function attachPhoneFormat(inputEl) {
    if (!inputEl || inputEl.dataset.phPhoneFormatAttached === '1') {
        return;
    }
    inputEl.dataset.phPhoneFormatAttached = '1';
    inputEl.addEventListener('input', function () {
        const formatted = formatPHPhone(this.value);
        if (formatted !== this.value) {
            this.value = formatted;
        }
    });
    inputEl.addEventListener('blur', function () {
        const stripped = this.value.replace(/\s/g, '');
        if (stripped && !/^\+639\d{9}$/.test(stripped)) {
            this.setCustomValidity('Enter a valid PH number e.g. +63 949 180 5825');
            this.reportValidity();
        } else {
            this.setCustomValidity('');
        }
    });
}

function currentPageFilename() {
    const last = window.location.pathname.split('/').pop() || '';
    if (!last || !last.endsWith('.html')) return 'index.html';
    return last;
}

function navLinkClass(active) {
    if (active) {
        return 'text-[var(--primary)] font-semibold border-b-2 border-[var(--primary)] pb-0.5';
    }
    return 'text-[var(--secondary)] hover:text-[var(--primary)] transition-colors';
}

function navLinkClassMobile(active) {
    if (active) {
        return 'text-[var(--primary)] font-semibold bg-gray-100';
    }
    return 'text-[var(--secondary)] hover:text-[var(--primary)] hover:bg-gray-50';
}

function renderNav() {
    const page = currentPageFilename();
    const active = (file) => page === file;

    const links = [
        { href: 'index.html', label: 'Home', file: 'index.html' },
        { href: 'about.html', label: 'About', file: 'about.html' },
        { href: 'services.html', label: 'Services', file: 'services.html' },
        { href: 'contact.html', label: 'Contact', file: 'contact.html' },
    ];

    const desktop = links
        .map(
            (l) =>
                `<a href="${l.href}" class="${navLinkClass(active(l.file))}">${l.label}</a>`
        )
        .join('');

    const mobile = links
        .map(
            (l) =>
                `<a href="${l.href}" class="block py-2 px-3 rounded-lg ${navLinkClassMobile(active(l.file))}">${l.label}</a>`
        )
        .join('');
    const loginButton =
        '<a href="login.html" class="inline-flex items-center justify-center rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-[var(--primary-dark)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:ring-offset-2">Login</a>';
    const mobileLoginButton =
        '<a href="login.html" class="mt-2 block rounded-lg bg-[var(--primary)] px-3 py-2 text-center font-semibold text-white hover:bg-[var(--primary-dark)]">Login</a>';

    return `
<header class="sticky top-0 z-50 bg-white/95 backdrop-blur border-b border-gray-200 shadow-sm">
  <nav class="max-w-7xl mx-auto px-4 sm:px-6" aria-label="Primary">
    <div class="flex items-center justify-between h-16">
      <a href="index.html" class="inline-flex items-center gap-2 text-xl font-bold tracking-tight text-[var(--primary)] hover:text-[var(--primary-dark)] transition-colors">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--primary)] shadow-sm" aria-hidden="true">
          <img src="../assets/images/tooth-icon.png" alt="" class="h-7 w-7 rounded-md object-cover" loading="lazy" decoding="async">
        </span>
        <span>Edroso Dental</span>
      </a>
      <div class="hidden md:flex items-center gap-8 text-sm font-medium">
        ${desktop}
        ${loginButton}
      </div>
      <button type="button" id="nav-toggle" class="md:hidden inline-flex items-center justify-center p-2 rounded-lg text-[var(--secondary)] hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-[var(--primary)]" aria-controls="mobile-menu" aria-expanded="false" aria-label="Toggle menu">
        <span class="sr-only">Open menu</span>
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>
    </div>
    <div id="mobile-menu" class="md:hidden hidden border-t border-gray-100 py-3 text-sm font-medium">
      ${mobile}
      ${mobileLoginButton}
    </div>
  </nav>
</header>`;
}

function renderFooter(clinic) {
    const c = clinic && typeof clinic === 'object' ? clinic : {};
    const year = new Date().getFullYear();
    const brand = escapeHtml(String(c.clinic_name || '').trim() || 'Edroso Dental');
    const addr = String(c.clinic_address || '').trim();
    const rawPhone = String(c.clinic_contact || '').trim();
    const phoneLabel = rawPhone ? formatPHPhone(rawPhone) : '';
    const tel = rawPhone ? telHrefFromContact(rawPhone) : '';

    let contactBlock = '';
    if (addr || phoneLabel) {
        const addrHtml = addr
            ? `<p class="text-gray-400 whitespace-pre-line">${escapeHtml(addr)}</p>`
            : '';
        let phoneHtml = '';
        if (phoneLabel && tel) {
            phoneHtml = `<p><a href="${escapeHtml(tel)}" class="text-gray-300 hover:text-[var(--primary)]">${escapeHtml(phoneLabel)}</a></p>`;
        } else if (phoneLabel) {
            phoneHtml = `<p class="text-gray-400">${escapeHtml(phoneLabel)}</p>`;
        }
        contactBlock = `<div class="mt-6 space-y-1 text-sm">${addrHtml}${phoneHtml}</div>`;
    }

    return `
<footer class="bg-[var(--secondary)] text-white">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 py-10">
    <p class="text-lg font-semibold text-[var(--primary)]">${brand}</p>
    <p class="mt-1 text-gray-300 text-sm">Your smile, our priority</p>
    ${contactBlock}
    <p class="mt-8 text-xs text-gray-500 border-t border-white/10 pt-6">
      &copy; ${year} ${brand}. All rights reserved.
    </p>
  </div>
</footer>`;
}

function setMenuState(menu, button, open) {
    menu.classList.toggle('hidden', !open);
    button.setAttribute('aria-expanded', String(open));
}

function initNavToggle() {
    const btn = document.getElementById('nav-toggle');
    const menu = document.getElementById('mobile-menu');
    if (!btn || !menu) return;

    btn.addEventListener('click', () => {
        const open = menu.classList.contains('hidden');
        setMenuState(menu, btn, open);
    });

    menu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => setMenuState(menu, btn, false));
    });

    document.addEventListener('click', (event) => {
        if (menu.classList.contains('hidden')) return;
        if (menu.contains(event.target) || btn.contains(event.target)) return;
        setMenuState(menu, btn, false);
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            setMenuState(menu, btn, false);
        }
    });
}

function initScrollAnimations() {
    const targets = document.querySelectorAll('section, article');
    if (!targets.length) return;

    targets.forEach((el) => el.classList.add('animate-on-scroll'));

    if (!('IntersectionObserver' in window)) {
        targets.forEach((el) => el.classList.add('visible'));
        return;
    }

    const observer = new IntersectionObserver(
        (entries, obs) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('visible');
                obs.unobserve(entry.target);
            });
        },
        { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
    );

    targets.forEach((el) => observer.observe(el));
}

async function initFooterFromSettings() {
    const footer = document.getElementById('footer');
    if (!footer) {
        return;
    }
    let clinic = {};
    try {
        const res = await fetch(publicClinicSettingsUrl(), { credentials: 'omit', cache: 'no-store' });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data && typeof data === 'object' && !data.error) {
            clinic = data;
        }
    } catch (_) {
        /* keep empty clinic → footer still renders brand + tagline */
    }
    footer.innerHTML = renderFooter(clinic);
}

document.addEventListener('DOMContentLoaded', () => {
    const nav = document.getElementById('nav');
    if (nav) {
        nav.innerHTML = renderNav();
    }
    initFooterFromSettings();
    initNavToggle();
    initScrollAnimations();
});
