const API_BASE_URL = window.location.origin;

export function getToken() {
    return sessionStorage.getItem('variance_token');
}

export function getUser() {
    const user = sessionStorage.getItem('variance_user');

    try {
        return user ? JSON.parse(user) : null;
    } catch {
        return null;
    }
}

export function isAuthenticated() {
    return Boolean(getToken() && getUser());
}

export function hasRole(roles) {
    const user = getUser();

    if (!user) return false;

    return roles.includes(user.role);
}

export async function apiRequest(endpoint, options = {}) {
    const token = getToken();

    const headers = {
        Accept: 'application/json',
        ...(options.headers || {}),
    };

    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(`${API_BASE_URL}/api/${endpoint}`, {
        ...options,
        headers,
    });

    if (response.status === 401 && token) {
        logout();
        throw new Error('Sesi telah berakhir. Silakan login kembali.');
    }

    const result = await response.json();

    if (!response.ok) {
        throw new Error(
            result.message || 'Terjadi kesalahan pada server.'
        );
    }

    return result;
}

export async function login(email, password) {
    const result = await apiRequest('auth/login', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            email,
            password,
            device_name: 'PEMI-ISUZU-Web',
        }),
    });

    if (!result.success || !result.data?.token) {
        throw new Error('Respons login tidak valid.');
    }

    sessionStorage.setItem(
        'variance_token',
        result.data.token
    );

    sessionStorage.setItem(
        'variance_user',
        JSON.stringify(result.data.user)
    );

    return result.data.user;
}

export function logout() {
    const token = getToken();

    sessionStorage.removeItem('variance_token');
    sessionStorage.removeItem('variance_user');

    if (token) {
        fetch(`${API_BASE_URL}/api/auth/logout`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        }).catch(() => {});
    }

    window.location.href = '/login';
}

export function initializeLogin() {
    const form = document.getElementById('login-form');

    if (!form) return;

    const errorBox = document.getElementById('login-error');
    const submitButton = document.getElementById('login-submit');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        errorBox.classList.add('hidden');
        submitButton.disabled = true;
        submitButton.textContent = 'Signing in...';

        const email = form.email.value.trim();
        const password = form.password.value;

        try {
            await login(email, password);

            window.location.href = '/dashboard';

        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.classList.remove('hidden');

        } finally {
            submitButton.disabled = false;
            submitButton.textContent = 'Sign In';
            form.password.value = '';
        }
    });
}

export function initializeLayout() {
    const user = getUser();

    if (!user) {
        window.location.href = '/login';
        return;
    }

    const name = document.getElementById('header-user-name');
    const role = document.getElementById('header-user-role');
    const avatar = document.getElementById('header-user-avatar');

    if (name) name.textContent = user.name;
    if (role) role.textContent = user.role.toUpperCase();

    if (avatar) {
        avatar.textContent = user.name.charAt(0).toUpperCase();
    }

    const adminMenu = document.getElementById(
        'user-management-menu'
    );

    if (adminMenu) {
        adminMenu.classList.toggle(
            'hidden',
            user.role !== 'administrator'
        );
    }

    document.getElementById('logout-button')
        ?.addEventListener('click', logout);

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggle = document.getElementById('sidebar-toggle');

    const closeSidebar = () => {
        sidebar?.classList.add('-translate-x-full');
        overlay?.classList.add('hidden');
    };

    toggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('-translate-x-full');
        overlay?.classList.toggle('hidden');
    });

    overlay?.addEventListener('click', closeSidebar);
}
