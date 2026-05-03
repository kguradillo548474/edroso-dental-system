/**
 * Portal tab bar — active state from current URL filename (data-tab="…html").
 */
(function () {
    function initPortalTabs() {
        const currentPage = (window.location.pathname.split('/').pop() || '').toLowerCase();
        document.querySelectorAll('[data-tab]').forEach(function (tab) {
            const hrefFile = (tab.getAttribute('data-tab') || '').toLowerCase();
            const isActive = hrefFile === currentPage;
            const base = [
                'inline-flex',
                'items-center',
                'justify-center',
                'p-4',
                'border-b',
                'rounded-t-base',
                'group',
            ];
            if (isActive) {
                tab.className = base.concat(['text-fg-brand', 'border-brand']).join(' ');
                tab.setAttribute('aria-current', 'page');
            } else {
                tab.className = base.concat([
                    'border-transparent',
                    'text-body',
                    'hover:text-fg-brand',
                    'hover:border-brand',
                ]).join(' ');
                tab.removeAttribute('aria-current');
            }
            const icon = tab.querySelector('svg');
            if (icon) {
                icon.setAttribute(
                    'class',
                    isActive
                        ? 'w-4 h-4 me-2 text-fg-brand'
                        : 'w-4 h-4 me-2 text-body group-hover:text-fg-brand'
                );
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPortalTabs);
    } else {
        initPortalTabs();
    }
})();
