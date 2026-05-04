// =============================================
// Edroso Dental Clinic — Shared JS Utilities
// =============================================

const API_BASE = '../api';
let __csrfToken = '';

async function fetchCsrfToken() {
    if (__csrfToken) return __csrfToken;
    const url = new URL(`${API_BASE}/auth.php`, window.location.href);
    url.searchParams.set('action', 'csrf');
    const res = await fetch(url, { method: 'GET', credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.csrf_token) {
        throw new Error(data.error || 'Could not get CSRF token');
    }
    __csrfToken = String(data.csrf_token);
    return __csrfToken;
}

// ── API Client ────────────────────────────────────────────────────────────
const api = {
    async request(endpoint, method = 'GET', body = null, params = {}) {
        // Support "endpoint?key=val" already embedded in endpoint string
        const [base, qs] = endpoint.split('?');
        const url = new URL(`${API_BASE}/${base}`, window.location.href);
        if (qs) new URLSearchParams(qs).forEach((v, k) => url.searchParams.set(k, v));
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

        const opts = {
            method,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };
        if (method !== 'GET') {
            const csrfToken = await fetchCsrfToken();
            opts.headers['X-CSRF-Token'] = csrfToken;
            if (body && typeof body === 'object' && !Array.isArray(body)) {
                body = Object.assign({}, body, { csrf_token: csrfToken });
            }
        }
        if (body) opts.body = JSON.stringify(body);

        const res = await fetch(url, opts);
        const text = await res.text();
        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch (e) {
            const snippet = text.slice(0, 80).replace(/\s+/g, ' ');
            throw new Error(
                snippet.startsWith('<')
                    ? 'Server returned HTML instead of JSON (often a PHP error or wrong URL). Check Network tab for api/' + base + '.'
                    : 'Invalid JSON from server: ' + snippet
            );
        }
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    },
    /** Multipart POST (e.g. file upload). Do not set Content-Type manually. */
    async postForm(endpoint, formData) {
        if (!(formData instanceof FormData)) {
            throw new Error('postForm expects FormData');
        }
        const csrfToken = await fetchCsrfToken();
        if (![...formData.keys()].includes('csrf_token')) {
            formData.append('csrf_token', csrfToken);
        }
        const [base, qs] = endpoint.split('?');
        const url = new URL(`${API_BASE}/${base}`, window.location.href);
        if (qs) new URLSearchParams(qs).forEach((v, k) => url.searchParams.set(k, v));
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData,
            credentials: 'same-origin'
        });
        const text = await res.text();
        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch (e) {
            const snippet = text.slice(0, 80).replace(/\s+/g, ' ');
            throw new Error(
                snippet.startsWith('<')
                    ? 'Server returned HTML instead of JSON. Check Network tab for api/' + base + '.'
                    : 'Invalid JSON from server: ' + snippet
            );
        }
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    },
    get:    (ep, params)       => api.request(ep, 'GET',    null, params || {}),
    post:   (ep, body)         => api.request(ep, 'POST',   body, {}),
    put:    (ep, body, params) => api.request(ep, 'PUT',    body, params || {}),
    delete: (ep, params)       => api.request(ep, 'DELETE', null, params || {}),
};

// ── Auth ──────────────────────────────────────────────────────────────────
async function checkAuth() {
    try {
        const data = await api.get('auth.php', { action: 'me' });
        if (!data.authenticated) { window.location.href = 'login.html'; return null; }

        const name     = data.user.full_name || data.user.username;
        const initials = name.split(' ').map(p => p[0]).join('').toUpperCase().slice(0, 2);

        document.querySelectorAll('#userName, #adminName').forEach(el => el.textContent = name);
        document.querySelectorAll('#userInitials').forEach(el => el.textContent = initials);
        initAutoLogoutFromSession(data.auto_logout_minutes);
        return data.user;
    } catch (e) {
        window.location.href = 'login.html';
        return null;
    }
}

async function logout() {
    try { await api.post('auth.php?action=logout'); } catch (_) {}
    window.location.href = 'login.html';
}

