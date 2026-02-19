@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<h1 class="text-xl font-medium mb-2">Dashboard — {{ $companyName }}</h1>
<p class="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">Aqui você gerencia como o bot funciona: respostas, horários e demais configurações.</p>

<ul class="space-y-2 text-sm">
    <li><a href="{{ route('company.bot.index') }}" class="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">Configurações do bot</a> — respostas, horários, etc.</li>
</ul>
@endsection
