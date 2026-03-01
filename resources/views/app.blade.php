<!DOCTYPE html>
{{-- Add class="dark" to <html> when user/brand prefers dark (Phase 0 ui-kit; toggle can be added later). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="flexiqueue">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'FlexiQueue') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    <div id="app" data-page="{{ json_encode($page) }}"></div>
</body>
</html>
