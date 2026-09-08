<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2f6885">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name') }}</title>

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="نوبت بارگیری">

    {{--
        فونت زودتر از کشف‌شدنش دانلود شود.

        بدون این، مرورگر تازه وقتی CSS را گرفت و parse کرد می‌فهمد فونتی هم
        لازم است — یعنی دو رفت‌وبرگشت بعد از HTML. crossorigin اجباری است:
        فونت‌ها همیشه در حالت CORS گرفته می‌شوند و بدون آن، preload دور
        ریخته و فونت دوباره دانلود می‌شود.
    --}}
    @if ($fontPreload = App\Support\Assets::fontPreloadUrl())
        <link rel="preload" href="{{ $fontPreload }}" as="font" type="font/woff2" crossorigin>
    @endif

    {{-- nonce از SecurityHeaders می‌آید؛ بدون آن CSP این اسکریپت را می‌بندد --}}
    @routes(nonce: Illuminate\Support\Facades\Vite::cspNonce())
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="font-sans">
    @inertia
</body>
</html>
