<?php

declare(strict_types=1);

/**
 * A plan and a module document answer different questions and age differently.
 *
 * A plan is dated: it stays true forever about one moment. Module documentation describes the
 * present and is wrong the instant the code moves. So a shipped feature whose only description
 * lives in a plan is undocumented, and the reader who finds it there will build what was decided
 * rather than what exists. That has already happened in this repository: a plan named artifacts
 * the implementation deliberately replaced, and a shipped bibliography went unmentioned in the
 * module docs for months.
 *
 * The rule this enforces: closing a plan means saying where the behaviour is described now.
 */

/**
 * @return list<string>
 */
function closedPlanFiles(): array
{
    $files = glob(base_path('docs/superpowers/plans/*.md')) ?: [];

    return array_values(array_filter(
        $files,
        static function (string $file): bool {
            $content = (string) file_get_contents($file);

            // A "## Delivery status" heading marks a plan as closed only when it is
            // a real heading. An unexecuted plan may quote the closing block as an
            // example inside a fenced code block (```...```); that is instructions,
            // not a delivery record, so strip fenced blocks before looking.
            $withoutFences = preg_replace('#^```.*?^```#ms', '', $content) ?? $content;

            return preg_match('/^## Delivery status/m', $withoutFences) === 1;
        },
    ));
}

/**
 * The `status:` of a plan's YAML front matter, lowercased; null when it has none.
 */
function frontMatterStatus(string $file): ?string
{
    $content = (string) file_get_contents($file);

    if (preg_match('/\A---\R(.*?)\R---\R/s', $content, $front) !== 1) {
        return null;
    }

    return preg_match('/^status:\s*(\S+)/m', $front[1], $status) === 1 ? mb_strtolower(mb_trim($status[1], "\"'")) : null;
}

it('finds plans marked as delivered', function (): void {
    // Guards the guard: a glob that silently matches nothing would make every case below vacuous.
    expect(closedPlanFiles())->not->toBeEmpty();
});

it('makes every delivered plan name the documentation that describes it now', function (): void {
    $missing = [];

    foreach (closedPlanFiles() as $file) {
        if (preg_match('#Modules/[A-Za-z]+/docs/[A-Za-z0-9_/.-]+\.md#', (string) file_get_contents($file)) !== 1) {
            $missing[] = basename($file);
        }
    }

    expect($missing)->toBe([], implode(', ', $missing) . ' report delivery but point at no module documentation');
});

it('keeps those pointers resolving to files that exist', function (): void {
    $broken = [];

    foreach (closedPlanFiles() as $file) {
        preg_match_all('#Modules/[A-Za-z]+/docs/[A-Za-z0-9_/.-]+\.md#', (string) file_get_contents($file), $matches);

        foreach (array_unique($matches[0]) as $path) {
            if (! is_file(base_path($path))) {
                $broken[] = basename($file) . ' -> ' . $path;
            }
        }
    }

    expect($broken)->toBe([], implode('; ', $broken));
});

it('makes every delivered plan declare it in its front matter', function (): void {
    // plan-status reads the front matter and this file reads the delivery status: a plan carrying
    // only one of the two reads as finished to one reader and as open to the other.
    $undeclared = [];

    foreach (closedPlanFiles() as $file) {
        if (! in_array(frontMatterStatus($file), ['completed', 'superseded'], true)) {
            $undeclared[] = basename($file) . ' (' . (frontMatterStatus($file) ?? 'no status') . ')';
        }
    }

    expect($undeclared)->toBe([], implode(', ', $undeclared) . ' report delivery without status: completed or superseded');
});

it('makes every plan declared completed carry a delivery status', function (): void {
    $closed = closedPlanFiles();
    $silent = [];

    foreach (glob(base_path('docs/superpowers/plans/*.md')) ?: [] as $file) {
        if (frontMatterStatus($file) === 'completed' && ! in_array($file, $closed, true)) {
            $silent[] = basename($file);
        }
    }

    expect($silent)->toBe([], implode(', ', $silent) . ' declare completed but have no delivery status');
});
