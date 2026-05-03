/**
 * Customer home — load dentists + weekly hours (same data as portal booking).
 */
(function () {
    const DENTISTS_URL = '../api/dentists.php?scope=booking&include_schedule=1';
    const grid = document.getElementById('homeDentistsGrid');
    const modal = document.getElementById('dentistHoursModal');
    if (!grid || !modal) return;

    const modalTitle = document.getElementById('dentistHoursModalTitle');
    const modalSpec = document.getElementById('dentistHoursModalSpec');
    const modalBody = document.getElementById('dentistHoursModalBody');

    function escapeHtml(s) {
        if (s == null) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatHm(hm) {
        if (!hm || typeof hm !== 'string') return '';
        var p = hm.split(':');
        if (p.length < 2) return hm;
        var h = parseInt(p[0], 10);
        var m = parseInt(p[1], 10);
        var am = h < 12;
        var h12 = h % 12;
        if (h12 === 0) h12 = 12;
        var mm = String(m).padStart(2, '0');
        return h12 + ':' + mm + ' ' + (am ? 'AM' : 'PM');
    }

    function scheduleLines(sched) {
        if (!Array.isArray(sched) || !sched.length) {
            return '<p class="text-sm text-gray-500">Weekly hours are not set yet. Please call the clinic.</p>';
        }
        var active = sched.filter(function (r) {
            return r && Number(r.is_active) === 1;
        });
        if (!active.length) {
            return '<p class="text-sm text-gray-600">This dentist has no active working days on file. Please contact the clinic.</p>';
        }
        return (
            '<ul class="space-y-2 text-sm text-gray-700">' +
            active
                .map(function (r) {
                    return (
                        '<li class="flex justify-between gap-4 border-b border-gray-100 pb-2">' +
                        '<span class="font-medium text-[var(--secondary)]">' +
                        escapeHtml(r.day_of_week || '') +
                        '</span>' +
                        '<span>' +
                        escapeHtml(formatHm(r.start_time)) +
                        ' – ' +
                        escapeHtml(formatHm(r.end_time)) +
                        '</span>' +
                        '</li>'
                    );
                })
                .join('') +
            '</ul>'
        );
    }

    function openModal(d) {
        if (!modalTitle || !modalSpec || !modalBody) return;
        modalTitle.textContent = d.name || 'Dentist';
        modalSpec.textContent = d.specialization || '';
        modalBody.innerHTML = scheduleLines(d.weekly_schedule);
        modal.classList.remove('hidden');
        modal.classList.add('flex', 'items-center', 'justify-center');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex', 'items-center', 'justify-center');
        modal.setAttribute('aria-hidden', 'true');
    }

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.getElementById('dentistHoursModalClose')?.addEventListener('click', closeModal);

    function resolvePhotoUrl(d) {
        if (!d.photo_url) return '';
        var u = String(d.photo_url);
        if (/^https?:\/\//i.test(u)) return u;
        return '../' + u.replace(/^\.\//, '');
    }

    async function load() {
        grid.innerHTML =
            '<p class="col-span-full text-center text-sm text-gray-500 py-8">Loading team…</p>';
        try {
            var res = await fetch(DENTISTS_URL, { credentials: 'same-origin' });
            var list = await res.json().catch(function () {
                return [];
            });
            if (!res.ok || (list && list.error)) {
                throw new Error((list && list.error) || 'HTTP ' + res.status);
            }
            if (!Array.isArray(list) || !list.length) {
                grid.innerHTML =
                    '<p class="col-span-full text-center text-sm text-gray-600 py-8">No dentist profiles are available at the moment. Please try again later or call the clinic.</p>';
                return;
            }
            grid.innerHTML = list
                .map(function (d) {
                    var photo = resolvePhotoUrl(d);
                    var initials = (d.name || '?')
                        .split(/\s+/)
                        .map(function (x) {
                            return x[0];
                        })
                        .join('')
                        .slice(0, 2)
                        .toUpperCase();
                    var imgBlock = photo
                        ? '<img src="' +
                          escapeHtml(photo) +
                          (d.photo_version ? '?v=' + encodeURIComponent(String(d.photo_version)) : '') +
                          '" alt="" class="h-24 w-24 rounded-full object-cover border-4 border-white shadow" loading="lazy">'
                        : '<div class="h-24 w-24 rounded-full bg-gray-100 border-4 border-white shadow flex items-center justify-center text-2xl font-bold text-[var(--primary)]">' +
                          escapeHtml(initials) +
                          '</div>';
                    return (
                        '<article class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm flex flex-col">' +
                        '<div class="flex justify-center mb-4">' +
                        imgBlock +
                        '</div>' +
                        '<h3 class="text-lg font-semibold text-center text-[var(--secondary)]">' +
                        escapeHtml(d.name || '') +
                        '</h3>' +
                        '<p class="text-sm text-gray-500 text-center mt-1">' +
                        escapeHtml(d.specialization || '') +
                        '</p>' +
                        '<button type="button" class="mt-5 w-full rounded-xl border-2 border-[var(--primary)] px-4 py-2.5 text-sm font-semibold text-[var(--primary)] hover:bg-[var(--primary)] hover:text-white transition-colors" data-dentist-card="' +
                        escapeHtml(String(d.id)) +
                        '">View hours &amp; days</button>' +
                        '<a href="portal/book.html" class="mt-3 block text-center text-sm font-semibold text-[var(--primary)] hover:text-[var(--primary-dark)]">Book an appointment</a>' +
                        '</article>'
                    );
                })
                .join('');

            window.__homeDentists = list;
            grid.querySelectorAll('[data-dentist-card]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = parseInt(btn.getAttribute('data-dentist-card'), 10);
                    var d = (window.__homeDentists || []).find(function (x) {
                        return Number(x.id) === id;
                    });
                    if (d) openModal(d);
                });
            });
        } catch (e) {
            grid.innerHTML =
                '<p class="col-span-full text-center text-sm text-red-600 py-8">Could not load dentist information. Please refresh or contact the clinic.</p>';
        }
    }

    load();
})();
