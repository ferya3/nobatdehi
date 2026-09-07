<?php

use Illuminate\Support\Facades\Schedule;

// اسلات‌های افق نوبت‌دهی هر شب جلو می‌روند
Schedule::command('slots:generate')->dailyAt('00:10');

// نوبت‌های سررسیدگذشته بسته می‌شوند تا ظرفیت و شمارش نوبت فعال درست بماند
Schedule::command('appointments:expire')->dailyAt('00:20');

// مهلت حضور که گذشت، ظرفیت همان روز باید آزاد شود — نه فردا شب
Schedule::command('appointments:no-show')->everyFiveMinutes()->withoutOverlapping();

// کامیونی که روی لاین مانده، تا کسی خبردار نشود همان‌جا می‌ماند
Schedule::command('loading:alert-delays')->everyTenMinutes()->withoutOverlapping();

// عکس‌های دوربین پلاک‌خوان جمع می‌شوند تا دیسک پر شود — مگر کسی پاکشان کند
Schedule::command('plate-readings:prune')->dailyAt('03:30')->withoutOverlapping();

// پل باسکول هر تغییر وزن را می‌فرستد؛ بیشترشان لحظه‌ی بالا رفتن روی سکو هستند
Schedule::command('scale-readings:prune')->dailyAt('03:40')->withoutOverlapping();

// لاگ امنیتی تا وقتی کسی نگاهش نکند فقط یک جدول است
Schedule::command('security:alert')->everyFifteenMinutes()->withoutOverlapping();
