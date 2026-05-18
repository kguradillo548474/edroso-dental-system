/**
 * Booking CTAs — auth check before navigating to portal book flow.
 */
(function () {
    function isPortalPage() {
        return /\/portal\//.test(window.location.pathname);
    }

    function authApiUrl() {
        return isPortalPage() ? '../../api/patient_auth.php' : '../api/patient_auth.php';
    }

    function bookUrl() {
        return isPortalPage() ? 'book.html' : 'portal/book.html';
    }

    function loginUrlWithNext() {
        var base = isPortalPage() ? '../login.html' : 'login.html';
        return base + '?next=' + encodeURIComponent('portal/book.html');
    }

    function handleBookClick(e) {
        e.preventDefault();
        fetch(authApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'me' })
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (data && data.id) {
                    window.location.href = bookUrl();
                } else {
                    window.location.href = loginUrlWithNext();
                }
            })
            .catch(function () {
                window.location.href = loginUrlWithNext();
            });
    }

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-book-cta]');
        if (!el) return;
        handleBookClick(e);
    });
})();
