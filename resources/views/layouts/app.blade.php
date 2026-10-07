<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $metaDescription ?? 'Humphrey Building Materials — your trusted supplier of quality cement, steel, timber, sand, tiles and more.' }}">

    <title>@yield('title', config('app.name'))</title>

    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body data-page="@yield('page', '')">

    @include('partials.header')

    {{-- Flash messages: one-time after a form submit. Without JS they render
         as static bars; with JS they become temporary auto-dismissing toasts
         (js/app.js reads the data-* attributes once and removes the carrier,
         so a refresh never replays an old message). --}}
    @if (session('status') || session('error'))
        <div id="flash-data"
             data-status="{{ session('status') }}"
             data-error="{{ session('error') }}">
            @if (session('status'))
                <div class="flash flash-success" role="status">
                    <div class="container">{{ session('status') }}</div>
                </div>
            @endif
            @if (session('error'))
                <div class="flash flash-error" role="alert">
                    <div class="container">{{ session('error') }}</div>
                </div>
            @endif
        </div>
    @endif
    {{-- Validation errors stay visible until the form is fixed. --}}
    @if ($errors->any())
        <div class="flash flash-error" role="alert">
            <div class="container">
                <strong>Please fix the following:</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <main>
        @yield('content')
    </main>

    @include('partials.footer')

    <script src="{{ asset('js/app.js') }}"></script>
    @stack('scripts')
</body>
</html>
