<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\AppointmentStateMachine;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;

/**
 * هر عملیات روی نوبت، دسترسی مخصوص خودش را دارد.
 * «کاربر لاگین است» هیچ‌وقت به‌تنهایی مجوز نیست.
 */
class AppointmentPolicy
{
    public function __construct(private readonly AppointmentStateMachine $machine) {}

    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::APPOINTMENTS_VIEW);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->can(Permissions::APPOINTMENTS_VIEW)
            && $this->sameFactory($user, $appointment);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::APPOINTMENTS_CREATE);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $user->can(Permissions::APPOINTMENTS_CANCEL)
            && $this->sameFactory($user, $appointment)
            && $appointment->status->isActive();
    }

    /** آیا این کاربر می‌تواند نوبت را به وضعیت مشخصی ببرد؟ */
    public function transition(User $user, Appointment $appointment, AppointmentStatus $to): bool
    {
        if (! $this->sameFactory($user, $appointment)) {
            return false;
        }

        $canRollback = $user->can(Permissions::APPOINTMENTS_ROLLBACK);

        if (! $this->machine->canTransition($appointment->status, $to, $canRollback)) {
            return false;
        }

        return $user->can($this->machine->permissionFor($appointment->status, $to));
    }

    /**
     * کاربر بدون کارخانه (مدیر ارشد سامانه) به همه دسترسی دارد؛ بقیه فقط به
     * کارخانه‌ی خودشان.
     */
    private function sameFactory(User $user, Appointment $appointment): bool
    {
        return $user->factory_id === null || $user->factory_id === $appointment->factory_id;
    }
}
