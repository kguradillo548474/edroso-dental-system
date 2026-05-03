// Returns sidebar HTML with the given active page highlighted
function getSidebarHTML(activePage) {
    const navItems = [
        { href: 'dashboard.html', icon: 'fa-chart-line', label: 'Dashboard', key: 'dashboard' },
        { href: 'patients.html', icon: 'fa-user-injured', label: 'Patients', key: 'patients' },
        { href: 'appointments.html', icon: 'fa-calendar-check', label: 'Appointments', key: 'appointments' },
        { href: 'dentists.html', icon: 'fa-user-md', label: 'Dentists', key: 'dentists' },
        { href: 'payments.html', icon: 'fa-file-invoice-dollar', label: 'Payments', key: 'payments' },
    ];
    const navHTML = navItems.map(item => {
        const isActive = activePage === item.key;
        return `<li>
            <a href="${item.href}" class="flex items-center p-2 rounded-lg font-medium transition-colors group relative
                ${isActive ? 'text-secondary bg-primary/10' : 'text-secondary hover:bg-neutral-dark'}">
                <div class="w-5 h-5 flex items-center justify-center mr-3 ${isActive ? 'text-primary' : 'text-gray-500 group-hover:text-primary'}">
                    <i class="fas ${item.icon}"></i>
                </div>
                <span>${item.label}</span>
                ${isActive ? '<span class="w-1 h-5 bg-primary rounded-full absolute right-0"></span>' : ''}
            </a>
        </li>`;
    }).join('');

    return `
    <div id="sidebarBackdrop" class="fixed inset-0 z-20 bg-black/40 hidden md:hidden"></div>
    <aside class="w-64 bg-white shadow-lg fixed h-full z-30 flex flex-col transition-transform duration-200 -translate-x-full md:translate-x-0" id="sidebar">
        <div class="flex items-center p-4 border-b border-neutral">
            <div class="w-10 h-10 bg-primary rounded-lg flex items-center justify-center mr-2">
                <i class="fas fa-tooth text-white text-lg"></i>
            </div>
            <div>
                <span class="font-bold text-secondary text-lg">Edroso</span>
                <span class="font-bold text-primary text-lg">Clinic</span>
            </div>
        </div>
        <nav class="p-4 overflow-y-auto flex-1">
            <div class="mb-6">
                <p class="text-xs font-medium text-gray-400 mb-3 pl-2 uppercase tracking-wider">Main Menu</p>
                <ul class="space-y-1">${navHTML}</ul>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-400 mb-3 pl-2 uppercase tracking-wider">System</p>
                <ul class="space-y-1">
                    <li>
                        <a href="settings.html" class="flex items-center p-2 rounded-lg font-medium transition-colors group relative
                            ${activePage === 'settings' ? 'text-secondary bg-primary/10' : 'text-secondary hover:bg-neutral-dark'}">
                            <div class="w-5 h-5 flex items-center justify-center mr-3 ${activePage === 'settings' ? 'text-primary' : 'text-gray-500 group-hover:text-primary'}"><i class="fas fa-cog"></i></div>
                            <span>Settings</span>
                            ${activePage === 'settings' ? '<span class="w-1 h-5 bg-primary rounded-full absolute right-0"></span>' : ''}
                        </a>
                    </li>
                    <li>
                        <a href="#" id="logoutButton" class="flex items-center p-2 rounded-lg text-secondary hover:bg-red-50 hover:text-red-500 font-medium transition-colors group relative">
                            <div class="w-5 h-5 flex items-center justify-center text-gray-500 group-hover:text-red-500 mr-3"><i class="fas fa-sign-out-alt"></i></div>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
        <div class="p-4 border-t border-neutral">
            <div class="flex items-center justify-between">
                <p class="text-xs text-gray-400">v2.0.0</p>
                <p class="text-xs text-gray-400">© 2025 Edroso</p>
            </div>
        </div>
    </aside>`;
}

function getHeaderHTML(pageTitle) {
    return `
    <header class="bg-white shadow-sm h-16 fixed w-full md:w-[calc(100%-16rem)] z-10 flex items-center justify-between px-4 md:px-6" id="mainHeader">
        <div class="flex items-center">
            <button id="sidebar-toggle" class="mr-3 w-11 h-11 flex items-center justify-center rounded-md hover:bg-neutral-dark transition-colors" aria-label="Toggle sidebar navigation">
                <i class="fas fa-bars text-secondary"></i>
            </button>
            <div class="flex items-center">
                <span class="w-2 h-8 bg-primary rounded-md mr-3"></span>
                <h1 class="text-xl font-semibold text-secondary">${pageTitle}</h1>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <div class="flex items-center space-x-2">
                <div class="h-8 mx-1 w-px bg-neutral-dark"></div>
                <div class="h-9 w-9 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-sm" id="userInitials">A</div>
                <span class="text-sm font-medium text-secondary hidden md:block" id="userName">Admin</span>
            </div>
        </div>
    </header>`;
}
