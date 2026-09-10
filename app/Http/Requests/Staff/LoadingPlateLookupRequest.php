<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Http\Requests\Concerns\ResolvesPlateInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * جستجوی پلاک روی لاین بارگیری.
 *
 * دسترسی‌اش دقیقاً همان چیزی است که LoadingController::authorizeLoading()
 * می‌خواهد: یا شروع بارگیری یا پایانش. پیدا کردن حواله از روی پلاک هیچ
 * اختیارِ تازه‌ای نمی‌دهد — تغییر وضعیت همچنان از Policy رد می‌شود.
 */
class LoadingPlateLookupRequest extends FormRequest
{
    use ResolvesPlateInput;

    public function authorize(): bool
    {
        return (bool) (
            $this->user()?->can(Permissions::QUEUE_START_LOADING)
            || $this->user()?->can(Permissions::QUEUE_COMPLETE_LOADING)
        );
    }
}
