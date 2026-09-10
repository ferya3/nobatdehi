<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff\Concerns;

use App\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * هر ایستگاه در هر لحظه روی یک حواله کار می‌کند — و آن حواله در نشانیِ
 * صفحه می‌نشیند، نه در پاسخِ یک POST.
 *
 * تا پیش از این، اسکن و جستجوی پلاک خودشان صفحه را رندر می‌کردند. نتیجه‌اش
 * این بود که نشانیِ مرورگر روی یک مسیرِ POST-only می‌نشست، و اولین back()
 * بعدی — مثلاً بعد از ثبت وزن — مرورگر را با GET به همان مسیر می‌فرستاد و
 * کاربر یک صفحه‌ی «۴۰۵ Method Not Allowed» می‌دید، بدون هیچ سرنخی.
 *
 * حالا هر POST به همان صفحه‌ی GET برمی‌گردد و حواله را در نشانی با خود
 * می‌برد. F5 دیگر فرم را دوباره نمی‌فرستد و رفرش‌های جزئی حواله را گم
 * نمی‌کنند.
 */
trait WorksOnOneWaybill
{
    /** حواله‌ای که ایستگاه همین حالا رویش کار می‌کند */
    private function currentWaybill(Request $request): ?Appointment
    {
        $ulid = $request->string('waybill')->trim()->toString();

        if ($ulid === '') {
            return null;
        }

        // محدود به کارخانه‌ی همین کاربر: نشانیِ دست‌کاری‌شده نباید حواله‌ی
        // کارخانه‌ی دیگری را روی صفحه بیاورد.
        return Appointment::where('ulid', $ulid)
            ->where('factory_id', $this->factory($request)->id)
            ->first();
    }

    /** پیغامی که ایستگاه بعد از برگشتن باید نشان بدهد */
    private function stationNotice(Request $request): ?string
    {
        $notice = $request->session()->get('station_notice');

        return is_string($notice) ? $notice : null;
    }

    /** برگشت به صفحه‌ی ایستگاه — همیشه به یک نشانیِ GET */
    private function toStation(?Appointment $appointment = null): RedirectResponse
    {
        return redirect()->route(
            $this->stationRoute(),
            $appointment === null ? [] : ['waybill' => $appointment->ulid],
        );
    }

    /** نام مسیرِ GET همین ایستگاه */
    abstract private function stationRoute(): string;
}
