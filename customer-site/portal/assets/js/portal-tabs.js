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
                'px-6',
                'sm:px-8',
                'py-4',
                'text-base',
                'sm:text-lg',
                'font-semibold',
                'border-b-2',
                'rounded-t-base',
                'transition-colors',
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
                    'hover:border-brand/60',
                    'hover:bg-brand/5',
                ]).join(' ');
                tab.removeAttribute('aria-current');
            }
            const icon = tab.querySelector('svg');
            if (icon) {
                icon.setAttribute(
                    'class',
                    isActive
                        ? 'w-5 h-5 me-2 text-fg-brand'
                        : 'w-5 h-5 me-2 text-body group-hover:text-fg-brand'
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
