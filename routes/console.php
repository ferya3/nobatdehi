<?php

use Illuminate\Support\Facades\Schedule;

// اسلات‌های افق نوبت‌دهی هر شب جلو می‌روند
Schedule::command('slots:generate')->dailyAt('00:10');
