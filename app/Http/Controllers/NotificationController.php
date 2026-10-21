<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's own notices: all of them on one page, and marking them read,
 * one (as it is opened) or all.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $unread = $request->boolean('unread');

        $page = ($unread ? $user->unreadNotifications() : $user->notifications())
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('notifications/Index', [
            'notifications' => [...$page->toArray(), 'data' => $page->getCollection()->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'kind' => $notification->data['kind'] ?? null,
                'message' => $notification->data['message'] ?? '',
                'url' => $notification->data['url'] ?? null,
                'read' => $notification->read_at !== null,
                'at' => $notification->created_at?->toIso8601String(),
            ])->values()->all()],
            'unreadOnly' => $unread,
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
