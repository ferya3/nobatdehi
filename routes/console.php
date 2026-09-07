<?php

use Illuminate\Support\Facades\Schedule;

// اسلات‌های افق نوبت‌دهی هر شب جلو می‌روند
Schedule::command('slots:generate')->dailyAt('00:10');

// نوبت‌های سررسیدگذشته بسته می‌شوند تا ظرفیت و شمارش نوبت فعال درست بماند
Schedule::command('appointments:expire')->dailyAt('00:20');

// مهلت حضور که گذشت، ظرفیت همان روز باید آزاد شود — نه فردا شب
Schedule::command('appointments:no-show')->everyFiveMinutes()->withoutOverlapping();
