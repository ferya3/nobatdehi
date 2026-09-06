<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Appointment\AppointmentStateMachine;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppointmentStateMachineTest extends TestCase
{
    private AppointmentStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new AppointmentStateMachine();
    }

    #[Test]
    public function it_allows_the_happy_path(): void
    {
        $path = [S::Booked, S::Waiting, S::Called, S::CheckedIn, S::Loading, S::Loaded, S::Completed];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(
                $this->machine->canTransition($path[$i], $path[$i + 1]),
                "انتقال {$path[$i]->value} → {$path[$i + 1]->value} باید مجاز باشد",
            );
        }
    }

    #[Test]
    public function it_blocks_going_backwards_without_permission(): void
    {
        $this->assertFalse($this->machine->canTransition(S::Loading, S::Booked));
        $this->assertFalse($this->machine->canTransition(S::Loading, S::CheckedIn));
        $this->assertFalse($this->machine->canTransition(S::Completed, S::Loaded));
    }

    #[Test]
    public function it_allows_a_defined_rollback_with_permission(): void
    {
        $this->assertTrue($this->machine->canTransition(S::Loading, S::CheckedIn, withRollbackPermission: true));
        $this->assertTrue($this->machine->canTransition(S::Completed, S::Loaded, withRollbackPermission: true));
    }

    #[Test]
    public function permission_never_invents_an_undefined_rollback(): void
    {
        // LOADING → BOOKED در جدول ROLLBACK نیست؛ حتی با دسترسی ویژه هم مجاز نیست.
        $this->assertFalse($this->machine->canTransition(S::Loading, S::Booked, withRollbackPermission: true));
    }

    #[Test]
    public function final_states_are_terminal(): void
    {
        foreach ([S::Completed, S::Rejected, S::Expired] as $status) {
            $this->assertSame([], $this->machine->allowedFrom($status));
        }
    }

    #[Test]
    public function it_never_allows_a_transition_to_itself(): void
    {
        foreach (S::cases() as $status) {
            $this->assertFalse($this->machine->canTransition($status, $status, withRollbackPermission: true));
        }
    }

    #[Test]
    public function rollback_transitions_require_the_rollback_permission(): void
    {
        $this->assertSame(
            AppointmentStateMachine::ROLLBACK_PERMISSION,
            $this->machine->permissionFor(S::Loading, S::CheckedIn),
        );

        $this->assertSame('queue.call', $this->machine->permissionFor(S::Waiting, S::Called));
    }

    #[Test]
    public function active_statuses_hold_capacity_and_final_ones_do_not(): void
    {
        $this->assertTrue(S::Waiting->isActive());
        $this->assertTrue(S::Loaded->isActive());
        $this->assertFalse(S::Completed->isActive());
        $this->assertFalse(S::Cancelled->isActive());
        $this->assertTrue(S::Completed->isFinal());
    }
}
