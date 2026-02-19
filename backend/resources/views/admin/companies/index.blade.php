@extends('layouts.app')

@section('title', 'Empresas')

@section('content')
<h1 class="text-xl font-medium mb-2">Empresas</h1>
<p class="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">Lista de empresas com acesso. Clique para ver informações e uso.</p>

@if($companies->isEmpty())
    <p class="text-sm text-[#706f6c]">Nenhuma empresa cadastrada.</p>
@else
    <ul class="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A] overflow-hidden">
        @foreach($companies as $company)
            <li>
                <a href="{{ route('admin.empresas.show', $company) }}" class="block px-4 py-3 hover:bg-[#FDFDFC] dark:hover:bg-[#161615]">
                    <span class="font-medium">{{ $company->name }}</span>
                    <span class="text-sm text-[#706f6c] dark:text-[#A1A09A] ml-2">— {{ $company->conversations_count }} conversa(s)</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
@endsection
