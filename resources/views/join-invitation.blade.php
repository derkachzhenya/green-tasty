<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>You're invited · Green Tasty</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/guest.ts'])
</head>
<body class="green-tasty min-h-screen bg-background font-[Poppins,sans-serif] text-foreground">
    <main class="mx-auto flex min-h-screen max-w-xl items-center p-4">
        <section class="w-full rounded-3xl bg-white p-8 shadow-lg sm:p-10">
            <img src="/images/green-tasty/logo.png" alt="Green Tasty" class="mb-8 w-36">
            <h1 class="mb-3 text-2xl font-medium">You’re Invited!</h1>
            <p class="mb-6 text-sm leading-6">Enter your name to create your own pre-order for this reservation.</p>
            <p class="mb-6 border-y py-4 text-sm leading-6">{{ $reservation->restaurant->address }}<br>{{ $reservation->reserved_at->setTimezone('Asia/Tbilisi')->format('M j, Y · H:i') }} · {{ $reservation->guest_count }} Guests</p>
            <form method="post" action="{{ request()->fullUrl() }}" class="space-y-4">
                @csrf
                <label for="join-name" class="block text-sm">Enter Your Name</label>
                <input id="join-name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" class="min-h-12 w-full rounded-md border px-3" placeholder="e.g. Alex">
                @error('name')<p role="alert" class="text-sm text-destructive">{{ $message }}</p>@enderror
                <button class="min-h-12 w-full rounded-md bg-primary px-4 font-medium text-white">Browse Menu</button>
            </form>
        </section>
    </main>
</body>
</html>
