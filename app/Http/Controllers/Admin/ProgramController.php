<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RefusesDeletingInUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProgramRequest;
use App\Models\College;
use App\Models\Program;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProgramController extends Controller
{
    use RefusesDeletingInUse;

    public function store(ProgramRequest $request, College $college): RedirectResponse
    {
        $college->programs()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Program added.')]);

        return back();
    }

    public function update(ProgramRequest $request, Program $program): RedirectResponse
    {
        $program->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Program updated.')]);

        return back();
    }

    public function destroy(Program $program): RedirectResponse
    {
        $refusal = $this->refuseIfInUse($program->code, [
            'learner' => $program->learners()->count(),
            'section' => $program->sections()->count(),
        ]);

        if ($refusal !== null) {
            return $refusal;
        }

        $program->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Program deleted.')]);

        return back();
    }
}
