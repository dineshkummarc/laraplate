<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;

/**
 * Navigation groups are registered under a lang key (`erp.navigation.sales`) and labelled in the request
 * locale. Resources and pages name their group by that key: Filament matches a group by key before label,
 * so an item whose key is not registered would show the raw key as its group.
 *
 * @return array<int, string>
 */
function declaredAdminNavigationGroups(): array
{
    return array_values(array_filter(
        array_keys(Filament::getPanel('admin')->getNavigationGroups()),
        static fn (int|string $key): bool => is_string($key),
    ));
}

/**
 * @return array<int, string>
 */
function usedAdminNavigationGroups(): array
{
    $panel = Filament::getPanel('admin');

    $groups = [];

    foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
        $group = $class::getNavigationGroup();

        if (is_string($group) && $group !== '') {
            $groups[$group] = $group;
        }
    }

    return array_values($groups);
}

it('declares every navigation group used by the panel', function (): void {
    $undeclared = array_diff(usedAdminNavigationGroups(), declaredAdminNavigationGroups());

    expect($undeclared)->toBeEmpty();
});

/**
 * The module a group belongs to: the lang group of its key (`erp.navigation.sales` is erp).
 */
function navigationGroupModule(string $key): string
{
    return explode('.', $key, 2)[0];
}

it('registers every navigation group under a key', function (): void {
    expect(count(declaredAdminNavigationGroups()))->toBe(count(Filament::getPanel('admin')->getNavigationGroups()));
});

it('puts Health, Documentation and Core first', function (): void {
    expect(array_slice(declaredAdminNavigationGroups(), 0, 3))
        ->toBe(['app.navigation.health', 'app.navigation.documentation', 'app.navigation.core']);
});

it('orders the modules alphabetically and keeps the groups of a module together', function (): void {
    $modules = array_map(navigationGroupModule(...), array_slice(declaredAdminNavigationGroups(), 3));

    // Consecutive groups of one module collapse into one entry; a module listed twice is split.
    $sequence = array_values(array_filter(
        $modules,
        static fn (string $module, int $position): bool => $position === 0 || $module !== $modules[$position - 1],
        ARRAY_FILTER_USE_BOTH,
    ));
    $sorted = array_values(array_unique($sequence));
    usort($sorted, static fn (string $a, string $b): int => strcasecmp($a, $b));

    // Within a module the order is the module's own: its process, not the alphabet.
    expect($sequence)->toBe($sorted);
});

it('labels navigation groups in the request locale', function (): void {
    /** @var NavigationGroup $documentation */
    $documentation = Filament::getPanel('admin')->getNavigationGroups()['app.navigation.documentation'];

    app()->setLocale('it');
    expect($documentation->getLabel())->toBe('Documentazione');

    app()->setLocale('en');
    expect($documentation->getLabel())->toBe('Documentation');
});
