<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the signed-in user's dashboard. Each role's panels are added by
     * the feature that needs them.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'roleLabel' => $user->role->label(),
            'collegeName' => $user->college?->name,
        ]);
    }
}
