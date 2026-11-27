import {
    initializeLogin,
    initializeLayout
} from './modules/auth.js';

import {
    initializeDashboard
} from './modules/dashboard.js';

document.addEventListener('DOMContentLoaded', () => {

    if (document.getElementById('login-form')) {
        initializeLogin();
    }

    if (document.getElementById('sidebar')) {
        initializeLayout();
    }

    if (document.getElementById('dashboard-root')) {
        initializeDashboard();
    }

});
