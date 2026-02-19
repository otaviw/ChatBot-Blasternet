@extends('layouts.app')

@section('title', 'Configurações do bot')

@section('content')
<h1 class="text-xl font-medium mb-2">Configurações do bot — {{ $company->name }}</h1>
<p class="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">Defina como o bot responde, horários de atendimento e demais opções. (Você pode ir ajustando os campos depois.)</p>

<div class="space-y-8 max-w-2xl">
    <section class="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
        <h2 class="font-medium mb-2">Respostas</h2>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Mensagens padrão, respostas automáticas, menu. (Formulários e salvamento você implementa depois.)</p>
    </section>
    <section class="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
        <h2 class="font-medium mb-2">Horários</h2>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Horário de atendimento, mensagem fora do horário. (Campos e regras você implementa depois.)</p>
    </section>
</div>
@endsection
