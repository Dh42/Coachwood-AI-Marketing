@php($tz = app(\App\Settings\Settings::class)->get('platform.timezone'))

<div class="max-w-5xl space-y-8">
    <section data-tour="users-invite" class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="font-semibold">Invite someone</h2>
        <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">Company addresses sign in with Google. Anyone else gets a link to set a password.</p>

        <form wire:submit="invite" class="mt-4 flex flex-wrap items-start gap-3">
            <div class="min-w-64 flex-1">
                <x-form.input type="email" wire:model="inviteEmail" placeholder="name@example.com" aria-label="Email" required />
                @error('inviteEmail') <p class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="w-48">
                <x-form.select wire:model="inviteRole" aria-label="Role">
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </x-form.select>
                @error('inviteRole') <p class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <x-button type="submit" wire:loading.attr="disabled" wire:target="invite">Send invitation</x-button>
        </form>

        <details class="mt-4 text-zinc-600 dark:text-zinc-400">
            <summary class="cursor-pointer select-none">What each role can do</summary>
            <dl class="mt-2 grid grid-cols-[8rem_1fr] gap-y-1">
                @foreach ($roles as $role)
                    <dt class="font-medium text-zinc-900 dark:text-zinc-100">{{ $role->label() }}</dt>
                    <dd>{{ $role->description() }}</dd>
                @endforeach
            </dl>
        </details>
    </section>

    <x-form.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name or email" aria-label="Search users" class="max-w-sm" />

    @if ($invitations->isNotEmpty())
        <section>
            <h2 class="mb-2 font-semibold">Waiting to accept</h2>
            <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
                <table class="w-full text-left">
                    <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 font-medium">Email</th>
                            <th class="px-4 py-2 font-medium">Role</th>
                            <th class="px-4 py-2 font-medium">Invited by</th>
                            <th class="px-4 py-2 font-medium">Link expires</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        @foreach ($invitations as $invitation)
                            <tr wire:key="invitation-{{ $invitation->id }}">
                                <td class="px-4 py-2.5">{{ $invitation->email }}</td>
                                <td class="px-4 py-2.5">{{ $invitation->role->label() }}</td>
                                <td class="px-4 py-2.5">{{ $invitation->inviter?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    @if ($invitation->expires_at->isPast())
                                        <span class="text-red-600 dark:text-red-400">Expired</span>
                                    @else
                                        <span title="{{ $invitation->expires_at->timezone($tz)->format('M j, Y g:i A T') }}">{{ $invitation->expires_at->diffForHumans() }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <x-button size="sm" variant="ghost" wire:click="resend({{ $invitation->id }})">Resend</x-button>
                                    <x-button size="sm" variant="ghost" wire:click="revoke({{ $invitation->id }})">Cancel</x-button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section>
        <h2 class="mb-2 font-semibold">People</h2>
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="w-full text-left">
                <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Role</th>
                        <th class="px-4 py-2 font-medium">Last signed in</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}" @class(['text-zinc-400 dark:text-zinc-500' => $user->isDisabled()])>
                            <td class="px-4 py-2.5">
                                <div class="font-medium">{{ $user->name }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</div>
                            </td>
                            <td class="px-4 py-2.5">
                                <x-form.select wire:key="role-{{ $user->id }}-{{ $redraw }}" class="w-44 py-1.5" aria-label="Role for {{ $user->name }}"
                                               wire:change="changeRole({{ $user->id }}, $event.target.value)">
                                    <option value="" @selected($user->platformRole() === null)>{{ $user->roleAssignments->count() > ($user->platformRole() ? 1 : 0) ? 'Brand roles only' : 'No access' }}</option>
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->value }}" @selected($user->platformRole() === $role)>{{ $role->label() }}</option>
                                    @endforeach
                                </x-form.select>
                                @foreach ($user->roleAssignments->where('brand_id', '!=', \App\Models\RoleAssignment::PLATFORM) as $assignment)
                                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $assignment->role->label() }} in {{ $brands[$assignment->brand_id] ?? 'a removed brand' }}
                                    </div>
                                @endforeach
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($user->isDisabled())
                                    Turned off
                                @elseif ($user->last_login_at)
                                    <span title="{{ $user->last_login_at->timezone($tz)->format('M j, Y g:i A T') }}">{{ $user->last_login_at->diffForHumans() }}</span>
                                @else
                                    Never
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                @if ($user->isDisabled())
                                    <x-button size="sm" variant="ghost" wire:click="enable({{ $user->id }})">Turn on</x-button>
                                @elseif (! $user->is(auth()->user()))
                                    <span x-data="{ confirming: false }">
                                        <x-button size="sm" variant="ghost" x-show="! confirming" @click="confirming = true">Turn off</x-button>
                                        <span x-cloak x-show="confirming" class="inline-flex items-center gap-1">
                                            <span class="text-xs">They won't be able to sign in.</span>
                                            <x-button size="sm" variant="danger" wire:click="disable({{ $user->id }})">Turn off</x-button>
                                            <x-button size="sm" variant="ghost" @click="confirming = false">Keep</x-button>
                                        </span>
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-zinc-500 dark:text-zinc-400">No one matches that search.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
