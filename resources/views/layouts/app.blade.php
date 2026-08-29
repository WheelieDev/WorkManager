<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>

    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @if (config('database.sandbox'))
            <div class="[grid-area:header] flex items-center justify-center gap-2 bg-amber-400 px-4 py-1 text-center text-xs font-medium text-amber-950">
                Sandbox database &mdash; nothing here is real.
                <code class="rounded bg-amber-950/10 px-1.5 py-0.5">php artisan db:use main</code>
            </div>
        @endif

        @auth
            <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" inset="left" />

                <flux:sidebar.brand
                    :href="route('dashboard')"
                    :name="config('app.name')"
                    class="px-2"
                />

                <flux:navlist variant="outline">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        Dashboard
                    </flux:navlist.item>

                    <flux:navlist.item icon="clock" :href="route('timesheet')" :current="request()->routeIs('timesheet')" wire:navigate>
                        Timesheet
                    </flux:navlist.item>

                    <flux:navlist.item icon="sun" :href="route('leave')" :current="request()->routeIs('leave')" wire:navigate>
                        Leave
                    </flux:navlist.item>

                    <flux:navlist.item icon="calendar-days" :href="route('patterns.index')" :current="request()->routeIs('patterns.*')" wire:navigate>
                        Working patterns
                    </flux:navlist.item>

                    <flux:navlist.item icon="cog-6-tooth" :href="route('settings')" :current="request()->routeIs('settings')" wire:navigate>
                        Settings
                    </flux:navlist.item>
                </flux:navlist>

                <flux:spacer />

                <flux:navlist variant="outline">
                    <form action="{{ route('logout') }}" method="POST" class="w-full">
                        @csrf
                        <flux:navlist.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            Sign out
                        </flux:navlist.item>
                    </form>
                </flux:navlist>
            </flux:sidebar>

            <flux:header class="lg:hidden">
                <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            </flux:header>
        @endauth

        <flux:main>
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>
