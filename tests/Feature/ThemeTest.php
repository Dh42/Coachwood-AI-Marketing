<?php

use App\Auth\Role;

it('keeps the theme when Livewire swaps pages', function () {
    signInAs(Role::Admin);

    // The theme script re-applies itself on navigation, and only loads once.
    $this->get(route('account'))->assertOk()
        ->assertSee('<script data-navigate-once>', false)
        ->assertSee("addEventListener('livewire:navigating'", false)
        ->assertSee("addEventListener('livewire:navigated', apply)", false);
});
