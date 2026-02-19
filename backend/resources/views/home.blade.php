@extends('layouts.app')

@section('title', 'Entrar')

@section('content')
<div class="max-w-md mx-auto">
    <h1 class="text-xl font-medium mb-2">Entrar</h1>
    <p class="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">Escolha com qual perfil acessar (login real você cria depois).</p>

    <div class="space-y-3">
        <a href="{{ route('entrar-como.admin') }}" class="block w-full px-4 py-3 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] hover:border-[#19140035] dark:hover:border-[#62605b] text-left font-medium">
            Minha empresa (admin) — gerenciar tudo
        </a>
        @forelse($companies as $company)
            <a href="{{ route('entrar-como.empresa', ['id' => $company->id]) }}" class="block w-full px-4 py-3 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615] hover:border-[#19140035] dark:hover:border-[#62605b] text-left">
                {{ $company->name }} — configurar bot
            </a>
        @empty
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Nenhuma empresa cadastrada. Cadastre pelo admin ou pelo banco.</p>
        @endforelse
    </div>
</div>
@endsection
