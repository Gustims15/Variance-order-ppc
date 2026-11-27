<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | PEMI-Variance Order PPC</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-100">

    <div class="min-h-screen grid lg:grid-cols-2">

        <!-- LEFT PANEL -->s
        <div class="hidden lg:flex bg-slate-950 text-white
                    flex-col justify-between p-12">

            <div>
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-blue-600
                                flex items-center justify-center
                                font-bold text-2xl">
                        P
                    </div>

                    <div>
                        <h1 class="font-bold text-xl">PEMI</h1>
                        <p class="text-sm text-slate-400">
                            Variance Order PPC
                        </p>
                    </div>
                </div>
            </div>

            <div class="max-w-lg">
                <p class="text-blue-400 text-sm font-semibold
                          uppercase tracking-widest mb-4">
                    Production Planning & Control
                </p>

                <h2 class="text-4xl font-bold leading-tight">
                    Monitor your production variance
                    with confidence.
                </h2>

                <p class="mt-6 text-slate-400 leading-relaxed">
                    Integrated production planning,
                    Forecast vs Actual analysis,
                    and variance monitoring in one platform.
                </p>

                <div class="mt-10 grid grid-cols-2 gap-4">

                    <div class="bg-slate-900 border border-slate-800
                                rounded-xl p-4">
                        <p class="text-2xl font-bold">01</p>
                        <p class="text-sm text-slate-400 mt-2">
                            Data Integration
                        </p>
                    </div>

                    <div class="bg-slate-900 border border-slate-800
                                rounded-xl p-4">
                        <p class="text-2xl font-bold">02</p>
                        <p class="text-sm text-slate-400 mt-2">
                            Variance Analysis
                        </p>
                    </div>

                </div>
            </div>

            <p class="text-xs text-slate-600">
                PEMI-ISUZU | Variance Order PPC System
            </p>

        </div>

        <!-- LOGIN PANEL -->
        <div class="flex items-center justify-center p-6 sm:p-12">

            <div class="w-full max-w-md">

                <div class="lg:hidden mb-10 text-center">
                    <div class="inline-flex w-14 h-14 rounded-xl
                                bg-blue-600 text-white items-center
                                justify-center font-bold text-2xl">
                        P
                    </div>

                    <h1 class="mt-3 font-bold text-xl">
                        PEMI-PPC
                    </h1>

                    <p class="text-sm text-slate-500">
                        Variance Order PPC
                    </p>
                </div>

                <div class="bg-white rounded-2xl shadow-sm
                            border border-slate-200 p-7 sm:p-9">

                    <div class="mb-8">
                        <h2 class="text-2xl font-bold text-slate-900">
                            Welcome 
                        </h2>

                        <p class="text-sm text-slate-500 mt-2">
                            Sign in to access your dashboard.
                        </p>
                    </div>

                    <div id="login-error"
                         class="hidden mb-5 p-3 rounded-lg
                                bg-red-50 border border-red-200
                                text-red-700 text-sm">
                    </div>

                    <form id="login-form" class="space-y-5">

                        <div>
                            <label for="email"
                                class="block text-sm font-semibold
                                       text-slate-700 mb-2">
                                Email Address
                            </label>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                required
                                autocomplete="username"
                                placeholder="Enter your email"
                                class="w-full px-4 py-3 rounded-lg
                                       border border-slate-300
                                       outline-none
                                       focus:ring-2 focus:ring-blue-500
                                       focus:border-blue-500">
                        </div>

                        <div>
                            <label for="password"
                                class="block text-sm font-semibold
                                       text-slate-700 mb-2">
                                Password
                            </label>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                class="w-full px-4 py-3 rounded-lg
                                       border border-slate-300
                                       outline-none
                                       focus:ring-2 focus:ring-blue-500
                                       focus:border-blue-500">
                        </div>

                        <button
                            type="submit"
                            id="login-submit"
                            class="w-full py-3 rounded-lg
                                   bg-blue-600 hover:bg-blue-700
                                   text-white font-semibold
                                   transition disabled:opacity-60">
                            Sign In
                        </button>

                    </form>

                    <p class="text-center text-xs text-slate-400 mt-8">
                        Authorized personnel only.
                    </p>

                </div>

                <p class="text-center text-xs text-slate-400 mt-6">
                    © 2026 PEMI. All rights reserved.
                </p>

            </div>

        </div>

    </div>

</body>
</html>
