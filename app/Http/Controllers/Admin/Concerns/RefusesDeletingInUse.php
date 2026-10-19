<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Catalog records are only deleted while nothing uses them. The database
 * refuses anyway (restrictive foreign keys); this says why, in words.
 */
trait RefusesDeletingInUse
{
    /**
     * A redirect with an error toast when any count is above zero, else null.
     *
     * @param  array<string, int>  $uses  e.g. ['program' => 2, 'subject' => 41]
     */
    protected function refuseIfInUse(string $name, array $uses, string $hint = ''): ?RedirectResponse
    {
        $inUse = array_filter($uses, fn (int $count): bool => $count > 0);

        if ($inUse === []) {
            return null;
        }

        $parts = array_map(
            fn (string $thing, int $count): string => $count.' '.Str::plural($thing, $count),
            array_keys($inUse),
            $inUse,
        );

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => trim(__(':name still has :uses, so it can\'t be deleted.', ['name' => $name, 'uses' => Str::of(implode(', ', $parts))->replaceLast(', ', ' and ')]).' '.$hint),
        ]);

        return back();
    }
}
