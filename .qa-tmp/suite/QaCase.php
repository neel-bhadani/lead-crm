<?php
namespace QA;

use App\Models\Lead;
use App\Models\User;
use Tests\TestCase;

abstract class QaCase extends TestCase
{
    protected function u(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }
    protected function admin(): User { return $this->u('admin@crm.test'); }
    protected function tele(): User { return $this->u('tele@crm.test'); }
    protected function sales(): User { return $this->u('sales@crm.test'); }
    protected function sales2(): User { return $this->u('sales2@crm.test'); }

    /** @return array{zero:int,multi:int,terminal:int} */
    protected function invariants(): array
    {
        return [
            'zero'  => Lead::open()->doesntHave('pendingTodo')->count(),
            'multi' => Lead::open()
                ->withCount(['todos as p' => fn ($q) => $q->where('status', 'pending')])
                ->get()->filter(fn ($l) => $l->p > 1)->count(),
            'terminal' => Lead::whereIn('stage', config('crm.terminal_stages'))
                ->whereHas('todos', fn ($q) => $q->where('status', 'pending'))->count(),
        ];
    }

    protected function say(string $s): void { fwrite(STDERR, $s . PHP_EOL); }

    protected function inv(string $label): void
    {
        $i = $this->invariants();
        $this->say(sprintf('INV %-40s zero=%d multi=%d terminalPending=%d', $label, $i['zero'], $i['multi'], $i['terminal']));
    }
}
