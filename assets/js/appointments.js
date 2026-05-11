// Edroso Dental — Appointments page logic

let allAppts = [];
let patients = [];
let dentists = [];
/** Full dentist list from API (modal filters by procedure specialization). */
let dentistsFullList = [];
/** Services catalog for procedure dropdown (includes required_specialization). */
let allServicesForProcedures = [];
let calDate = new Date();
let selDate = new Date();
let viewingId = null;
let weekMode = false;
let listFilterTab = 'today';
/** When listFilterTab === 'day', list is filtered to this YYYY-MM-DD (local calendar; never use toISOString() for dates). */
let pickedDayYmd = null;
let tabListData = [];
/** Snapshot when opening Edit Appointment — used to require internal change reason when slot fields change. */
let editApptBaseline = null;

const CHANGE_REASON_LABELS = {
    schedule_conflict: 'Schedule / slot conflict',
    dentist_availability: 'Dentist availability',
    patient_request: 'Patient requested change',
    clinic_operations: 'Clinic operations',
    record_correction: 'Record correction',
    other: 'Other',
};

function formatChangeReasonLabel(code) {
    if (!code) {
        return '—';
    }
    return CHANGE_REASON_LABELS[code] || String(code);
}

/** Top stat cards: shared base + active / idle tail (full className set in setActiveStatCards). */
const STAT_FILTER_BASE =
    'stat-filter-card group w-full bg-white rounded-2xl p-4 shadow-sm text-center border-2 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50';
const STAT_FILTER_ACTIVE = ' border-primary bg-primary/10 ring-1 ring-primary/20 shadow-md';
const STAT_FILTER_IDLE = ' border-transparent hover:border-primary/35 hover:shadow-md';

const PROC_COLORS = {
    cleaning: 'bg-primary/20 text-primary',
    rootcanal: 'bg-accent/20 text-amber-600',
    extraction: 'bg-red-500/20 text-red-500',
    filling: 'bg-green-500/20 text-green-600',
    crown: 'bg-purple-500/20 text-purple-600',
    whitening: 'bg-blue-500/20 text-blue-600',
    consultation: 'bg-primary/20 text-primary',
    other: 'bg-gray-200 text-gray-600',
};
const APPT_HOUR_MIN = '09:00';
const APPT_HOUR_MAX = '16:30';

const SLOT_CONFLICT_MSG = 'This time slot is no longer available. Please choose another.';

const PROCEDURE_OPTIONS = [
    { value: 'Consultation|consultation|30', label: 'Consultation (30 min)' },
    { value: 'Teeth Cleaning|cleaning|30', label: 'Teeth Cleaning (30 min)' },
    { value: 'Dental Filling|filling|45', label: 'Dental Filling (45 min)' },
    { value: 'Tooth Extraction|extraction|45', label: 'Tooth Extraction (45 min)' },
    { value: 'Root Canal|rootcanal|60', label: 'Root Canal (60 min)' },
    { value: 'Dental Crown|crown|90', label: 'Dental Crown (90 min)' },
    { value: 'Teeth Whitening|whitening|60', label: 'Teeth Whitening (60 min)' },
    { value: 'Orthodontic Check|other|30', label: 'Orthodontic Check (30 min)' },
    { value: 'Oral Surgery|other|90', label: 'Oral Surgery (90 min)' },
];

function inferProcTypeFromServiceName(name) {
    const value = String(name || '').toLowerCase();
    if (/clean|prophylaxis|polish|scaling/.test(value)) return 'cleaning';
    if (/root\s*canal|endodontic/.test(value)) return 'rootcanal';
    if (/extract|extraction|wisdom/.test(value)) return 'extraction';
    if (/fill|filling|cavity|pasta/.test(value)) return 'filling';
    if (/crown|cap/.test(value)) return 'crown';
    if (/whiten|bleach|whitening/.test(value)) return 'whitening';
    return 'other';
}

function buildProcedureOptionsFromServices() {
    if (!Array.isArray(allServicesForProcedures) || !allServicesForProcedures.length) {
        return PROCEDURE_OPTIONS.slice();
    }
    return allServicesForProcedures.map((s) => {
        const type = inferProcTypeFromServiceName(s.name);
        const dur = 30;
        const spec = String(s.required_specialization || '').trim();
        return {
            value: `${s.name}|${type}|${dur}|${spec}`,
            label: `${s.name} (${dur} min)`,
        };
    });
}

function getProcedureSpecializationFromForm() {
    const raw = document.getElementById('fProcedure')?.value || '';
    const parts = String(raw).split('|');
    return parts.length >= 4 ? String(parts[3] || '').trim() : '';
}

/**
 * Prefer dentists whose specialization matches the selected service (catalog 4th field).
 * If none match (legacy data), show all active dentists so staff can still book another time slot.
 */
function getFilteredDentistsForProcedure() {
    const rawList = dentistsFullList.length ? dentistsFullList : dentists;
    const actives = rawList.filter((d) => String(d.status || 'active').toLowerCase() !== 'inactive');
    const list = actives.length ? actives : rawList;
    const spec = getProcedureSpecializationFromForm();
    if (!spec) return list;
    const specLc = spec.toLowerCase();
    const filtered = list.filter(
        (d) => String(d.specialization || '').trim().toLowerCase() === specLc
    );
    return filtered.length ? filtered : list;
}

