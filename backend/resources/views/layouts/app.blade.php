<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] min-h-screen">
    <header class="border-b border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615]">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="{{ session()->has('role') ? route('dashboard') : route('home') }}" class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                {{ config('app.name') }}
            </a>
            @if(session()->has('role'))
            <nav class="flex items-center gap-4 text-sm">
                @if(session('role') === 'admin')
                    <a href="{{ route('admin.empresas.index') }}" class="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white">Empresas</a>
                @endif
                @if(session('role') === 'company')
                    <a href="{{ route('company.bot.index') }}" class="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white">Config. do bot</a>
                @endif
                <a href="{{ route('sair') }}" class="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white">Sair</a>
            </nav>
            @endif
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">
        @if(session('success'))
            <p class="mb-4 text-sm text-green-600 dark:text-green-400">{{ session('success') }}</p>
        @endif
        @yield('content')
    </main>
</body>
</html>
