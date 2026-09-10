<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Http\Requests\Concerns\ResolvesPlateInput;
use Illuminate\Foundation\Http\FormRequest;

/** جستجوی پلاک در نگهبانی */
class PlateLookupRequest extends FormRequest
{
    use ResolvesPlateInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::QUEUE_CHECKIN);
    }
}
