<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="flexiqueue" data-mode="light">
<head>
    <script>
        (function(){
            try{
                var t=localStorage.getItem('flexiqueue-theme');
                if(t==='dark'||t==='light')document.documentElement.setAttribute('data-mode',t);
            }catch(e){}
        })();
    </script>
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
