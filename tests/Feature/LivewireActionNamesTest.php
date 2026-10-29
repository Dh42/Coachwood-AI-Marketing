<?php

use Symfony\Component\Finder\Finder;

/**
 * Livewire's JavaScript reads some action names as its own $wire shortcuts
 * (wire:click="commit" is $commit, a refresh), so a component method with
 * that name is never called from the page. Tests that call the method on
 * the server still pass, so this checks the views instead.
 */
it('never names a page action after one of Livewire\'s own shortcuts', function () {
    $js = file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.esm.js'));
    preg_match_all('/^\s+"(\w+)": "\$\1",?$/m', $js, $aliases);
    expect($aliases[1])->toContain('commit');

    $clashes = [];
    $views = Finder::create()->files()->name('*.blade.php')->in([resource_path('views'), base_path('plugins')])->exclude(['vendor', 'node_modules']);

    foreach ($views as $file) {
        preg_match_all('/wire:(?:click|submit|change|keydown|blur|init|poll)[\w.-]*="(\w+)\s*[("]/', $file->getContents(), $actions);

        foreach (array_intersect($actions[1], $aliases[1]) as $name) {
            $clashes[] = $file->getRelativePathname().": {$name}";
        }
    }

    expect($clashes)->toBe([]);
});
