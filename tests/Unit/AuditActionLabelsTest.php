<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AuditActionLabelsTest extends TestCase
{
    /**
     * The dashboard's recent activity and the audit log show every action
     * by its label; one without a label shows up as its raw name. Actions
     * written as a literal (or a ternary of literals) in the first argument
     * of AuditLogger::record() are found here; those built from a value
     * (such as "<domain type>.created") are listed by hand.
     */
    public function test_every_recorded_audit_action_has_a_label()
    {
        $root = dirname(__DIR__, 2);
        preg_match_all("/^\s+'([a-z_]+\.[a-z_]+)':/m", (string) file_get_contents($root.'/resources/js/lib/format.ts'), $labels);
        $labels = array_flip($labels[1]);

        $actions = ['domain.created', 'subdomain.created', 'pointer.created', 'domain.deleted', 'subdomain.deleted', 'pointer.deleted'];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/->record\(\s*([^,]+),/', (string) file_get_contents($file->getPathname()), $calls);

            foreach ($calls[1] as $argument) {
                // Literals the argument compares against are not actions.
                preg_match_all("/(?<![=!]=\s)'([a-z_]+\.[a-z_]+)'/", $argument, $literals);
                array_push($actions, ...$literals[1]);
            }
        }

        $this->assertGreaterThan(100, count(array_unique($actions)));
        $this->assertSame([], array_values(array_unique(array_filter($actions, fn (string $action): bool => ! isset($labels[$action])))));
    }
}
