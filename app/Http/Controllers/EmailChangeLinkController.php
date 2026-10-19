<?php

namespace App\Http\Controllers;

use App\Actions\Email\RespondToEmailChange;
use App\Models\EmailChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages behind the emailed links. They need no login (people open mail
 * on other devices); the signed, expiring URL is the authorization. Opening
 * a link only shows a page: the change is made by its button, so mail
 * scanners that open links cannot answer for anyone.
 */
class EmailChangeLinkController extends Controller
{
    private const RESULTS = [
        RespondToEmailChange::PENDING, RespondToEmailChange::COMPLETED,
        RespondToEmailChange::TAKEN, RespondToEmailChange::DENIED,
    ];

    public function __construct(private RespondToEmailChange $respond) {}

    /**
     * The new address's link: confirm that it receives mail.
     */
    public function showNew(Request $request, int $emailChange): Response
    {
        $change = $this->resolve($request, $emailChange);

        if ($change === null) {
            return $this->page('invalid');
        }

        if ($change->new_confirmed_at !== null) {
            return $this->page('waiting-for-current', $change);
        }

        return $this->page('confirm-new', $change, [
            'confirmUrl' => $change->signedUrl('new', 'confirm'),
        ]);
    }

    public function confirmNew(Request $request, int $emailChange): RedirectResponse
    {
        $change = $this->resolve($request, $emailChange);

        return $this->result($change === null ? null : $this->respond->confirmNew($change), $change, 'waiting-for-current');
    }

    /**
     * The current address's link: approve the change, or stop it.
     */
    public function showCurrent(Request $request, int $emailChange): Response
    {
        $change = $this->resolve($request, $emailChange);

        if ($change === null) {
            return $this->page('invalid');
        }

        if ($change->old_approved_at !== null) {
            return $this->page('waiting-for-new', $change);
        }

        return $this->page('review-current', $change, [
            'approveUrl' => $change->signedUrl('current', 'approve'),
            'denyUrl' => $change->signedUrl('current', 'deny'),
        ]);
    }

    public function approveCurrent(Request $request, int $emailChange): RedirectResponse
    {
        $change = $this->resolve($request, $emailChange);

        return $this->result($change === null ? null : $this->respond->approveOld($change), $change, 'waiting-for-new');
    }

    public function denyCurrent(Request $request, int $emailChange): RedirectResponse
    {
        $change = $this->resolve($request, $emailChange);

        return $this->result($change === null ? null : $this->respond->deny($change), $change, 'waiting-for-new');
    }

    /**
     * Where every button lands, so a refresh never repeats the action.
     */
    public function done(Request $request): Response
    {
        $state = $request->session()->get('emailChangeState');

        return $this->page(is_string($state) ? $state : 'invalid', null, [
            'newEmail' => $request->session()->get('emailChangeNewEmail'),
        ]);
    }

    /**
     * The request behind a link, or null when the link was tampered with,
     * has expired, or its request was replaced, cancelled or completed.
     */
    private function resolve(Request $request, int $id): ?EmailChange
    {
        if (! $request->hasValidSignature()) {
            return null;
        }

        $change = EmailChange::with('user')->find($id);

        return $change === null || $change->isExpired() ? null : $change;
    }

    /**
     * @param  string|null  $outcome  One of RespondToEmailChange's results.
     */
    private function result(?string $outcome, ?EmailChange $change, string $pendingState): RedirectResponse
    {
        $state = match (true) {
            $outcome === null || ! in_array($outcome, self::RESULTS, true) => 'invalid',
            $outcome === RespondToEmailChange::PENDING => $pendingState,
            default => $outcome,
        };

        return to_route('email-change.done')
            ->with('emailChangeState', $state)
            ->with('emailChangeNewEmail', $change?->new_email);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function page(string $state, ?EmailChange $change = null, array $extra = []): Response
    {
        return Inertia::render('auth/EmailChange', [
            'state' => $state,
            'newEmail' => $change?->new_email,
            'accountName' => $change?->user->full_name,
            ...$extra,
        ]);
    }
}