async function loadAdminAvailabilitySlots() {
    const timeEl = document.getElementById('fTime');
    const suggestEl = document.getElementById('adminSlotSuggest');
    const dentistId = document.getElementById('fDentist')?.value;
    const date = document.getElementById('fDate')?.value;
    if (suggestEl) {
        suggestEl.textContent = '';
        suggestEl.classList.add('hidden');
    }
    if (!timeEl || timeEl.tagName !== 'SELECT') return;
    if (!dentistId || !date) {
        timeEl.innerHTML = '<option value="">Select dentist and date…</option>';
        return;
    }
    timeEl.innerHTML = '<option value="">Loading…</option>';
    try {
        const data = await api.get('availability.php', {
            dentist_id: dentistId,
            date,
            suggest_days: 1,
        });
        const slots = Array.isArray(data.slots) ? data.slots.filter((s) => s.available) : [];
        if (!slots.length) {
            if (data.dentist_day_off) {
                timeEl.innerHTML = '<option value="">Not a scheduled work day for this dentist</option>';
            } else if (data.all_slots_booked) {
                timeEl.innerHTML = '<option value="">All slots booked this day</option>';
            } else {
                timeEl.innerHTML = '<option value="">No open slots this day</option>';
            }
            const sug = Array.isArray(data.suggested_dates) ? data.suggested_dates : [];
            if (suggestEl && sug.length) {
                suggestEl.textContent =
                    'Next days with an open slot: ' + sug.map((ymd) => formatDateYMD(ymd)).join(', ');
                suggestEl.classList.remove('hidden');
            }
            return;
        }
        timeEl.innerHTML =
            '<option value="">Choose a time…</option>' +
            slots.map((s) => `<option value="${escapeHtml(s.time)}">${formatTime(s.time)}</option>`).join('');
    } catch (e) {
        timeEl.innerHTML = '<option value="">Could not load slots</option>';
    }
}

// ── YYYY-MM-DD local parse / format (avoid UTC off-by-one) ─────────────────
function parseYMD(ymd) {
    if (!ymd || typeof ymd !== 'string') return new Date();
    const parts = ymd.split('-').map(Number);
    const y = parts[0];
    const m = parts[1];
    const d = parts[2];
    return new Date(y, m - 1, d);
}

