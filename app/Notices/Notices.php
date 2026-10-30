<?php

namespace App\Notices;

use App\Budgets\Budgets;
use App\Models\CreativeRequest;
use App\Models\Generation;
use App\Models\User;
use App\Notifications\RequestNotice;
use App\Review\Shortlist;
use Illuminate\Support\Str;

/**
 * Tells people what happened to their requests: a notice under the bell,
 * and an email for the events they chose. Nothing here decides who may see
 * a request; a notice goes to the person it's about.
 */
class Notices
{
    /**
     * The planner stopped and the request's owner has to act: it asked a
     * question, the plan is ready to confirm, or planning failed.
     */
    public function needsYou(CreativeRequest $request): void
    {
        $name = $this->name($request);
        $questions = count($request->questions ?? []);

        [$title, $body] = match ($request->status) {
            CreativeRequest::ASKING => [
                $questions === 1 ? "The planner has a question about \"{$name}\"" : "The planner has {$questions} questions about \"{$name}\"",
                $questions === 1 ? 'Answer it and the plan is written.' : 'Answer them and the plan is written.',
            ],
            CreativeRequest::PLANNED => [
                "The plan for \"{$name}\" is ready",
                'Read it over, then press "Confirm and run"'.($request->estimated_cost !== null ? ', for about $'.Budgets::money((float) $request->estimated_cost) : '').'.',
            ],
            CreativeRequest::FAILED => [
                "The planner couldn't finish \"{$name}\"",
                $request->error ?: 'It stopped before it finished.',
            ],
            default => [null, null],
        };

        if ($title !== null) {
            $this->send($request->user, RequestNotice::NEEDS_YOU, $title, $body, $request);
        }
    }

    /**
     * A run finished: the shortlist is ready, or nothing came back.
     */
    public function finished(CreativeRequest $request): void
    {
        $name = $this->name($request);

        if ($request->status === CreativeRequest::DONE) {
            $made = $request->outputs()->count();
            $delivered = $request->outputs()->where('status', Shortlist::SHORTLISTED)->count();
            $this->send($request->user, RequestNotice::DONE, "\"{$name}\" is ready", "{$delivered} of {$made} outputs passed review and are on the shortlist, for \$".Budgets::money((float) $request->actual_cost).'.', $request);
        } elseif ($request->status === CreativeRequest::FAILED) {
            $this->send($request->user, RequestNotice::DONE, "\"{$name}\" failed", $request->error ?: 'No image came back.', $request);
        }
    }

    /**
     * A change, or a shape run again, finished: to whoever asked for it.
     */
    public function generationFinished(Generation $generation): void
    {
        $request = $generation->request;
        $name = $this->name($request);
        $outputs = $generation->outputs()->get();
        $ok = $outputs->where('status', Shortlist::SHORTLISTED)->count();

        if ($generation->isChange()) {
            $version = $outputs->first();
            $title = $outputs->isEmpty() ? "Your change on \"{$name}\" failed" : "Your change on \"{$name}\" is ready";
            $body = $outputs->isEmpty()
                ? ($generation->error ?: 'No image came back.')
                : "\"{$generation->instruction}\": scored {$version->score}, ".($version->status === Shortlist::SHORTLISTED ? 'and is on the shortlist.' : 'and was rejected: '.Str::lcfirst((string) $version->reason));
        } else {
            $title = $outputs->isEmpty() ? "The {$generation->ratio} shape of \"{$name}\" couldn't run again" : "The {$generation->ratio} shape of \"{$name}\" has run again";
            $body = $outputs->isEmpty() ? ($generation->error ?: 'No image came back.') : "{$ok} of {$outputs->count()} new images passed review and are on the shortlist.";
        }

        $this->send($generation->user ?? $request->user, RequestNotice::CHANGES, $title, $body, $request);
    }

    /**
     * The person has the request open, so its notices count as read, and
     * any email still waiting on them isn't sent.
     */
    public function seen(User $user, CreativeRequest $request): void
    {
        $user->unreadNotifications()->where('data->request_id', (string) $request->id)->update(['read_at' => now()]);
    }

    private function send(?User $to, string $event, string $title, string $body, CreativeRequest $request): void
    {
        if (! $to || $to->isDisabled()) {
            return;
        }

        $to->notify(new RequestNotice($event, $title, $body, route('requests.show', [$request->brand, $request]), $request->id, $request->brand_id, $request->brand->name));
    }

    private function name(CreativeRequest $request): string
    {
        return $request->title ?: Str::limit(trim((string) $request->brief), 60);
    }
}
