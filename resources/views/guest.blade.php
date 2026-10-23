<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <meta name="robots" content="noindex, nofollow">
        <title>Your pre-order · Green Tasty</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @fonts
        @vite(['resources/css/app.css', 'resources/js/guest.ts'])
    </head>
    <body class="font-sans antialiased">
        <div id="guest-app" data-invalid="{{ $invalid ? 'true' : 'false' }}"></div>
        <noscript>
            <p>{{ $invalid ? 'Invitation unavailable. Ask the reservation owner for a new link.' : 'Enable JavaScript to browse the menu and save your selections.' }}</p>
        </noscript>
    </body>
</html>