function formatYMDLocal(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function todayYMDLocal() {
    return formatYMDLocal(new Date());
}

/** Display label for a YYYY-MM-DD string without UTC shift */
function formatDateYMD(ymd) {
    if (!ymd) return '—';
    const [y, m, d] = ymd.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function escapeHtml(s) {
    if (s == null) return '';
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

/** Relative URL from admin/ for a stored portal GCash proof path (validated). */
function portalPaymentProofUrl(rel) {
    const clean = String(rel || '')
        .trim()
        .replace(/^\/*/, '')
        .replace(/\\/g, '/');
    if (
        !/^assets\/uploads\/portal_gcash\/gcash_\d+_[a-f0-9]{16}\.(jpg|jpeg|png|webp)$/i.test(clean)
    ) {
        return '';
    }
    return '../' + clean.split('/').map(encodeURIComponent).join('/');
}

function formatMultilineText(s) {
    let normalized = String(s || '');
    const labels = ['Patient:', 'Address:', 'Concerns:', 'Medical:', 'Allergies:', 'Payment:'];
    labels.forEach((label) => {
        const re = new RegExp('\\s*' + label.replace(':', '\\:') + '\\s*', 'g');
        normalized = normalized.replace(re, '\n' + label + ' ');
    });
    normalized = normalized.replace(/^\s*\n/, '').trim();
    return escapeHtml(normalized).replace(/\r\n|\r|\n/g, '<br>');
}

// ── Searchable select (one reusable component) ─────────────────────────────
function makeSearchableSelect(containerId, optionsList, onSelect) {
    const container = document.getElementById(containerId);
    if (!container) return null;
    const hiddenId = container.dataset.hiddenId;
    const placeholder = container.dataset.placeholder || 'Search…';
    const hidden = document.getElementById(hiddenId);
    if (!hidden) return null;

    container.innerHTML = '';
    container.className = 'searchable-select-wrap relative';

    const text = document.createElement('input');
    text.type = 'text';
    text.className =
        'w-full px-4 py-2.5 border border-neutral-dark rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/40';
    text.placeholder = placeholder;
    text.autocomplete = 'off';

    const dd = document.createElement('div');
    dd.className =
        'searchable-dropdown absolute left-0 right-0 z-50 mt-1 max-h-48 overflow-y-auto bg-white border border-neutral-dark rounded-xl shadow-lg hidden';

    function filterList(q) {
        const qq = (q || '').trim().toLowerCase();
        if (!qq) return optionsList.slice();
        return optionsList.filter((o) => o.label.toLowerCase().includes(qq));
    }

    function renderDd(list) {
        if (!list.length) {
            dd.innerHTML =
                '<div class="px-3 py-2 text-xs text-gray-400">No matches</div>';
            return;
        }
        dd.innerHTML = list
            .map(
                (o) =>
                    `<button type="button" class="searchable-item w-full text-left px-3 py-2 text-sm hover:bg-neutral/60 text-secondary" data-value="${encodeURIComponent(String(o.value))}">${escapeHtml(o.label)}</button>`
            )
            .join('');
        dd.querySelectorAll('.searchable-item').forEach((btn) => {
            btn.addEventListener('click', (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                let val = btn.getAttribute('data-value') || '';
                try {
                    val = decodeURIComponent(val);
                } catch (_) {}
                const opt = optionsList.find((x) => String(x.value) === val);
                hidden.value = val;
                text.value = opt ? opt.label : '';
                dd.classList.add('hidden');
                if (typeof onSelect === 'function') onSelect(val, opt);
            });
        });
    }

    function openDd() {
        document.querySelectorAll('#apptModal .searchable-dropdown').forEach((el) => {
            if (el !== dd) el.classList.add('hidden');
        });
        renderDd(filterList(text.value));
        dd.classList.remove('hidden');
    }

    text.addEventListener('focus', () => openDd());
    text.addEventListener('keyup', () => {
        renderDd(filterList(text.value));
        dd.classList.remove('hidden');
    });

    container.appendChild(text);
    container.appendChild(dd);

    function setValue(val) {
        const opt = optionsList.find((o) => String(o.value) === String(val));
        hidden.value = val != null && val !== '' ? val : '';
        text.value = opt ? opt.label : '';
        if (opt && typeof onSelect === 'function') onSelect(opt.value, opt);
    }

    container._searchableSetValue = setValue;
    return { setValue, textInput: text };
}

function setAppointmentDateMin(isNew) {
    const fDate = document.getElementById('fDate');
    if (!fDate) return;
    if (isNew) {
        fDate.min = todayYMDLocal();
    } else {
        fDate.removeAttribute('min');
    }
}

function syncProcedureFromValue(val) {
    const parts = String(val || '').split('|');
    if (parts.length >= 3) {
        document.getElementById('fProcType').value = parts[1];
        document.getElementById('fDuration').value = parts[2];
    }
}

function rebuildDentistSelectInModal(preferredDentistId) {
    const dentistOpts = getFilteredDentistsForProcedure().map((d) => ({
        value: String(d.id),
        label: d.name,
    }));
    makeSearchableSelect('dentistSelectMount', dentistOpts, (val) => {
        loadDentistBookedSlots(val);
        loadAdminAvailabilitySlots();
    });
    const dEl = document.getElementById('dentistSelectMount');
    const pid = String(preferredDentistId || '');
    const keep = pid && dentistOpts.some((o) => o.value === pid);
    if (dEl && dEl._searchableSetValue) {
        dEl._searchableSetValue(keep ? pid : '');
    }
    if (!keep) {
        const tEl = document.getElementById('fTime');
        if (tEl) tEl.innerHTML = '<option value="">Select dentist and date…</option>';
    }
    loadAdminAvailabilitySlots();
}

function buildModalSearchableSelects(procedureOpts) {
    const procList = procedureOpts || buildProcedureOptionsFromServices();
    const patientOpts = patients.map((p) => ({
        value: String(p.id),
        label: `${p.first_name} ${p.last_name}`,
    }));
    makeSearchableSelect('patientSelectMount', patientOpts, () => {});

    const dentistOpts = getFilteredDentistsForProcedure().map((d) => ({
        value: String(d.id),
        label: d.name,
    }));
    makeSearchableSelect('dentistSelectMount', dentistOpts, (val) => {
        loadDentistBookedSlots(val);
        loadAdminAvailabilitySlots();
    });

    makeSearchableSelect('procedureSelectMount', procList, (val) => {
        syncProcedureFromValue(val);
        const prevD = document.getElementById('fDentist')?.value || '';
        rebuildDentistSelectInModal(prevD);
    });
}

async function loadDentistBookedSlots(dentistId) {
    const wrap = document.getElementById('dentistBookedSlots');
    const list = document.getElementById('dentistBookedSlotsList');
    if (!wrap || !list) return;
    if (!dentistId) {
        wrap.classList.add('hidden');
        list.innerHTML = '';
        return;
    }
    try {
        const rows = await api.get('appointments.php', {
            dentist_id: dentistId,
            status: 'scheduled',
        });
        const today = todayYMDLocal();
        const upcoming = (rows || []).filter((a) => (a.appointment_date || '') >= today);
        upcoming.sort((a, b) => {
            const dc = (a.appointment_date || '').localeCompare(b.appointment_date || '');
            if (dc !== 0) return dc;
            return (a.appointment_time || '').localeCompare(b.appointment_time || '');
        });
        if (!upcoming.length) {
            list.innerHTML =
                '<li class="text-gray-400">No upcoming scheduled slots for this dentist.</li>';
        } else {
            list.innerHTML = upcoming
                .map((a) => {
                    const dt = formatDateYMD(a.appointment_date);
                    const tm = formatTime(a.appointment_time);
                    return `<li class="flex flex-wrap gap-x-2 border-b border-neutral-dark/50 pb-1 last:border-0"><span class="text-secondary font-medium">${escapeHtml(dt)}</span><span>${escapeHtml(tm)}</span><span class="text-gray-500">— ${escapeHtml(a.patient_name || '—')}</span><span class="text-gray-400">(${escapeHtml(a.procedure_name || '')})</span></li>`;
                })
                .join('');
        }
        wrap.classList.remove('hidden');
    } catch (e) {
        list.innerHTML = '<li class="text-red-500">Could not load schedule.</li>';
        wrap.classList.remove('hidden');
    }
}

function setModalSelectValues(patientId, dentistId, procedureVal) {
    const pEl = document.getElementById('patientSelectMount');
    const dEl = document.getElementById('dentistSelectMount');
    const prEl = document.getElementById('procedureSelectMount');
    if (pEl && pEl._searchableSetValue) pEl._searchableSetValue(String(patientId || ''));
    if (dEl && dEl._searchableSetValue) dEl._searchableSetValue(String(dentistId || ''));
    if (prEl && prEl._searchableSetValue) prEl._searchableSetValue(procedureVal || '');
}

function clearApptConflictError() {
    const el = document.getElementById('apptConflictError');
    if (!el) return;
    el.textContent = '';
    el.classList.add('hidden');
}

function showApptConflictError() {
    const el = document.getElementById('apptConflictError');
    if (!el) return;
    el.textContent = SLOT_CONFLICT_MSG;
    el.classList.remove('hidden');
}

async function loadTabCounts() {
    try {
        const c = await api.get('appointments.php', { count_by_status: 1 });
        const map = [
            ['statToday', c.today],
            ['statUpcoming', c.upcoming],
            ['statCompleted', c.completed],
            ['statCancelled', c.cancelled],
        ];
        map.forEach(([id, n]) => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(Number(n));
        });
    } catch (e) {
        console.error(e);
    }
}

function setActiveStatCards() {
    const cards = [
        ['statCardToday', 'today'],
        ['statCardUpcoming', 'upcoming'],
        ['statCardCompleted', 'completed'],
        ['statCardCancelled', 'cancelled'],
    ];
    cards.forEach(([id, key]) => {
        const btn = document.getElementById(id);
        if (!btn) return;
        const active = listFilterTab === key;
        btn.className = STAT_FILTER_BASE + (active ? STAT_FILTER_ACTIVE : STAT_FILTER_IDLE);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

/** Query params shared by list fetches (server applies dentist + search so filters match the table). */
function appendListQueryParams(params) {
    const dFil = document.getElementById('dentistFilter');
    const df = dFil && dFil.value ? String(dFil.value).trim() : '';
    if (df) {
        params.dentist_id = df;
    }
    const searchEl = document.getElementById('searchInput');
    const q = searchEl && searchEl.value ? String(searchEl.value).trim() : '';
    if (q) {
        params.search = q;
    }
}

function updateListTitleForTab() {
    const titles = {
        today: "Today's Appointments",
        upcoming: 'Upcoming appointments',
        completed: 'Completed appointments',
        cancelled: 'Cancelled appointments',
    };
    const badge = {
        today: 'Today',
        upcoming: 'Upcoming',
        completed: 'Completed',
        cancelled: 'Cancelled',
    };
    document.getElementById('listTitle').textContent = titles[listFilterTab] || 'Appointments';
    document.getElementById('listBadge').textContent = badge[listFilterTab] || '—';
}

async function loadTabList() {
    if (weekMode) {
        renderWeekView();
        return;
    }
    document.getElementById('apptTable').innerHTML =
        '<tr><td colspan="6" class="px-5 py-10 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-primary text-xl mb-2 block"></i>Loading…</td></tr>';
    try {
        if (listFilterTab === 'day' && pickedDayYmd) {
            const dayParams = { date: pickedDayYmd };
            appendListQueryParams(dayParams);
            tabListData = await api.get('appointments.php', dayParams);
            const todayStr = todayYMDLocal();
            if (pickedDayYmd === todayStr) {
                document.getElementById('listTitle').textContent = "Today's Appointments";
                document.getElementById('listBadge').textContent = 'Today';
            } else {
                document.getElementById('listTitle').textContent = `Appointments — ${formatDateYMD(pickedDayYmd)}`;
                document.getElementById('listBadge').textContent = formatDateYMD(pickedDayYmd);
            }
            setActiveStatCards();
            renderApptTable(tabListData);
            return;
        }
        const listParams = { filter: listFilterTab };
        appendListQueryParams(listParams);
        tabListData = await api.get('appointments.php', listParams);
        updateListTitleForTab();
        setActiveStatCards();
        renderApptTable(tabListData);
    } catch (e) {
        showToast('Failed to load appointments: ' + e.message, 'error');
        document.getElementById('apptTable').innerHTML =
            '<tr><td colspan="6" class="px-5 py-10 text-center text-red-500">Could not load list.</td></tr>';
    }
}

// ── Init ──────────────────────────────────────────────────────────────────
async function init() {
    await checkAuth();
    await Promise.all([loadFormData(), loadAllAppts()]);
    await loadTabCounts();
    setView('list');
    await goToToday();
}

async function loadFormData() {
    const [patRes, dentRes, svcWrap] = await Promise.all([
        api.get('patients.php'),
        api.get('dentists.php'),
        api.get('services.php', { scope: 'catalog' }).catch(() => ({ services: [] })),
    ]);
    patients = Array.isArray(patRes) ? patRes : [];
    let dentList = Array.isArray(dentRes) ? dentRes : [];
    if (!dentList.length && dentRes && typeof dentRes === 'object' && Array.isArray(dentRes.dentists)) {
        dentList = dentRes.dentists;
    }
    dentistsFullList = dentList;
    dentists = dentistsFullList;
    allServicesForProcedures = Array.isArray(svcWrap.services) ? svcWrap.services : [];
    const dFil = document.getElementById('dentistFilter');
    if (dFil) {
        const dentOpts = dentists.map((d) => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');
        dFil.innerHTML = '<option value="">All Dentists</option>' + dentOpts;
    }
}

async function loadAllAppts() {
    try {
        allAppts = await api.get('appointments.php');
        updateStats();
    } catch (e) {
        showToast('Failed to load appointments: ' + e.message, 'error');
    }
}

document.querySelectorAll('#statFilterStrip .stat-filter-card').forEach((btn) => {
    btn.addEventListener('click', async () => {
        const f = btn.getAttribute('data-filter');
        if (!f || weekMode) return;
        pickedDayYmd = null;
        listFilterTab = f;
        setActiveStatCards();
        await loadTabList();
    });
});

// ── Stats ─────────────────────────────────────────────────────────────────
function updateStats() {
    const todayStr = todayYMDLocal();
    document.getElementById('statToday').textContent = allAppts.filter((a) => a.appointment_date === todayStr).length;
    document.getElementById('statUpcoming').textContent = allAppts.filter(
        (a) => a.appointment_date >= todayStr && !['Cancelled', 'Completed'].includes(a.status)
    ).length;
    document.getElementById('statCompleted').textContent = allAppts.filter((a) => a.status === 'Completed').length;
    document.getElementById('statCancelled').textContent = allAppts.filter((a) => a.status === 'Cancelled').length;
}

// ── Views ─────────────────────────────────────────────────────────────────
function setView(view) {
    const cal = document.getElementById('calendarView');
    const list = document.getElementById('listView');
    const calBtn = document.getElementById('calViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    if (view === 'calendar') {
        cal.classList.remove('hidden');
        list.classList.add('hidden');
        calBtn.className = 'px-3.5 py-2 text-sm bg-primary text-white';
        listBtn.className = 'px-3.5 py-2 text-sm hover:bg-neutral-dark text-secondary transition-colors';
    } else {
        list.classList.remove('hidden');
        cal.classList.add('hidden');
        listBtn.className = 'px-3.5 py-2 text-sm bg-primary text-white';
        calBtn.className = 'px-3.5 py-2 text-sm hover:bg-neutral-dark text-secondary transition-colors';
    }
}
document.getElementById('calViewBtn').addEventListener('click', () => {
    setView('calendar');
    renderCalendar();
});
document.getElementById('listViewBtn').addEventListener('click', () => {
    setView('list');
    if (!weekMode) loadTabList();
});

// ── Calendar ──────────────────────────────────────────────────────────────
function renderCalendar() {
    const yr = calDate.getFullYear();
    const mo = calDate.getMonth();
    const todayStr = todayYMDLocal();
    document.getElementById('monthDisplay').textContent = calDate.toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
    });

    const firstDow = new Date(yr, mo, 1).getDay();
    const daysInMo = new Date(yr, mo + 1, 0).getDate();
    const prevDays = new Date(yr, mo, 0).getDate();

    let html = '';
    for (let i = firstDow - 1; i >= 0; i--) {
        html += `<div class="cal-cell offmonth border-r border-b border-neutral-dark p-2">
            <span class="text-xs text-gray-300">${prevDays - i}</span></div>`;
    }
    for (let d = 1; d <= daysInMo; d++) {
        const ds = `${yr}-${String(mo + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const dayAppts = allAppts.filter((a) => a.appointment_date === ds);
        const isToday = ds === todayStr;
        const chips = dayAppts
            .slice(0, 3)
            .map(
                (a) =>
                    `<div class="appt-chip ${PROC_COLORS[a.procedure_type] || 'bg-gray-200 text-gray-600'}">${(a.patient_name || '?').split(' ')[0]}</div>`
            )
            .join('');
        const more =
            dayAppts.length > 3 ? `<div class="text-[9px] text-gray-400 pl-1">+${dayAppts.length - 3} more</div>` : '';
        html += `<div class="cal-cell border-r border-b border-neutral-dark p-2 ${isToday ? 'today' : ''}" onclick="window.__selectCalDay('${ds}')">
            <span class="text-xs font-semibold ${isToday ? 'text-primary' : 'text-gray-600'}">${d}</span>
            <div class="mt-1">${chips}${more}</div>
        </div>`;
    }
    const total = firstDow + daysInMo;
    const trailing = total % 7 === 0 ? 0 : 7 - (total % 7);
    for (let d = 1; d <= trailing; d++) {
        html += `<div class="cal-cell offmonth border-r border-b border-neutral-dark p-2">
            <span class="text-xs text-gray-300">${d}</span></div>`;
    }
    document.getElementById('calGrid').innerHTML = html;
}

window.__selectCalDay = function (ds) {
    // `ds` is already YYYY-MM-DD in local calendar — keep as string; do not use Date#toISOString() (UTC shifts the day).
    selDate = parseYMD(ds);
    pickedDayYmd = ds;
    listFilterTab = 'day';
    weekMode = false;
    updateWeekBtnStyle();
    toggleDayWeekList();
    loadTabList();
    setView('list');
};

// ── Monday–Sunday week containing `d` ─────────────────────────────────────
function getMonday(d) {
    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    const mon = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    mon.setDate(mon.getDate() + diff);
    mon.setHours(0, 0, 0, 0);
    return mon;
}

function toggleDayWeekList() {
    const dayWrap = document.getElementById('dayListWrap');
    const weekWrap = document.getElementById('weekListWrap');
    const statStrip = document.getElementById('statFilterStrip');
    if (!dayWrap || !weekWrap) return;
    if (weekMode) {
        dayWrap.classList.add('hidden');
        weekWrap.classList.remove('hidden');
        if (statStrip) statStrip.classList.add('hidden');
    } else {
        dayWrap.classList.remove('hidden');
        weekWrap.classList.add('hidden');
        if (statStrip) statStrip.classList.remove('hidden');
    }
}

function updateWeekBtnStyle() {
    const btn = document.getElementById('weekBtn');
    if (!btn) return;
    if (weekMode) {
        btn.className =
            'px-4 py-2.5 bg-primary text-white border border-primary rounded-xl text-sm font-medium transition-colors';
    } else {
        btn.className =
            'px-4 py-2.5 bg-white border border-neutral-dark rounded-xl text-sm font-medium text-secondary hover:bg-neutral-dark transition-colors';
    }
}

async function renderWeekView() {
    const weekWrap = document.getElementById('weekListWrap');
    if (!weekWrap) return;
    weekWrap.innerHTML =
        '<div class="px-5 py-10 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-primary text-xl mb-2 block"></i>Loading week…</div>';

    const mon = getMonday(selDate);
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const cols = [];

    for (let i = 0; i < 7; i++) {
        const dt = new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() + i);
        const ds = formatYMDLocal(dt);
        cols.push({ ds, dt, label: `${dayNames[dt.getDay()]} ${dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })}` });
    }

    try {
        const results = await Promise.all(cols.map((c) => api.get('appointments.php', { date: c.ds })));
        const dFilter = document.getElementById('dentistFilter').value;
        const q = document.getElementById('searchInput').value.toLowerCase();

        let html = '<div class="grid grid-cols-7 gap-2 min-h-[320px]">';
        cols.forEach((c, idx) => {
            let data = results[idx] || [];
            if (dFilter) data = data.filter((a) => String(a.dentist_id) === dFilter);
            if (q) {
                data = data.filter(
                    (a) =>
                        (a.patient_name || '').toLowerCase().includes(q) ||
                        (a.procedure_name || '').toLowerCase().includes(q) ||
                        (a.dentist_name || '').toLowerCase().includes(q)
                );
            }
            data = [...data].sort((a, b) => (a.appointment_time || '').localeCompare(b.appointment_time || ''));

            const chips = data
                .map(
                    (a) => `
                <div class="text-xs p-2 rounded-lg border border-neutral-dark bg-white hover:bg-neutral/40 cursor-pointer"
                     onclick="viewAppt(${a.id})">
                    <p class="font-semibold text-secondary">${formatTime(a.appointment_time)}</p>
                    <p class="text-gray-600 truncate">${escapeHtml(a.patient_name || '')}</p>
                    <p class="text-gray-400 truncate text-[10px]">${escapeHtml(a.procedure_name || '')}</p>
                </div>`
                )
                .join('');

            html += `
            <div class="flex flex-col border border-neutral-dark rounded-xl overflow-hidden bg-neutral/20 min-w-0">
                <div class="bg-neutral/60 px-2 py-2 text-[11px] font-semibold text-secondary text-center border-b border-neutral-dark">${escapeHtml(c.label)}</div>
                <div class="p-2 space-y-2 flex-1 overflow-y-auto max-h-[480px]">
                    ${data.length ? chips : '<p class="text-xs text-gray-400 text-center py-4">No appointments</p>'}
                </div>
            </div>`;
        });
        html += '</div>';
        weekWrap.innerHTML = html;

        const monLbl = cols[0].dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
        const sunLbl = cols[6].dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
        document.getElementById('listTitle').textContent = `Week of ${monLbl} – ${sunLbl}`;
        document.getElementById('listBadge').textContent = 'Week view';
    } catch (e) {
        weekWrap.innerHTML = `<div class="px-5 py-10 text-center text-red-500">${escapeHtml(e.message)}</div>`;
    }
}

// ── List (filter tabs) ────────────────────────────────────────────────────

function renderApptTable(source) {
    let data = Array.isArray(source) ? source : [];
    data = [...data].sort((a, b) => {
        const dc = (a.appointment_date || '').localeCompare(b.appointment_date || '');
        if (dc !== 0) return dc;
        return (a.appointment_time || '').localeCompare(b.appointment_time || '');
    });

    if (data.length === 0) {
        const emptyByFilter = {
            today: 'No appointments for today in this view.',
            upcoming: 'No upcoming appointments match this view.',
            completed: 'No completed appointments match this view.',
            cancelled: 'No cancelled appointments match this view.',
            day: 'No appointments for this day.',
        };
        const msg = emptyByFilter[listFilterTab] || 'No appointments for this view.';
        document.getElementById('apptTable').innerHTML =
            `<tr><td colspan="6" class="px-5 py-12 text-center text-gray-400">
                <i class="fas fa-calendar-times text-3xl mb-3 block text-gray-200"></i>
                ${msg}
             </td></tr>`;
        return;
    }
    document.getElementById('apptTable').innerHTML = data
        .map(
            (a) => `
        <tr class="hover:bg-neutral/40 transition-colors ${a.status === 'In Progress' ? 'border-l-4 border-primary' : ''}">
            <td class="px-5 py-3.5">
                <p class="text-sm font-semibold ${a.status === 'In Progress' ? 'text-primary' : 'text-secondary'}">${formatDate(a.appointment_date)} <span class="text-gray-400 font-normal">·</span> ${formatTime(a.appointment_time)}</p>
                <p class="text-xs text-gray-400">${a.duration_minutes || 30} min</p>
            </td>
            <td class="px-5 py-3.5">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-full bg-primary/20 text-primary flex items-center justify-center text-xs font-bold flex-shrink-0">${getInitials(a.patient_name)}</div>
                    <div>
                        <p class="text-sm font-semibold text-secondary">${a.patient_name || '—'}</p>
                        <p class="text-xs text-gray-400">${a.patient_number || ''}</p>
                    </div>
                </div>
            </td>
            <td class="px-5 py-3.5">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-primary flex-shrink-0 opacity-80"></span>
                    <span class="text-sm text-secondary">${escapeHtml(a.procedure_name)}</span>
                </div>
            </td>
            <td class="px-5 py-3.5 text-sm text-secondary">${a.dentist_name || '—'}</td>
            <td class="px-5 py-3.5">
                <span class="px-2.5 py-1 text-xs rounded-full font-semibold ${getStatusClass(a.status)}">${escapeHtml(String(a.status || '—').trim())}</span>
            </td>
            <td class="px-5 py-3.5 text-right">
                <div class="flex justify-end gap-1.5">
                    <button onclick="viewAppt(${a.id})" class="p-1.5 bg-primary/10 text-primary rounded-lg hover:bg-primary/25 transition-colors" title="View"><i class="fas fa-eye text-xs"></i></button>
                    <button onclick="editAppt(${a.id})" class="p-1.5 bg-accent/10 text-accent rounded-lg hover:bg-accent/25 transition-colors" title="Edit"><i class="fas fa-edit text-xs"></i></button>
                    <button onclick="deleteAppt(${a.id})" class="p-1.5 bg-red-50 text-red-400 rounded-lg hover:bg-red-100 transition-colors" title="Delete"><i class="fas fa-trash text-xs"></i></button>
                </div>
            </td>
        </tr>`
        )
        .join('');
}

// ── View details ──────────────────────────────────────────────────────────
async function viewAppt(id) {
    try {
        const a = await api.get('appointments.php', { id });
        viewingId = id;
        const paySt = a.payment_status ? escapeHtml(String(a.payment_status)) : '—';
        const spec = a.dentist_specialization ? escapeHtml(String(a.dentist_specialization)) : '—';
        const proofUrl = portalPaymentProofUrl(a.payment_proof_path);
        const proofBlock =
            proofUrl !== ''
                ? `<div class="mt-2 p-2 sm:p-2.5 rounded-lg border border-neutral-dark/70 bg-white">
                <p class="text-[11px] font-semibold text-gray-500 mb-1.5">Payment proof (patient upload)</p>
                <a href="${escapeHtml(proofUrl)}" target="_blank" rel="noopener noreferrer" class="block text-center">
                    <img src="${escapeHtml(proofUrl)}" alt="GCash payment proof" class="max-h-52 sm:max-h-64 w-auto max-w-full rounded-md border border-neutral-dark mx-auto object-contain bg-neutral" loading="lazy" decoding="async" />
                </a>
                <p class="text-[10px] text-gray-500 mt-1.5 text-center">Open in new tab to zoom for verification.</p>
            </div>`
                : '';
        document.getElementById('detailContent').innerHTML = `
            <div class="grid grid-cols-2 gap-2">
                <div class="p-2 sm:p-2.5 bg-neutral rounded-lg"><p class="text-[11px] text-gray-400 mb-0.5">Patient</p><p class="text-sm font-semibold text-secondary leading-snug">${escapeHtml(a.patient_name)}</p></div>
                <div class="p-2 sm:p-2.5 bg-neutral rounded-lg"><p class="text-[11px] text-gray-400 mb-0.5">Procedure</p><p class="text-sm font-semibold text-secondary leading-snug">${escapeHtml(a.procedure_name)}</p></div>
                <div class="p-2 sm:p-2.5 bg-neutral rounded-lg"><p class="text-[11px] text-gray-400 mb-0.5">Date</p><p class="text-sm font-semibold text-secondary">${formatDateYMD(a.appointment_date)}</p></div>
                <div class="p-2 sm:p-2.5 bg-neutral rounded-lg"><p class="text-[11px] text-gray-400 mb-0.5">Time</p><p class="text-sm font-semibold text-secondary">${formatTime(a.appointment_time)} · ${a.duration_minutes} min</p></div>
                <div class="p-2 sm:p-2.5 bg-neutral rounded-lg"><p class="text-[11px] text-gray-400 mb-0.5">Dentist</p><p class="text-sm font-semibold text-secondary leading-snug">${escapeHtml(a.dentist_name)}</p></div>
                <div class="p-2 sm:p-2.5 bg-neutral rounded-lg"><p class="text-[11px] text-gray-400 mb-0.5">Specialization</p><p class="text-sm font-semibold text-secondary leading-snug">${spec}</p></div>
                <div class="p-2 sm:p-2.5 bg-neutral rounded-lg col-span-2"><p class="text-[11px] text-gray-400 mb-0.5">Payment</p><p class="text-sm font-semibold text-secondary">${paySt}</p></div>
            </div>
            ${proofBlock}
            ${a.notes ? `<div class="mt-2 p-2 sm:p-2.5 bg-neutral rounded-lg"><p class="text-[11px] text-gray-400 mb-1">Notes</p><div class="text-xs sm:text-sm text-secondary leading-relaxed">${formatMultilineText(a.notes)}</div></div>` : ''}
            ${
                a.internal_change_reason
                    ? `<div class="mt-2 p-2 sm:p-2.5 rounded-lg border border-amber-200 bg-amber-50">
                        <p class="text-[11px] font-semibold text-amber-900 mb-0.5">Last schedule change (internal)</p>
                        <p class="text-xs sm:text-sm text-amber-950">${escapeHtml(formatChangeReasonLabel(a.internal_change_reason))}</p>
                        ${
                            a.slot_modified_at
                                ? `<p class="text-[11px] text-amber-800 mt-0.5">${escapeHtml(String(a.slot_modified_at))}</p>`
                                : ''
                        }
                    </div>`
                    : ''
            }
        `;

        const statuses = ['Scheduled', 'Confirmed', 'In Progress', 'Completed', 'Cancelled'];
        document.getElementById('statusButtons').innerHTML = statuses
            .map(
                (s) =>
                    `<button onclick="quickStatus(${id},'${s}')" class="px-3 py-1.5 text-xs rounded-lg font-medium transition-colors ${a.status === s ? getStatusClass(s) + ' ring-2 ring-offset-1 ring-current' : 'bg-white border border-neutral-dark text-secondary hover:bg-neutral-dark'}">${s}</button>`
            )
            .join('');
        openModal('detailModal');
    } catch (e) {
        showToast('Error loading details: ' + e.message, 'error');
    }
}

async function quickStatus(id, status) {
    try {
        await api.put('appointments.php', { status }, { id });
        showToast(`Status updated to "${status}".`);
        await loadAllAppts();
        await loadTabCounts();
        await loadTabList();
        renderCalendar();
        if (document.getElementById('detailModal') && !document.getElementById('detailModal').classList.contains('hidden')) {
            await viewAppt(id);
        } else {
            closeModal('detailModal');
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    }
}

document.getElementById('detailDelete').addEventListener('click', () => {
    if (viewingId) deleteAppt(viewingId, true);
});
document.getElementById('detailEdit').addEventListener('click', () => {
    closeModal('detailModal');
    if (viewingId) editAppt(viewingId);
});

// ── CRUD ──────────────────────────────────────────────────────────────────
document.getElementById('newApptBtn').addEventListener('click', async () => {
    clearApptConflictError();
    document.getElementById('apptModalTitle').textContent = 'New Appointment';
    document.getElementById('apptForm').reset();
    document.getElementById('apptId').value = '';
    document.getElementById('fTime').value = '09:00';
    document.getElementById('fDuration').value = '30';
    document.getElementById('fProcType').value = 'other';
    await loadFormData();
    if (!dentistsFullList.length) {
        showToast('No dentists loaded — check Network → dentists.php or add dentists in Admin.', 'error');
    }
    buildModalSearchableSelects();
    document.getElementById('fDate').value = formatYMDLocal(selDate);
    setAppointmentDateMin(true);
    const firstProc = buildProcedureOptionsFromServices()[0];
    setModalSelectValues('', '', (firstProc && firstProc.value) || PROCEDURE_OPTIONS[0].value);
    const crwNew = document.getElementById('apptChangeReasonWrap');
    if (crwNew) {
        crwNew.classList.add('hidden');
    }
    const fcrNew = document.getElementById('fChangeReason');
    if (fcrNew) {
        fcrNew.value = '';
    }
    editApptBaseline = null;
    openModal('apptModal');
    loadDentistBookedSlots('');
    loadAdminAvailabilitySlots();
});

async function editAppt(id) {
    try {
        const a = await api.get('appointments.php', { id });
        await loadFormData();

        let procOpts = buildProcedureOptionsFromServices();
        let procVal = '';
        for (const opt of procOpts) {
            if (opt.value.startsWith(a.procedure_name + '|')) {
                procVal = opt.value;
                break;
            }
        }
        if (!procVal) {
            procVal = `${a.procedure_name}|${a.procedure_type || 'other'}|${a.duration_minutes || 30}|`;
            procOpts = [{ value: procVal, label: a.procedure_name }, ...procOpts];
        }

        buildModalSearchableSelects(procOpts);

        document.getElementById('apptModalTitle').textContent = 'Edit Appointment';
        document.getElementById('apptId').value = a.id;

        setModalSelectValues(a.patient_id, a.dentist_id, procVal);
        const fRoomEl = document.getElementById('fRoom');
        if (fRoomEl) fRoomEl.value = a.room || '';
        document.getElementById('fProcType').value = a.procedure_type;
        document.getElementById('fDate').value = a.appointment_date;
        await loadAdminAvailabilitySlots();
        const tslice = (a.appointment_time || '').slice(0, 5);
        const tsel = document.getElementById('fTime');
        if (tsel && tsel.querySelector(`option[value="${tslice}"]`)) {
            tsel.value = tslice;
        } else if (tsel) {
            const o = document.createElement('option');
            o.value = tslice;
            o.textContent = formatTime(a.appointment_time);
            tsel.appendChild(o);
            tsel.value = tslice;
        }
        document.getElementById('fDuration').value = a.duration_minutes;
        document.getElementById('fStatus').value = a.status;
        document.getElementById('fNotes').value = a.notes || '';
        clearApptConflictError();
        setAppointmentDateMin(false);
        const crw = document.getElementById('apptChangeReasonWrap');
        if (crw) {
            crw.classList.remove('hidden');
        }
        const fcr = document.getElementById('fChangeReason');
        if (fcr) {
            fcr.value = '';
        }
        editApptBaseline = {
            patient_id: String(a.patient_id ?? ''),
            dentist_id: String(a.dentist_id ?? ''),
            appointment_date: String(a.appointment_date || ''),
            appointment_time: (a.appointment_time || '').slice(0, 5),
            procedure_name: String(a.procedure_name || '').trim(),
        };
        openModal('apptModal');
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    }
}

document.getElementById('apptForm').addEventListener('input', clearApptConflictError);
document.getElementById('apptForm').addEventListener('change', clearApptConflictError);
const fDateField = document.getElementById('fDate');
if (fDateField) {
    fDateField.addEventListener('change', () => loadAdminAvailabilitySlots());
}

document.getElementById('apptForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    clearApptConflictError();
    const id = document.getElementById('apptId').value;
    const raw = document.getElementById('fProcedure').value;
    const parts = raw.split('|');
    const body = {
        patient_id: document.getElementById('fPatient').value,
        dentist_id: document.getElementById('fDentist').value,
        procedure_name: parts[0] || raw,
        procedure_type: document.getElementById('fProcType').value || 'other',
        room: document.getElementById('fRoom').value,
        appointment_date: document.getElementById('fDate').value,
        appointment_time: document.getElementById('fTime').value,
        duration_minutes: document.getElementById('fDuration').value,
        status: document.getElementById('fStatus').value,
        notes: document.getElementById('fNotes').value,
    };
    const btn = document.getElementById('saveApptBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i> Saving…';

    if (id && editApptBaseline) {
        const procName = String(parts[0] || raw || '').trim();
        const slotChanged =
            String(editApptBaseline.appointment_date) !== String(body.appointment_date) ||
            String(editApptBaseline.appointment_time) !== String(body.appointment_time) ||
            String(editApptBaseline.dentist_id) !== String(body.dentist_id) ||
            String(editApptBaseline.patient_id) !== String(body.patient_id) ||
            String(editApptBaseline.procedure_name).trim().toLowerCase() !== procName.toLowerCase();
        if (slotChanged) {
            const crEl = document.getElementById('fChangeReason');
            const code = crEl && crEl.value ? String(crEl.value).trim() : '';
            if (!code) {
                showToast(
                    'Select a reason for this schedule change. It is saved for clinic records only (patients do not see it).',
                    'error'
                );
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save mr-1.5"></i> Save Appointment';
                return;
            }
            body.change_reason = code;
        }
    }

    if (!body.appointment_time) {
        showToast('Please choose an available time slot.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save mr-1.5"></i> Save Appointment';
        return;
    }
    if (body.appointment_time < APPT_HOUR_MIN || body.appointment_time > APPT_HOUR_MAX) {
        showToast(`Appointment time must be between ${APPT_HOUR_MIN} and ${APPT_HOUR_MAX}.`, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save mr-1.5"></i> Save Appointment';
        return;
    }

    try {
        const conflictParams = {
            check_conflict: 1,
            dentist_id: body.dentist_id,
            date: body.appointment_date,
            time: body.appointment_time,
        };
        if (id) conflictParams.exclude_id = id;
        const chk = await api.get('appointments.php', conflictParams);
        if (chk.conflict) {
            showApptConflictError();
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save mr-1.5"></i> Save Appointment';
            return;
        }

        if (id) {
            await api.put('appointments.php', body, { id });
            showToast('Appointment updated!');
        } else {
            await api.post('appointments.php', body);
            showToast('Appointment created!');
        }
        closeModal('apptModal');
        await loadAllAppts();
        await loadTabCounts();
        await loadTabList();
        renderCalendar();
    } catch (err) {
        const msg = String(err.message || '');
        if (msg.includes(SLOT_CONFLICT_MSG)) {
            showApptConflictError();
        }
        showToast('Error: ' + msg, 'error');
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save mr-1.5"></i> Save Appointment';
});

async function deleteAppt(id, fromModal = false) {
    const ok = await confirmDialog('This appointment will be permanently deleted.');
    if (!ok) return;
    try {
        await api.delete('appointments.php', { id });
        if (fromModal) closeModal('detailModal');
        showToast('Appointment deleted.');
        await loadAllAppts();
        await loadTabCounts();
        await loadTabList();
        renderCalendar();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    }
}

// ── Navigation ────────────────────────────────────────────────────────────
async function goToToday() {
    weekMode = false;
    pickedDayYmd = null;
    listFilterTab = 'today';
    setActiveStatCards();
    updateWeekBtnStyle();
    toggleDayWeekList();
    selDate = new Date();
    calDate = new Date();
    renderCalendar();
    await loadTabList();
    document.getElementById('monthDisplay').textContent = calDate.toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
    });
}

document.getElementById('todayBtn').addEventListener('click', () => {
    goToToday();
});

document.getElementById('weekBtn').addEventListener('click', () => {
    weekMode = true;
    pickedDayYmd = null;
    listFilterTab = 'today';
    updateWeekBtnStyle();
    toggleDayWeekList();
    setView('list');
    renderWeekView();
});

document.getElementById('prevMonth').addEventListener('click', () => {
    calDate.setMonth(calDate.getMonth() - 1);
    renderCalendar();
    document.getElementById('monthDisplay').textContent = calDate.toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
    });
});
document.getElementById('nextMonth').addEventListener('click', () => {
    calDate.setMonth(calDate.getMonth() + 1);
    renderCalendar();
    document.getElementById('monthDisplay').textContent = calDate.toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
    });
});
document.getElementById('dentistFilter').addEventListener('change', () => {
    if (weekMode) renderWeekView();
    else loadTabList();
});
document.getElementById('searchInput').addEventListener(
    'input',
    debounce(() => {
        if (weekMode) renderWeekView();
        else loadTabList();
    }, 300)
);

// Close searchable dropdowns on outside click (single listener)
document.addEventListener('click', (e) => {
    if (!e.target.closest('.searchable-select-wrap')) {
        document.querySelectorAll('#apptModal .searchable-dropdown').forEach((el) => el.classList.add('hidden'));
    }
});

init();
