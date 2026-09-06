<?php

use Illuminate\Support\Facades\Schedule;

// اسلات‌های افق نوبت‌دهی هر شب جلو می‌روند
Schedule::command('slots:generate')->dailyAt('00:10');

// نوبت‌های سررسیدگذشته بسته می‌شوند تا ظرفیت و شمارش نوبت فعال درست بماند
Schedule::command('appointments:expire')->dailyAt('00:20');
