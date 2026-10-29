<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <meta name="robots" content="noindex, nofollow">
        <title>Your pre-order · Green Tasty</title>
        <link rel="icon" href="/favicon.svg?v=green-tasty-1" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=green-tasty-1">
        @fonts
        @vite(['resources/css/app.css', 'resources/js/guest.ts'])
    </head>
    <body class="font-sans antialiased">
        <div id="guest-app" data-invalid="{{ $invalid ? 'true' : 'false' }}" @isset($ownerReservationId) data-owner-reservation-id="{{ $ownerReservationId }}" @endisset></div>
        <noscript>
            <p>{{ $invalid ? 'Invitation unavailable. Ask the reservation owner for a new link.' : 'Enable JavaScript to browse the menu and save your selections.' }}</p>
        </noscript>
    </body>
</html>
