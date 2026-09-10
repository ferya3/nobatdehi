<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Http\Requests\Concerns\ResolvesPlateInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * جستجوی پلاک در باسکول.
 *
 * دسترسی‌اش weighing.record است و نه queue.checkin: باسکول‌بان دسترسی
 * نگهبانی ندارد و نباید هم داشته باشد.
 */
class WeighbridgePlateLookupRequest extends FormRequest
{
    use ResolvesPlateInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::WEIGHING_RECORD);
    }
}
