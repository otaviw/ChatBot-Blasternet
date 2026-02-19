@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<h1 class="text-xl font-medium mb-2">Dashboard — Minha empresa</h1>
<p class="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">Você está como administrador. Aqui você gerencia empresas, usos e informações.</p>

<ul class="space-y-2 text-sm">
    <li><a href="{{ route('admin.empresas.index') }}" class="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">Empresas</a> — listar, ver informações e uso de cada uma</li>
</ul>
@endsection