// ── Idle auto-logout (minutes from settings via auth.php?action=me) ───────
let __idleLogoutBound = false;
function initAutoLogoutFromSession(minutes) {
    const m = parseInt(minutes, 10);
    if (!m || m <= 0 || __idleLogoutBound) {
        return;
    }
    __idleLogoutBound = true;
    const ms = m * 60 * 1000;
    let timer;
    const arm = () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            showToast('Session ended due to inactivity.', 'info');
            await logout();
        }, ms);
    };
    ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(ev => {
        document.addEventListener(ev, arm, { passive: true });
    });
    arm();
}

// ── Toast notifications ───────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const existing = document.getElementById('toast-container');
    if (existing) existing.remove();

    const icons  = { success: 'fa-check-circle', error: 'fa-times-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle' };
    const colors = {
        success: 'text-primary  bg-primary/10',
        error:   'text-red-500  bg-red-50',
        info:    'text-blue-500 bg-blue-50',
        warning: 'text-amber-500 bg-amber-50',
    };

    const toast = document.createElement('div');
    toast.id = 'toast-container';
    toast.className = 'fixed bottom-6 right-6 z-[9999] flex items-center bg-white rounded-2xl shadow-2xl p-4 gap-3 max-w-sm border border-neutral-dark transform transition-all duration-300 translate-y-4 opacity-0';
    toast.innerHTML = `
        <div class="flex-shrink-0 w-9 h-9 rounded-full ${colors[type]} flex items-center justify-center">
            <i class="fas ${icons[type]}"></i>
        </div>
        <p class="text-sm font-medium text-secondary flex-1">${message}</p>
        <button onclick="this.closest('#toast-container').remove()" class="text-gray-300 hover:text-gray-500 ml-1">
            <i class="fas fa-times text-xs"></i>
        </button>`;
    document.body.appendChild(toast);

    requestAnimationFrame(() => requestAnimationFrame(() => {
        toast.classList.remove('translate-y-4', 'opacity-0');
    }));
    setTimeout(() => {
        toast.classList.add('translate-y-4', 'opacity-0');
        setTimeout(() => toast.remove(), 350);
    }, 4000);
}

// ── Modal helpers ─────────────────────────────────────────────────────────
function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('hidden');
    // Two rAF to allow display to kick in before transition
    requestAnimationFrame(() => requestAnimationFrame(() => m.classList.add('modal-open')));
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('modal-open');
    setTimeout(() => m.classList.add('hidden'), 280);
}

// Close modal on backdrop click
document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('modal-open');
        setTimeout(() => e.target.classList.add('hidden'), 280);
    }
});

// ── Sidebar toggle ────────────────────────────────────────────────────────
function initSidebar() {
    const btn = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const main = document.getElementById('main-content');
    const header = document.getElementById('mainHeader');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (!btn || !sidebar || !main) return;

    let collapsedDesktop = false;
    function isMobile() {
        return window.matchMedia('(max-width: 767px)').matches;
    }
    function applyLayoutState() {
        if (isMobile()) {
            main.classList.remove('ml-64', 'w-[calc(100%-16rem)]');
            main.classList.add('ml-0', 'w-full');
            if (header) {
                header.classList.remove('w-[calc(100%-16rem)]');
                header.classList.add('w-full');
            }
            const open = !sidebar.classList.contains('-translate-x-full');
            if (backdrop) backdrop.classList.toggle('hidden', !open);
            return;
        }
        sidebar.classList.remove('-translate-x-full');
        if (backdrop) backdrop.classList.add('hidden');
        const hidden = collapsedDesktop;
        main.classList.toggle('ml-64', !hidden);
        main.classList.toggle('w-[calc(100%-16rem)]', !hidden);
        main.classList.toggle('ml-0', hidden);
        main.classList.toggle('w-full', hidden);
        if (header) {
            header.classList.toggle('w-[calc(100%-16rem)]', !hidden);
            header.classList.toggle('w-full', hidden);
        }
    }

    btn.addEventListener('click', () => {
        if (isMobile()) {
            const opening = sidebar.classList.contains('-translate-x-full');
            sidebar.classList.toggle('-translate-x-full', !opening);
            if (backdrop) backdrop.classList.toggle('hidden', !opening);
            return;
        }
        collapsedDesktop = !collapsedDesktop;
        applyLayoutState();
    });

    if (backdrop) {
        backdrop.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            backdrop.classList.add('hidden');
        });
    }

    window.addEventListener('resize', applyLayoutState);
    applyLayoutState();
}

