@extends('layouts.app')

@section('title', $company->name)

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.empresas.index') }}" class="text-sm text-[#706f6c] dark:text-[#A1A09A] hover:underline">← Empresas</a>
</div>
<h1 class="text-xl font-medium mb-2">{{ $company->name }}</h1>
<p class="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">Informações e uso da empresa.</p>

<section class="mb-8">
    <h2 class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Informações</h2>
    <ul class="text-sm space-y-1">
        <li>ID: {{ $company->id }}</li>
        <li>Nome: {{ $company->name }}</li>
        <li>Meta Phone Number ID: {{ $company->meta_phone_number_id ?: '—' }}</li>
        <li>Token configurado: {{ $company->hasMetaCredentials() ? 'Sim' : 'Não' }}</li>
    </ul>
</section>

<section class="mb-8">
    <h2 class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Uso</h2>
    <p class="text-sm">Total de conversas: <strong>{{ $company->conversations_count }}</strong></p>
    @if($company->conversations->isNotEmpty())
        <p class="text-sm text-[#706f6c] mt-2">Últimas conversas (até 10):</p>
        <ul class="mt-1 text-sm space-y-1">
            @foreach($company->conversations as $conv)
                <li>{{ $conv->customer_phone }} — {{ $conv->status }} ({{ $conv->created_at->format('d/m/Y H:i') }})</li>
            @endforeach
        </ul>
    @endif
</section>
@endsection
