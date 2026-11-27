<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'Dashboard') | PEMI</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">

    <div class="min-h-screen flex">

        <!-- SIDEBAR -->
        <aside id="sidebar"
            class="fixed inset-y-0 left-0 z-40 w-64 bg-slate-950 text-white
                   flex flex-col transition-transform duration-300
                   -translate-x-full lg:translate-x-0">

            <!-- BRAND -->
            <div class="h-20 px-6 flex items-center border-b border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-600
                                flex items-center justify-center font-bold text-lg">
                        P
                    </div>
                    <div>
                        <h1 class="font-bold tracking-wide">PEMI</h1>
                        <p class="text-xs text-slate-400">Variance Order PPC</p>
                    </div>
                </div>
            </div>

            <!-- NAVIGATION -->
            <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">

                <p class="px-3 mb-3 text-xs font-semibold uppercase
                          tracking-wider text-slate-500">
                    Main Menu
                </p>

                
                <a href="/dashboard"
                    class="flex items-center gap-3 px-3 py-3 rounded-lg
                    {{ request()->is('dashboard')
                        ? 'bg-blue-600 text-white'
                        : 'text-slate-300 hover:bg-slate-800 transition' }}">
                    <span>▦</span>
                    <span class="text-sm font-medium">Dashboard</span>
                </a>

                <a href="/imports"
                    class="flex items-center gap-3 px-3 py-3 rounded-lg
                    {{ request()->is('imports')
                        ? 'bg-blue-600 text-white'
                        : 'text-slate-300 hover:bg-slate-800 transition' }}">
                    <span>⇧</span>
                    <span class="text-sm">Import Data</span>
                </a>


                <a href="/variance"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg
                          text-slate-300 hover:bg-slate-800 transition">
                    <span>⇄</span>
                    <span class="text-sm">Variance Analysis</span>
                </a>

                <a href="/reviews"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg
                          text-slate-300 hover:bg-slate-800 transition">
                    <span>☑</span>
                    <span class="text-sm">Review Management</span>
                </a>

                <a href="/audit-history"
                   class="flex items-center gap-3 px-3 py-3 rounded-lg
                          text-slate-300 hover:bg-slate-800 transition">
                    <span>◷</span>
                    <span class="text-sm">Audit History</span>
                </a>

                <div id="user-management-menu" class="hidden">
                    <p class="px-3 mt-8 mb-3 text-xs font-semibold
                              uppercase tracking-wider text-slate-500">
                        Administration
                    </p>

                    <a href="/users"
                       class="flex items-center gap-3 px-3 py-3 rounded-lg
                              text-slate-300 hover:bg-slate-800 transition">
                        <span>♙</span>
                        <span class="text-sm">User Management</span>
                    </a>
                </div>

            </nav>

            <!-- SIDEBAR FOOTER -->
            <div class="p-4 border-t border-slate-800">
                <p class="text-xs text-slate-500">
                    PEMI
                </p>
                <p class="text-xs text-slate-600 mt-1">
                    Variance Order System v1.0
                </p>
            </div>

        </aside>

        <!-- MAIN CONTENT -->
        <div class="flex-1 min-w-0 lg:ml-64">

            <!-- TOPBAR -->
            <header class="h-20 bg-white border-b border-slate-200
                           px-5 lg:px-8 flex items-center justify-between">

                <div class="flex items-center gap-4">
                    <button id="sidebar-toggle"
                        class="lg:hidden p-2 rounded-lg hover:bg-slate-100"
                        aria-label="Open navigation">
                        ☰
                    </button>

                    <div>
                        <h2 class="font-semibold text-lg">
                            @yield('page-heading', 'Dashboard')
                        </h2>
                        <p class="text-xs text-slate-500">
                            Production Planning & Control
                        </p>
                    </div>
                </div>

                <!-- USER PROFILE -->
                <div class="flex items-center gap-3">
                    <div class="text-right hidden sm:block">
                        <p id="header-user-name"
                           class="text-sm font-semibold">
                            Loading...
                        </p>
                        <p id="header-user-role"
                           class="text-xs text-slate-500">
                            -
                        </p>
                    </div>

                    <div class="w-10 h-10 rounded-full bg-blue-100
                                text-blue-700 flex items-center
                                justify-center font-bold"
                         id="header-user-avatar">
                        U
                    </div>

                    <button id="logout-button"
                        class="text-sm text-red-600 hover:text-red-800
                               font-medium">
                        Logout
                    </button>
                </div>

            </header>

            <!-- PAGE CONTENT -->
            <main class="p-5 lg:p-8">
                @yield('content')
            </main>

        </div>
    </div>

    <!-- MOBILE OVERLAY -->
    <div id="sidebar-overlay"
         class="hidden fixed inset-0 z-30 bg-black/50 lg:hidden">
    </div>

    @stack('scripts')

</body>
</html>
