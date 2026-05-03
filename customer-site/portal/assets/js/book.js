(function () {
    const AUTH_API = '../../api/patient_auth.php';
    const APPT_API = '../../api/patient_appointments.php';
    const AVAILABILITY_API = '../../api/availability.php';
    const DENTISTS_API = '../../api/dentists.php?scope=booking';
    const SERVICES_API = '../../api/services.php?scope=catalog';
    const PORTAL_OPTIONS_API = '../../api/portal_options.php';
    const PHONE_RE = /^\+639\d{9}$/;
    let assignedDentistName = 'Our dental team';

    function showError(msg) {
        var se = document.getElementById('submitError');
        se.textContent = msg;
        se.classList.remove('hidden');
    }

    let portalUserId = null;
    let portalUserName = '';
    let portalEmail = '';
    let portalPhone = '';
    let portalDob = '';
    let portalFirstName = '';
    let portalLastName = '';
    let csrfToken = '';
    let wizardStep = 1;

    function setWizardStep(n) {
        wizardStep = n;
        var i;
        for (i = 1; i <= 6; i++) {
            var panel = document.getElementById('wizardStep' + i);
            if (panel) {
                panel.classList.toggle('is-active', i === n);
            }
        }
        var items = document.querySelectorAll('#bookingStepper .stepper__item');
        Array.prototype.forEach.call(items, function (li, idx) {
            var stepNum = idx + 1;
            li.classList.remove('is-active', 'is-done');
            if (stepNum < n) {
                li.classList.add('is-done');
            } else if (stepNum === n) {
                li.classList.add('is-active');
            }
        });
        var wizBack = document.getElementById('wizBack');
        if (wizBack) {
            wizBack.classList.toggle('hidden', n <= 1);
        }
        var wizNext = document.getElementById('wizNext');
        var btnSub = document.getElementById('btnSubmit');
        if (wizNext && btnSub) {
            if (n === 6) {
                wizNext.classList.add('hidden');
                btnSub.classList.remove('hidden');
                refreshConfirmSummary();
            } else {
                wizNext.classList.remove('hidden');
                btnSub.classList.add('hidden');
            }
        }
        syncWizardNextState();
        updateLeftSummary();
        if (n === 3 && selectedDateStr && getDentistIdForSlots()) {
            loadSlotsForDate(selectedDateStr);
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function canAdvanceFromStep4() {
        if (!document.getElementById('first_name').value.trim()) {
            return false;
        }
        if (!document.getElementById('last_name').value.trim()) {
            return false;
        }
        if (!document.getElementById('email').value.trim()) {
            return false;
        }
        var phone = document.getElementById('phone').value.replace(/\s/g, '');
        if (!PHONE_RE.test(phone)) {
            return false;
        }
        var age = parseInt(document.getElementById('age').value, 10);
        if (!age || age < 1 || age > 120) {
            return false;
        }
        if (!document.getElementById('street').value.trim()) {
            return false;
        }
        if (!portalDob) {
            return false;
        }
        var ret = document.querySelector('input[name="returning"]:checked');
        if (!ret) {
            return false;
        }
        if (ret.value === 'returning' && !document.getElementById('previous_dentist').value.trim()) {
            return false;
        }
        return true;
    }

    function canAdvanceFromStep5() {
        var concerns = document.querySelectorAll('input[name="concern"]:checked');
        if (!concerns.length) {
            return false;
        }
        var ong = document.querySelector('input[name="ongoing"]:checked');
        if (ong && ong.value === 'yes' && !document.getElementById('ongoing_details').value.trim()) {
            return false;
        }
        var al = document.querySelector('input[name="allergies"]:checked');
        if (al && al.value === 'yes' && !document.getElementById('allergies_specify').value.trim()) {
            return false;
        }
        if (!document.getElementById('medical_conditions').value.trim()) {
            return false;
        }
        return true;
    }

    function syncWizardNextState() {
        var wn = document.getElementById('wizNext');
        if (!wn || wn.classList.contains('hidden')) {
            return;
        }
        var ok = false;
        var rs = document.getElementById('reason');
        var ds = document.getElementById('dentist');
        if (wizardStep === 1) {
            ok = !!(rs && rs.value);
        } else if (wizardStep === 2) {
            ok = !!(ds && ds.value);
        } else if (wizardStep === 3) {
            ok = !!(selectedDateStr && selectedTime);
        } else if (wizardStep === 4) {
            ok = canAdvanceFromStep4();
        } else if (wizardStep === 5) {
            ok = canAdvanceFromStep5();
        }
        wn.disabled = !ok;
    }

    function refreshConfirmSummary() {
        var rsn = document.getElementById('reason');
        var optR = rsn && rsn.options[rsn.selectedIndex];
        var svc = (optR && optR.dataset && optR.dataset.label) ? optR.dataset.label : (optR ? optR.textContent.split(' — ')[0] : '—');
        document.getElementById('rcptService').textContent = svc || '—';
        document.getElementById('rcptDentist').textContent = assignedDentistName || '—';
        document.getElementById('rcptDate').textContent = selectedDateStr ? formatLongDate(selectedDateStr) : '—';
        document.getElementById('rcptTime').textContent = selectedTime ? format12h(selectedTime) : '—';
        var pay = document.getElementById('payment_method');
        var payOpt = pay && pay.options[pay.selectedIndex];
        document.getElementById('rcptPayment').textContent = (payOpt && payOpt.value) ? payOpt.textContent.trim() : '—';
    }

    function buildServiceCards() {
        var wrap = document.getElementById('serviceCards');
        var sel = document.getElementById('reason');
        if (!wrap || !sel) {
            return;
        }
        wrap.innerHTML = '';
        Array.prototype.forEach.call(sel.options, function (opt) {
            if (!opt.value) {
                return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pick-card' + (sel.value === opt.value ? ' is-selected' : '');
            var label = (opt.dataset && opt.dataset.label) ? opt.dataset.label : opt.textContent.split(' — ')[0];
            var dash = opt.textContent.indexOf('—');
            var meta = dash >= 0 ? opt.textContent.slice(dash).trim() : '';
            btn.innerHTML = '<span class="pick-card__name"></span><span class="pick-card__meta"></span>';
            btn.querySelector('.pick-card__name').textContent = label;
            btn.querySelector('.pick-card__meta').textContent = meta;
            btn.dataset.serviceId = opt.value;
            btn.addEventListener('click', function () {
                sel.value = opt.value;
                Array.prototype.forEach.call(wrap.querySelectorAll('.pick-card'), function (b) {
                    b.classList.remove('is-selected');
                });
                btn.classList.add('is-selected');
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                syncWizardNextState();
            });
            wrap.appendChild(btn);
        });
        syncWizardNextState();
    }

    function syncServiceCardsFromSelect() {
        var wrap = document.getElementById('serviceCards');
        var sel = document.getElementById('reason');
        if (!wrap || !sel) {
            return;
        }
        Array.prototype.forEach.call(wrap.querySelectorAll('.pick-card'), function (btn) {
            btn.classList.toggle('is-selected', btn.dataset.serviceId === sel.value);
        });
    }

    function buildDentistCards() {
        var wrap = document.getElementById('dentistCards');
        var sel = document.getElementById('dentist');
        if (!wrap || !sel) {
            return;
        }
        wrap.innerHTML = '';
        Array.prototype.forEach.call(sel.options, function (opt) {
            if (!opt.value) {
                return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pick-card' + (sel.value === opt.value ? ' is-selected' : '');
            var full = opt.textContent || '';
            var parts = full.split(' — ');
            btn.innerHTML = '<span class="pick-card__name"></span><span class="pick-card__meta"></span>';
            btn.querySelector('.pick-card__name').textContent = parts[0] || full;
            btn.querySelector('.pick-card__meta').textContent = parts[1] || 'General dentistry';
            btn.dataset.dentistId = opt.value;
            btn.addEventListener('click', function () {
                sel.value = opt.value;
                Array.prototype.forEach.call(wrap.querySelectorAll('.pick-card'), function (b) {
                    b.classList.remove('is-selected');
                });
                btn.classList.add('is-selected');
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                syncWizardNextState();
            });
            wrap.appendChild(btn);
        });
        syncWizardNextState();
    }

    function syncDentistCardsFromSelect() {
        var wrap = document.getElementById('dentistCards');
        var sel = document.getElementById('dentist');
        if (!wrap || !sel) {
            return;
        }
        Array.prototype.forEach.call(wrap.querySelectorAll('.pick-card'), function (btn) {
            btn.classList.toggle('is-selected', btn.dataset.dentistId === sel.value);
        });
    }

    function clearStep2Alerts() {
        var fe = document.getElementById('fieldErrors');
        var se = document.getElementById('submitError');
        if (fe) {
            fe.classList.add('hidden');
            fe.innerHTML = '';
        }
        if (se) {
            se.classList.add('hidden');
            se.textContent = '';
        }
    }

    async function loadCsrfToken() {
        const res = await fetch(AUTH_API + '?action=csrf', { credentials: 'same-origin' });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.csrf_token) {
            throw new Error(data.error || 'Could not initialize security token.');
        }
        csrfToken = String(data.csrf_token);
    }

    let viewYear, viewMonth;
    let selectedDateStr = null;
    let selectedTime = null;
    let pendingSlot = null;
    let slotsData = [];
    /** Last bookable clock time (HH:MM). From portal_options when present; else 16:30. */
    let clinicEndTimeStr = '16:30';

    function todayISO() {
        const d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function parseYMD(str) {
        const parts = str.split('-');
        const y = +parts[0], m = +parts[1], d = +parts[2];
        return new Date(y, m - 1, d);
    }

    function formatLongDate(ymd) {
        const dt = parseYMD(ymd);
        return dt.toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }

    function format12h(hhmm) {
        const [h, m] = hhmm.split(':').map(Number);
        const d = new Date(2000, 0, 1, h, m);
        return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
    }

    function splitName(fullName) {
        const parts = String(fullName || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return { first: '', last: '' };
        if (parts.length === 1) return { first: parts[0], last: '' };
        return {
            first: parts[0],
            last: parts.slice(1).join(' ')
        };
    }

    function parseClinicEndHhMm(str) {
        var s = String(str || '').trim();
        var match = s.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
        if (!match) {
            return { h: 16, mm: 30 };
        }
        var h = parseInt(match[1], 10);
        var mm = parseInt(match[2], 10);
        if (isNaN(h) || isNaN(mm) || h < 0 || h > 23 || mm < 0 || mm > 59) {
            return { h: 16, mm: 30 };
        }
        return { h: h, mm: mm };
    }

    function lastBookableMomentForCalendarDay(y, m, d) {
        var p = parseClinicEndHhMm(clinicEndTimeStr);
        return new Date(y, m, d, p.h, p.mm, 0, 0);
    }

    /** Past dates disabled; today disabled only at/after clinic end (clinic_end_time or 16:30). */
    function isCalendarDayDisabled(y, m, d) {
        var dayStart = new Date(y, m, d);
        dayStart.setHours(0, 0, 0, 0);
        var todayStart = new Date();
        todayStart.setHours(0, 0, 0, 0);
        if (dayStart < todayStart) {
            return true;
        }
        if (dayStart > todayStart) {
            return false;
        }
        return new Date() >= lastBookableMomentForCalendarDay(y, m, d);
    }

    function monthMatrix(year, monthIndex) {
        const first = new Date(year, monthIndex, 1);
        const startPad = first.getDay();
        const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
        const cells = [];
        let i = 0;
        for (; i < startPad; i++) cells.push({ type: 'pad' });
        for (let d = 1; d <= daysInMonth; d++) cells.push({ type: 'day', d });
        while (cells.length % 7 !== 0) cells.push({ type: 'pad' });
        return cells;
    }

    function renderCalendar() {
        document.getElementById('calTitle').textContent =
            new Date(viewYear, viewMonth, 1).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
        const grid = document.getElementById('calGrid');
        grid.innerHTML = '';
        monthMatrix(viewYear, viewMonth).forEach(function (cell) {
            const el = document.createElement('button');
            el.type = 'button';
            if (cell.type === 'pad') {
                el.className = 'cal-day cal-day--pad';
                el.disabled = true;
                grid.appendChild(el);
                return;
            }
            const y = viewYear, m = viewMonth, d = cell.d;
            const ymd = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            if (isCalendarDayDisabled(y, m, d)) {
                el.className = 'cal-day cal-day--disabled';
                el.textContent = d;
                el.disabled = true;
            } else {
                el.className = 'cal-day' + (selectedDateStr === ymd ? ' cal-day--selected' : '');
                el.textContent = d;
                el.addEventListener('click', function () {
                    selectedDateStr = ymd;
                    selectedTime = null;
                    pendingSlot = null;
                    var wn = document.getElementById('wizNext');
                    if (wn) {
                        wn.disabled = true;
                    }
                    document.getElementById('slotSelectRow').classList.add('hidden');
                    renderCalendar();
                    loadSlotsForDate(ymd);
                    updateLeftSummary();
                    syncWizardNextState();
                });
            }
            grid.appendChild(el);
        });
    }

    function getDentistIdForSlots() {
        const select = document.getElementById('dentist');
        if (!select || !select.value) {
            return '';
        }
        const id = parseInt(select.value, 10);
        return id > 0 ? String(id) : '';
    }

    function formatYmdFriendly(ymd) {
        if (!ymd || typeof ymd !== 'string') return '';
        var p = ymd.split('-').map(Number);
        if (p.length < 3) return ymd;
        var dt = new Date(p[0], p[1] - 1, p[2]);
        return dt.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }

    async function loadSlotsForDate(ymd) {
        document.getElementById('slotHint').textContent = 'Loading slots…';
        document.getElementById('slotList').innerHTML = '';
        const dentistId = getDentistIdForSlots();
        if (!dentistId) {
            document.getElementById('slotHint').textContent = 'Select a dentist to see available times.';
            syncWizardNextState();
            return;
        }
        try {
            const res = await fetch(
                AVAILABILITY_API +
                    '?date=' +
                    encodeURIComponent(ymd) +
                    '&dentist_id=' +
                    encodeURIComponent(dentistId) +
                    '&suggest_days=1',
                { credentials: 'same-origin' }
            );
            const data = await res.json().catch(function () { return {}; });
            slotsData = Array.isArray(data.slots) ? data.slots : [];
            var hasOpen = slotsData.some(function (s) {
                return s && s.available;
            });
            var sug = Array.isArray(data.suggested_dates) ? data.suggested_dates : [];
            var sugText =
                sug.length > 0
                    ? ' Next openings: ' +
                      sug.slice(0, 8).map(formatYmdFriendly).join(' · ') +
                      (sug.length > 8 ? ' …' : '')
                    : '';

            if (!slotsData.length) {
                if (data.dentist_day_off) {
                    document.getElementById('slotHint').textContent =
                        'This dentist is not scheduled on this day.' + sugText;
                } else {
                    document.getElementById('slotHint').textContent =
                        'No time slots available for this date.' + sugText;
                }
                syncWizardNextState();
                return;
            }
            if (!hasOpen) {
                document.getElementById('slotHint').textContent =
                    'All listed times are already booked for this day.' + sugText;
                renderSlotList();
                syncWizardNextState();
                return;
            }
            document.getElementById('slotHint').textContent = 'Tap an available time.';
            renderSlotList();
            syncWizardNextState();
        } catch (e) {
            document.getElementById('slotHint').textContent = 'Could not load availability.';
            syncWizardNextState();
        }
    }

    function renderSlotList() {
        const list = document.getElementById('slotList');
        list.innerHTML = '';
        if (!selectedDateStr) return;
        slotsData.forEach(function (slot) {
            var slotTime = slot.time;
            var booked = !slot.available;
            const row = document.createElement('button');
            row.type = 'button';
            row.disabled = booked;
            row.className = 'slot-pill';
            if (booked) {
                row.className += ' slot-pill--booked';
                row.innerHTML = '<span>' + format12h(slotTime) + '</span><span class="slot-pill__status">Full</span>';
            } else if (selectedTime === slotTime) {
                row.className += ' slot-pill--selected';
                row.innerHTML = '<span>' + format12h(slotTime) + '</span>';
            } else if (pendingSlot === slotTime) {
                row.className += ' slot-pill--pending';
                row.innerHTML = '<span>' + format12h(slotTime) + '</span>';
            } else {
                row.innerHTML = '<span>' + format12h(slotTime) + '</span>';
            }
            if (!booked) {
                row.addEventListener('click', function () {
                    selectedTime = slotTime;
                    pendingSlot = null;
                    var wn = document.getElementById('wizNext');
                    if (wn) {
                        wn.disabled = false;
                    }
                    document.getElementById('slotSelectRow').classList.add('hidden');
                    renderSlotList();
                    updateLeftSummary();
                    syncWizardNextState();
                });
            }
            list.appendChild(row);
        });
    }

    document.getElementById('btnConfirmSlot').addEventListener('click', function () {
        if (!pendingSlot) return;
        selectedTime = pendingSlot;
        pendingSlot = null;
        var wn = document.getElementById('wizNext');
        if (wn) {
            wn.disabled = false;
        }
        document.getElementById('slotSelectRow').classList.add('hidden');
        renderSlotList();
        updateLeftSummary();
        syncWizardNextState();
    });

    function updateLeftSummary() {
        updateSummaryType();
        const whenEl = document.getElementById('summaryWhen');
        const detail = document.getElementById('summaryTimeDetail');
        const dentistEl = document.getElementById('summaryDentist');
        var rsn = document.getElementById('reason');
        var dsn = document.getElementById('dentist');
        if (!whenEl) {
            return;
        }
        if (dentistEl) {
            dentistEl.textContent = dsn && dsn.value ? assignedDentistName : 'Select a dentist';
        }
        var mDen = document.getElementById('mSummaryDentist');
        var mWhen = document.getElementById('mSummaryWhen');
        if (mDen) {
            mDen.textContent = dsn && dsn.value ? assignedDentistName : '—';
        }
        if (!selectedDateStr) {
            whenEl.textContent = 'Select date & time';
            if (detail) {
                detail.classList.add('hidden');
            }
            if (mWhen) {
                mWhen.textContent = '—';
            }
            return;
        }
        whenEl.textContent = formatLongDate(selectedDateStr);
        if (selectedTime) {
            if (detail) {
                detail.textContent = format12h(selectedTime);
                detail.classList.remove('hidden');
            }
            if (mWhen) {
                mWhen.textContent = formatLongDate(selectedDateStr) + ' · ' + format12h(selectedTime);
            }
        } else {
            if (detail) {
                detail.classList.add('hidden');
            }
            if (mWhen) {
                mWhen.textContent = formatLongDate(selectedDateStr);
            }
        }
    }

    function updateSummaryType() {
        const r = document.getElementById('reason');
        const t = document.getElementById('summaryType');
        const durEl = document.getElementById('summaryDuration');
        const mType = document.getElementById('mSummaryType');
        if (!r || !t) {
            return;
        }
        if (!r.value) {
            t.textContent = 'Select a service';
            if (durEl) {
                durEl.textContent = '—';
            }
            if (mType) {
                mType.textContent = '—';
            }
            return;
        }
        const opt = r.options[r.selectedIndex];
        const label =
            opt && opt.dataset && opt.dataset.label ? opt.dataset.label : (opt ? opt.textContent.split(' — ')[0] : '');
        t.textContent = label || 'Select a service';
        var mins = opt && opt.dataset && opt.dataset.durationMinutes ? parseInt(opt.dataset.durationMinutes, 10) : 30;
        if (Number.isNaN(mins) || mins < 1) {
            mins = 30;
        }
        if (durEl) {
            durEl.textContent = mins + (mins === 1 ? ' minute' : ' minutes');
        }
        if (mType) {
            mType.textContent = label || '—';
        }
    }

    document.getElementById('calPrev').addEventListener('click', function () {
        viewMonth--;
        if (viewMonth < 0) { viewMonth = 11; viewYear--; }
        renderCalendar();
    });
    document.getElementById('calNext').addEventListener('click', function () {
        viewMonth++;
        if (viewMonth > 11) { viewMonth = 0; viewYear++; }
        renderCalendar();
    });

    document.getElementById('wizNext').addEventListener('click', function () {
        var rs = document.getElementById('reason');
        var ds = document.getElementById('dentist');
        if (wizardStep === 1) {
            if (!rs || !rs.value) {
                return;
            }
            clearStep2Alerts();
            setWizardStep(2);
            return;
        }
        if (wizardStep === 2) {
            if (!ds || !ds.value) {
                return;
            }
            clearStep2Alerts();
            setWizardStep(3);
            return;
        }
        if (wizardStep === 3) {
            if (!selectedDateStr || !selectedTime) {
                return;
            }
            clearStep2Alerts();
            setWizardStep(4);
            return;
        }
        if (wizardStep === 4) {
            if (!canAdvanceFromStep4()) {
                return;
            }
            clearStep2Alerts();
            setWizardStep(5);
            return;
        }
        if (wizardStep === 5) {
            if (!canAdvanceFromStep5()) {
                return;
            }
            clearStep2Alerts();
            setWizardStep(6);
        }
    });

    document.getElementById('wizBack').addEventListener('click', function () {
        if (wizardStep <= 1) {
            return;
        }
        clearStep2Alerts();
        setWizardStep(wizardStep - 1);
    });

    attachPhoneFormat(document.getElementById('phone'));

    document.querySelectorAll('input[name="returning"]').forEach(function (r) {
        r.addEventListener('change', function () {
            const show = document.querySelector('input[name="returning"]:checked').value === 'returning';
            document.getElementById('prevDentistWrap').classList.toggle('hidden', !show);
            syncWizardNextState();
        });
    });
    document.querySelectorAll('input[name="ongoing"]').forEach(function (r) {
        r.addEventListener('change', function () {
            const show = document.querySelector('input[name="ongoing"]:checked').value === 'yes';
            document.getElementById('ongoingDetailsWrap').classList.toggle('hidden', !show);
            syncWizardNextState();
        });
    });
    document.querySelectorAll('input[name="allergies"]').forEach(function (r) {
        r.addEventListener('change', function () {
            const show = document.querySelector('input[name="allergies"]:checked').value === 'yes';
            document.getElementById('allergiesWrap').classList.toggle('hidden', !show);
            syncWizardNextState();
        });
    });
    document.querySelectorAll('input[name="concern"]').forEach(function (c) {
        c.addEventListener('change', syncWizardNextState);
    });
        document.getElementById('reason').addEventListener('change', function () {
            updateLeftSummary();
            syncServiceCardsFromSelect();
            var optR = this.options[this.selectedIndex];
            var spec = (optR && optR.dataset && optR.dataset.specialization) ? String(optR.dataset.specialization).trim() : '';
            loadDentists(spec || undefined).then(function () {
                var ds = document.getElementById('dentist');
                if (ds) {
                    ds.value = '';
                }
                assignedDentistName = 'Our dental team';
                selectedTime = null;
                pendingSlot = null;
                var wn = document.getElementById('wizNext');
                if (wn) {
                    wn.disabled = true;
                }
                var sr = document.getElementById('slotSelectRow');
                if (sr) {
                    sr.classList.add('hidden');
                }
                if (selectedDateStr) {
                    loadSlotsForDate(selectedDateStr);
                }
                buildDentistCards();
                updateLeftSummary();
                syncWizardNextState();
            });
            syncWizardNextState();
        });

    function calcAge(dobValue) {
        if (!dobValue) return '';
        const [y, m, d] = dobValue.split('-').map(Number);
        const dob = new Date(y, m - 1, d);
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age--;
        }
        return age > 0 ? age : '';
    }

    const dobInput = document.getElementById('dob');
    const ageInput = document.getElementById('age');
    if (dobInput && ageInput) {
        dobInput.max = new Date().toISOString().split('T')[0];
        ageInput.readOnly = true;
        dobInput.addEventListener('change', function () {
            ageInput.value = calcAge(this.value);
        });
        dobInput.addEventListener('input', function () {
            ageInput.value = calcAge(this.value);
        });
    }

    function validateForm() {
        const errs = [];
        const phone = document.getElementById('phone').value.replace(/\s/g, '');
        if (!document.getElementById('first_name').value.trim()) errs.push('First name is required.');
        if (!document.getElementById('last_name').value.trim()) errs.push('Last name is required.');
        if (!document.getElementById('email').value.trim()) errs.push('Email is required.');
        if (!PHONE_RE.test(phone)) errs.push('Mobile number must be a valid PH number (e.g. +63 949 180 5825).');
        const age = parseInt(document.getElementById('age').value, 10);
        if (!age || age < 1 || age > 120) errs.push('Age must be between 1 and 120.');
        if (!document.getElementById('street').value.trim()) errs.push('Street address is required.');
        if (!portalDob) errs.push('Date of birth is not available in your profile. Please contact the clinic.');
        const reasonSelectEl = document.getElementById('reason');
        const reasonIdVal = reasonSelectEl ? reasonSelectEl.value : '';
        if (!selectedDateStr || !selectedTime) errs.push('Please select a date and time for your appointment.');
        if (!reasonIdVal) errs.push('Please select a reason for your appointment.');
        if (!document.getElementById('dentist').value) errs.push('Please select a preferred dentist.');
        const ret = document.querySelector('input[name="returning"]:checked');
        if (!ret) errs.push('Please indicate if you are a new or returning patient.');
        if (ret && ret.value === 'returning' && !document.getElementById('previous_dentist').value.trim()) {
            errs.push('Please enter your previous dentist’s name.');
        }
        const concerns = Array.prototype.filter.call(document.querySelectorAll('input[name="concern"]:checked'), function (c) { return c; });
        if (concerns.length === 0) errs.push('Select at least one option under existing dental concerns (you may choose “Others”).');
        const ong = document.querySelector('input[name="ongoing"]:checked');
        if (ong && ong.value === 'yes' && !document.getElementById('ongoing_details').value.trim()) {
            errs.push('Please describe your ongoing dental treatments.');
        }
        const al = document.querySelector('input[name="allergies"]:checked');
        if (al && al.value === 'yes' && !document.getElementById('allergies_specify').value.trim()) {
            errs.push('Please specify your allergies.');
        }
        if (!document.getElementById('medical_conditions').value.trim()) errs.push('Medical conditions field is required.');
        if (!document.getElementById('payment_method').value) errs.push('Preferred payment method is required.');
        if (!document.getElementById('consent').checked) errs.push('You must accept the consent statement.');
        return errs;
    }

    document.getElementById('bookingForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (wizardStep !== 6) {
            return;
        }
        const fe = document.getElementById('fieldErrors');
        const se = document.getElementById('submitError');
        fe.classList.add('hidden');
        se.classList.add('hidden');
        const errs = validateForm();
        if (errs.length) {
            fe.innerHTML = errs.map(function (x) { return '• ' + x; }).join('<br>');
            fe.classList.remove('hidden');
            fe.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        const phone = document.getElementById('phone').value.replace(/\s/g, '');
        const firstName = document.getElementById('first_name').value.trim();
        const lastName = document.getElementById('last_name').value.trim();
        const fullName = (firstName + ' ' + lastName).trim();
        const concerns = Array.prototype.map.call(document.querySelectorAll('input[name="concern"]:checked'), function (c) { return c.value; });
        const patient_details = {
            first_name: firstName,
            last_name: lastName,
            full_name: fullName,
            email: document.getElementById('email').value.trim(),
            phone: phone,
            age: parseInt(document.getElementById('age').value, 10),
            dob: portalDob || null,
            street: document.getElementById('street').value.trim(),
            city: document.getElementById('city').value.trim(),
            state: document.getElementById('state').value.trim(),
            country: document.getElementById('country').value,
            postal_code: document.getElementById('postal').value.trim(),
            returning_patient: document.querySelector('input[name="returning"]:checked').value,
            previous_dentist: document.getElementById('previous_dentist').value.trim()
        };
        const health_history = {
            concerns: concerns,
            ongoing_treatments: document.querySelector('input[name="ongoing"]:checked').value,
            ongoing_details: document.getElementById('ongoing_details').value.trim(),
            allergies: document.querySelector('input[name="allergies"]:checked').value,
            allergies_specify: document.getElementById('allergies_specify').value.trim(),
            medical_conditions: document.getElementById('medical_conditions').value.trim(),
            special_requests: document.getElementById('special_requests').value.trim(),
            how_heard: document.getElementById('how_heard').value
        };

        const meeting_type = 'in_person';
        const reasonSelect = document.getElementById('reason');
        const reasonId = reasonSelect.value;
        const reasonLabel = reasonSelect.options[reasonSelect.selectedIndex] && reasonSelect.options[reasonSelect.selectedIndex].dataset.label
            ? reasonSelect.options[reasonSelect.selectedIndex].dataset.label
            : '';
        const body = {
            date: selectedDateStr,
            time: selectedTime,
            service_id: parseInt(reasonId, 10),
            dentist_id: parseInt(document.getElementById('dentist').value, 10),
            reason_label: reasonLabel,
            meeting_type: meeting_type,
            patient_details: patient_details,
            health_history: health_history,
            payment_method: document.getElementById('payment_method').value,
            consent: true
        };

        if (!portalUserId) {
            showError('Session error. Please refresh the page and log in again.');
            return;
        }

        const btn = document.getElementById('btnSubmit');
        btn.disabled = true;
        fetch(APPT_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: JSON.stringify(Object.assign({}, body, { csrf_token: csrfToken }))
        }).then(async function (res) {
            const raw = await res.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                showError('Server error. Please try again or contact the clinic.');
                btn.disabled = false;
                return;
            }
            if (data.success) {
                var dentSel = document.getElementById('dentist');
                var dentOpt = dentSel && dentSel.options[dentSel.selectedIndex];
                if (dentOpt && dentOpt.value) {
                    var dn = (dentOpt.textContent || '').split(' — ')[0].trim();
                    if (dn) assignedDentistName = dn;
                }
                var paySel = document.getElementById('payment_method');
                var payLabel = '';
                if (paySel && paySel.options[paySel.selectedIndex]) {
                    payLabel = (paySel.options[paySel.selectedIndex].textContent || '').trim() || String(paySel.value || '');
                }
                sessionStorage.setItem('booking_summary', JSON.stringify({
                    date: selectedDateStr,
                    time: selectedTime,
                    reason: reasonLabel,
                    dentist: assignedDentistName,
                    payment_method: payLabel
                }));
                window.location.href = 'confirmation.html';
                return;
            }
            if (data.error === 'conflict') {
                showError('This time slot is no longer available. Please choose another.');
            } else if (res.status === 409) {
                showError(data.error || 'This time slot is no longer available. Please choose another.');
            } else {
                var errMsg = data.error || 'Something went wrong. Please try again.';
                if (res.status === 429 && data.retry_after != null) {
                    var sec = parseInt(data.retry_after, 10);
                    if (sec > 0) {
                        errMsg += ' Try again in about ' + sec + ' second' + (sec === 1 ? '' : 's') + '.';
                    }
                }
                showError(errMsg);
            }
            btn.disabled = false;
        }).catch(function () {
            showError('Could not reach the server. Check your connection.');
            btn.disabled = false;
        });
    });

    (async function init() {
        try {
            await loadCsrfToken();
            const res = await fetch(AUTH_API + '?action=me', { credentials: 'same-origin' });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
                window.location.replace('../register.html');
                return;
            }
            portalUserId = data.id;
            portalUserName = data.name || '';
            portalEmail = data.email || '';
            portalPhone = data.phone || '';
            portalDob = data.dob || '';
            const split = splitName(portalUserName);
            portalFirstName = split.first;
            portalLastName = split.last;
            document.getElementById('first_name').value = portalFirstName;
            document.getElementById('last_name').value = portalLastName;
            document.getElementById('email').value = portalEmail;
            document.getElementById('phone').value = formatPHPhone(portalPhone) || portalPhone || '';
            if (dobInput) {
                dobInput.value = portalDob;
                dobInput.readOnly = true;
                dobInput.setAttribute('aria-readonly', 'true');
            }
            if (ageInput) {
                ageInput.value = calcAge(portalDob);
            }
        } catch (e) {
            window.location.replace('../register.html');
            return;
        }

        async function loadServices() {
            const select = document.getElementById('reason');
            if (!select) return;
            select.innerHTML = '<option value="">Loading services...</option>';
            try {
                const res = await fetch(SERVICES_API, {
                    credentials: 'same-origin'
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (data && data.error) throw new Error(data.error);
                var services = Array.isArray(data.services) ? data.services : [];
                services = services.filter(function (s) {
                    return s && (s.active === undefined || Number(s.active) === 1);
                });
                if (!services.length) {
                    select.innerHTML = '<option value="">No services available</option>';
                    buildServiceCards();
                    updateLeftSummary();
                    return;
                }
                select.innerHTML = '<option value="">Select a service...</option>';
                services.forEach(function (s) {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.dataset.label = s.name;
                    opt.dataset.durationMinutes = String(s.duration_minutes != null ? s.duration_minutes : 30);
                    if (s.required_specialization) {
                        opt.dataset.specialization = String(s.required_specialization);
                    }
                    opt.textContent = s.name +
                        (s.price ? ' — \u20B1' + parseFloat(s.price).toLocaleString('en-PH') : '');
                    select.appendChild(opt);
                });
                buildServiceCards();
                updateLeftSummary();
            } catch (e) {
                select.innerHTML = '<option value="">Failed to load services. Refresh page.</option>';
                buildServiceCards();
                updateLeftSummary();
            }
        }

        async function loadPortalOptions() {
            var paySel = document.getElementById('payment_method');
            var refSel = document.getElementById('how_heard');
            if (!paySel || !refSel) {
                return;
            }
            paySel.innerHTML = '<option value="">Loading…</option>';
            refSel.innerHTML = '<option value="">Loading…</option>';
            try {
                var res = await fetch(PORTAL_OPTIONS_API, { credentials: 'same-origin' });
                var data = await res.json().catch(function () { return {}; });
                if (!res.ok) {
                    throw new Error((data && data.error) ? data.error : 'HTTP ' + res.status);
                }
                if (data.clinic_end_time != null && String(data.clinic_end_time).trim() !== '') {
                    clinicEndTimeStr = String(data.clinic_end_time).trim();
                }
                var pm = Array.isArray(data.payment_methods) ? data.payment_methods : [];
                var rf = Array.isArray(data.referral_sources) ? data.referral_sources : [];
                if (!pm.length) {
                    paySel.innerHTML = '<option value="">No payment methods configured</option>';
                } else {
                    paySel.innerHTML = '<option value="">Select…</option>';
                    pm.forEach(function (label) {
                        var t = String(label || '').trim();
                        if (!t) {
                            return;
                        }
                        var opt = document.createElement('option');
                        opt.value = t;
                        opt.textContent = t;
                        paySel.appendChild(opt);
                    });
                }
                if (!rf.length) {
                    refSel.innerHTML = '<option value="">No referral options configured</option>';
                } else {
                    refSel.innerHTML = '<option value="">Select…</option>';
                    rf.forEach(function (label) {
                        var t = String(label || '').trim();
                        if (!t) {
                            return;
                        }
                        var opt = document.createElement('option');
                        opt.value = t;
                        opt.textContent = t;
                        refSel.appendChild(opt);
                    });
                }
            } catch (e) {
                paySel.innerHTML = '<option value="">Could not load options</option>';
                refSel.innerHTML = '<option value="">Could not load options</option>';
            }
        }

        async function loadDentists(requiredSpecialization) {
            const select = document.getElementById('dentist');
            if (!select) return;
            select.innerHTML = '<option value="">Loading dentists...</option>';
            try {
                var dentUrl = DENTISTS_API;
                if (requiredSpecialization) {
                    dentUrl += '&specialization=' + encodeURIComponent(requiredSpecialization);
                }
                const res = await fetch(dentUrl, {
                    credentials: 'same-origin'
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const dentists = await res.json();
                if (dentists && dentists.error) throw new Error(dentists.error);
                if (!Array.isArray(dentists) || !dentists.length) {
                    select.innerHTML =
                        '<option value="">No dentist available for this procedure at the moment</option>';
                    buildDentistCards();
                    updateLeftSummary();
                    return;
                }
                select.innerHTML = '<option value="">Select a dentist...</option>';
                dentists.forEach(function (d) {
                    const opt = document.createElement('option');
                    opt.value = d.id;
                    opt.textContent = d.name + (d.specialization ? ' — ' + d.specialization : '');
                    select.appendChild(opt);
                });
                select.value = '';
                assignedDentistName = 'Our dental team';
                buildDentistCards();
                updateLeftSummary();
            } catch (e) {
                select.innerHTML = '<option value="">Failed to load dentists. Refresh page.</option>';
                buildDentistCards();
                updateLeftSummary();
            }
        }

        document.getElementById('dentist').addEventListener('change', function () {
            const opt = this.options[this.selectedIndex];
            assignedDentistName = opt && opt.textContent ? opt.textContent.split(' — ')[0] : 'Our dental team';
            syncDentistCardsFromSelect();
            updateLeftSummary();
            if (selectedDateStr) {
                selectedTime = null;
                pendingSlot = null;
                var wn = document.getElementById('wizNext');
                if (wn) {
                    wn.disabled = true;
                }
                document.getElementById('slotSelectRow').classList.add('hidden');
                loadSlotsForDate(selectedDateStr);
            }
            syncWizardNextState();
        });

        await Promise.all([loadServices(), loadDentists(), loadPortalOptions()]);
        if (selectedDateStr && getDentistIdForSlots()) {
            loadSlotsForDate(selectedDateStr);
        }

        const now = new Date();
        viewYear = now.getFullYear();
        viewMonth = now.getMonth();
        renderCalendar();
        setWizardStep(1);
        updateLeftSummary();

        var payEl = document.getElementById('payment_method');
        if (payEl) {
            payEl.addEventListener('change', function () {
                if (wizardStep === 6) {
                    refreshConfirmSummary();
                }
            });
        }

        document.querySelectorAll('#wizardStep4 input, #wizardStep4 textarea, #wizardStep5 input, #wizardStep5 textarea').forEach(function (el) {
            el.addEventListener('input', syncWizardNextState);
            el.addEventListener('change', syncWizardNextState);
        });
    })();
})();
