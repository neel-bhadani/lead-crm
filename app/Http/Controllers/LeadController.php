<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Http\Requests\LeadRequest;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\FollowUpScheduler;
use App\Services\LeadFollowUpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LeadController extends Controller
{
    use ResolvesFilters;

    public function __construct(
        private LeadFollowUpService $service,
        private FollowUpScheduler $scheduler
    ) {}

    public function index(Request $request)
    {
        $user    = $request->user();
        $filters = $this->filters($request);

        $leads = Lead::visibleTo($user)
            // eager load or a 25-row page fires 50 extra queries
            ->with([
                'project:id,name',
                // role too: the Assigned to column stacks it under the name
                'owner:id,first_name,last_name,role',
                'pendingTodo:id,lead_id,scheduled_at,type',
            ])
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('first_name', 'like', "%$s%")
                        ->orWhere('last_name', 'like', "%$s%")
                        ->orWhere('mobile_number', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%");
                });
            })
            ->when($filters['stage'] ?? null, fn($q, $v) => $q->where('stage', $v))
            ->when($filters['project_id'] ?? null, fn($q, $v) => $q->where('project_id', $v))
            ->when($filters['source'] ?? null, fn($q, $v) => $q->where('source', $v))
            ->when($filters['assigned_to'] ?? null, fn($q, $v) => $q->where('assigned_to', $v))
            ->when($filters['from'] ?? null, fn($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest()
            // no withQueryString(): the filters are in the session now, so a
            // page link carries nothing but its page number
            ->paginate(15)
            // LeadFormModal shows what a stage change would schedule; only this
            // side knows the working hours behind that answer
            ->through(fn($lead) => $lead->setAttribute(
                'follow_up_previews',
                $this->scheduler->previews($lead)
            ));

        return Inertia::render('Leads/Index', [
            'leads'   => $leads,
            'filters' => $filters,
            'options' => $this->options($user),
        ]);
    }

    /**
     * The filters this page owns. Leads have no default — an unfiltered list
     * is the starting point — so a missing key simply means "do not filter".
     *
     * `from` and `to` have no control on the page today; they are still owned
     * here so that a hand-typed one is validated like the rest and cleared by
     * the same Clear button.
     */
    private function filters(Request $request): array
    {
        return $this->resolveFilters($request, 'leads', [
            'search'      => ['sometimes', 'string', 'max:100'],
            'stage'       => ['sometimes', 'string', Rule::in(array_keys(config('crm.stages')))],
            'project_id'  => ['sometimes', 'integer', 'min:1'],
            'source'      => ['sometimes', 'string', Rule::in(array_keys(config('crm.sources')))],
            'assigned_to' => ['sometimes', 'integer', 'min:1'],
            'from'        => ['sometimes', 'string', 'date_format:Y-m-d'],
            'to'          => ['sometimes', 'string', 'date_format:Y-m-d'],
        ]);
    }

    public function store(LeadRequest $request)
    {
        $user = $request->user();

        // never take assigned_to from the form
        $owner = $user->isAdmin()
            ? User::where('role', 'telecaller')->where('is_active', true)->value('id')
            : $user->id;

        /*
         | Both writes or neither. onLeadCreated() gives the lead its first
         | pending to-do, and "an open lead always has one" is an invariant the
         | To-do page and the scheduler both lean on — a lead that committed
         | while its to-do failed would break it for good, and nothing in the
         | application would notice.
         */
        try {
            DB::transaction(function () use ($request, $user, $owner) {
                $lead = Lead::create($request->validated() + [
                    'assigned_to'      => $owner ?? $user->id,
                    'assigned_role'    => $user->isAdmin() ? 'telecaller' : $user->role,
                    'created_by'       => $user->id,
                    'stage_changed_at' => now(),
                    'last_activity_at' => now(),
                ]);

                $this->service->onLeadCreated($lead);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicateMobile();
        }

        return back()->with('success', 'Lead added and follow-up scheduled.');
    }

    public function show(Request $request, Lead $lead)
    {
        abort_unless(
            $request->user()->isAdmin() || $lead->assigned_to === $request->user()->id,
            403
        );

        return response()->json([
            'lead' => $lead->load([
                'project:id,name,location',
                'owner:id,first_name,last_name',
                'pendingTodo',
                'completedTodos.completer:id,first_name,last_name',
            ]),
        ]);
    }

    public function update(LeadRequest $request, Lead $lead)
    {
        abort_unless(
            $request->user()->isAdmin() || $lead->assigned_to === $request->user()->id,
            403
        );

        $data  = $request->validated();
        $stage = $data['stage'];
        unset($data['stage']);

        try {
            $lead->update($data);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicateMobile();
        }

        // route every stage change through the service so history
        // and the next task stay consistent
        $notice = null;

        if ($lead->stage !== $stage) {
            $notice = $this->service->noticeFor(
                $this->service->changeStage($lead, $stage, [
                    'reason'      => $request->input('reason'),
                    'booked_unit' => $request->input('booked_unit'),
                ])
            );
        }

        $response = back()->with('success', 'Lead updated.');

        // the service decided something on its own — say so
        return $notice ? $response->with('warning', $notice) : $response;
    }

    public function destroy(Lead $lead)
    {
        $lead->todos()->where('status', 'pending')->update(['status' => 'cancelled']);
        $lead->delete();

        return back()->with('success', 'Lead deleted.');
    }

    /**
     * The unique index is the real guarantee, and LeadRequest checks the same
     * rows it does — so reaching here means the narrow gap between that check
     * and the insert: two people adding the same number at the same moment.
     * The index wins, and the user gets the message they should have seen
     * rather than a 500.
     */
    private function duplicateMobile(): ValidationException
    {
        return ValidationException::withMessages([
            'mobile_number' => 'This number already exists for this project.',
        ]);
    }

    /**
     * Live duplicate check as the user types.
     * The unique index is the real guarantee; this is only for a friendly message.
     */
    public function checkDuplicate(Request $request)
    {
        $request->validate([
            'mobile_number' => ['required', 'digits:10'],
            'project_id'    => ['required', 'exists:projects,id'],
        ]);

        // withTrashed(), because the index counts deleted rows and so does the
        // rule in LeadRequest — a check that said "free" here and then failed
        // on submit is what made this look like a random 500
        $lead = Lead::withTrashed()
            ->where('mobile_number', $request->mobile_number)
            ->where('project_id', $request->project_id)
            ->when($request->lead_id, fn($q, $id) => $q->where('id', '!=', $id))
            ->with('owner:id,first_name,last_name')
            ->first();

        if (! $lead) {
            return response()->json(['exists' => false]);
        }

        if ($lead->trashed()) {
            return response()->json([
                'exists'  => true,
                'message' => 'This number belongs to a deleted lead on this project. Restore that lead instead of adding it again.',
            ]);
        }

        $user = $request->user();

        // a salesperson must not learn who owns someone else's lead
        $canSee = $user->isAdmin() || $lead->assigned_to === $user->id;

        return response()->json([
            'exists'  => true,
            'message' => $canSee
                ? "Already exists for this project — {$lead->full_name}, owned by {$lead->owner?->display_name}."
                : 'This number already exists for this project. Please contact the admin.',
        ]);
    }

    private function options($user): array
    {
        return [
            'stages'      => config('crm.stages'),
            'stageColors' => config('crm.stage_colors'),
            'sources'     => config('crm.sources'),
            'reasons'     => config('crm.lost_reasons'),
            'projects'    => Project::active()->get(['id', 'name']),
            'roleLabels'  => config('crm.role_labels'),
            /*
             | The create case is a different question from a stage change:
             | onLeadCreated() gives a new lead its first task straight away
             | rather than after an interval, so it gets its own preview.
             */
            'followUpPreviews' => $this->scheduler->previewsForNewLead(),
            'users'       => $user->isAdmin()
                ? User::whereIn('role', ['telecaller', 'salesperson'])
                ->get(['id', 'first_name', 'last_name'])
                : [],
        ];
    }
}
