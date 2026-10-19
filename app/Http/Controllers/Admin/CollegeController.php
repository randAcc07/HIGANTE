<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CollegeRequest;
use App\Models\College;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CollegeController extends Controller
{
    use RefusesDeletingInUse;

    public function index(): Response
    {
        return Inertia::render('admin/colleges/Index', [
            'colleges' => College::query()
                ->withCount(['programs', 'subjects', 'users as staff_count' => fn ($users) => $users->where('role', '!=', UserRole::Learner)])
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function store(CollegeRequest $request): RedirectResponse
    {
        $college = College::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $college->name])]);

        return to_route('admin.colleges.show', $college);
    }

    public function show(College $college): Response
    {
        return Inertia::render('admin/colleges/Show', [
            'college' => $college->loadCount([
                'subjects',
                'users as staff_count' => fn ($users) => $users->where('role', '!=', UserRole::Learner),
                'users as learner_count' => fn ($users) => $users->where('role', UserRole::Learner),
            ]),
            'programs' => $college->programs()->withCount(['learners', 'sections'])->orderBy('code')->get(),
        ]);
    }

    public function update(CollegeRequest $request, College $college): RedirectResponse
    {
        $college->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('College updated.')]);

        return back();
    }

    public function destroy(College $college): RedirectResponse
    {
        $refusal = $this->refuseIfInUse($college->name, [
            'program' => $college->programs()->count(),
            'subject' => $college->subjects()->count(),
            'account' => $college->users()->count(),
            'calendar event' => $college->calendarEvents()->count(),
        ]);

        if ($refusal !== null) {
            return $refusal;
        }

        $college->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $college->name])]);

        return to_route('admin.colleges.index');
    }
}