// ── Philippine phone (display +63 949 180 5825, store +639491805825) ─────
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

// ── Formatters ────────────────────────────────────────────────────────────
function formatDate(dateStr) {
    if (!dateStr) return '—';
    const s = String(dateStr).trim();
    const ymd = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
    const d = ymd
        ? new Date(+ymd[1], +ymd[2] - 1, +ymd[3])
        : new Date(s);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(timeStr) {
    if (!timeStr) return '—';
    const raw = String(timeStr).trim();
    const hm = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(raw);
    if (hm) {
        const hour = parseInt(hm[1], 10);
        const minute = hm[2];
        return `${hour % 12 || 12}:${minute} ${hour >= 12 ? 'PM' : 'AM'}`;
    }
    const dt = new Date(raw);
    if (!Number.isNaN(dt.getTime())) {
        return dt.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
    }
    return raw;
}

function formatMoney(amount) {
    return '₱' + parseFloat(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getStatusClass(status) {
    const map = {
        'Confirmed':  'bg-primary/15 text-primary',
        'In Progress':'bg-amber-400/20 text-amber-600',
        'Completed':  'bg-green-500/15 text-green-600',
        'Cancelled':  'bg-red-500/15 text-red-500',
        'Scheduled':  'bg-blue-500/15 text-blue-600',
        'Pending':    'bg-amber-400/20 text-amber-600',
        'Paid':       'bg-green-500/15 text-green-600',
        'Partial':    'bg-blue-500/15 text-blue-600',
        'Refunded':   'bg-gray-400/20 text-gray-500',
        'active':     'bg-primary/15 text-primary',
        'inactive':   'bg-gray-200 text-gray-500',
    };
    return map[status] || 'bg-gray-200 text-gray-600';
}

function getInitials(name) {
    if (!name) return '?';
    return name.split(' ').filter(Boolean).map(p => p[0]).join('').toUpperCase().slice(0, 2);
}

// ── Debounce ──────────────────────────────────────────────────────────────
function debounce(fn, delay = 350) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}

// ── Counter animation ─────────────────────────────────────────────────────
function animateCounter(el, target) {
    if (!el || isNaN(target)) return;
    const step = Math.ceil(target / 40) || 1;
    let cur = 0;
    const t = setInterval(() => {
        cur = Math.min(cur + step, target);
        el.textContent = cur.toLocaleString();
        if (cur >= target) clearInterval(t);
    }, 20);
}

// ── Confirm dialog helper ─────────────────────────────────────────────────
function confirmDialog(message) {
    return new Promise(resolve => {
        const d = document.createElement('div');
        d.className = 'fixed inset-0 z-[99999] flex items-center justify-center bg-black/50 p-4';
        d.innerHTML = `
            <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-sm w-full text-center">
                <div class="w-14 h-14 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
                </div>
                <p class="text-secondary font-medium mb-1">Are you sure?</p>
                <p class="text-sm text-gray-500 mb-5">${message}</p>
                <div class="flex gap-3">
                    <button id="cfmCancel" class="flex-1 py-2 bg-neutral-dark text-secondary rounded-xl text-sm font-medium hover:bg-gray-200 transition-colors">Cancel</button>
                    <button id="cfmOk" class="flex-1 py-2 bg-red-500 hover:bg-red-600 text-white rounded-xl text-sm font-medium transition-colors">Delete</button>
                </div>
            </div>`;
        document.body.appendChild(d);
        d.querySelector('#cfmOk').onclick     = () => { d.remove(); resolve(true); };
        d.querySelector('#cfmCancel').onclick  = () => { d.remove(); resolve(false); };
    });
}

// ── Boot ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    document.querySelectorAll('#logoutButton').forEach(btn =>
        btn.addEventListener('click', e => { e.preventDefault(); logout(); })
    );
});
