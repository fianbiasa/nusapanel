<?php

namespace App\Support;

use App\Models\MailAutoreply;
use App\Models\MailFilter;

/**
 * The Sieve script of a mailbox (RFC 5228), from its automatic reply and
 * filters. Dovecot runs it on delivery, after the server's own script has
 * moved spam into Junk.
 *
 * The automatic reply comes first, so senders get it whatever the filters
 * do with their mail; then the filters run in their order. Every value is
 * written as a quoted string with its backslashes and double quotes
 * escaped, so nothing typed in the panel can change the script's syntax.
 */
final class SieveScript
{
    /**
     * The script, or an empty string when the mailbox has nothing to run.
     *
     * @param  iterable<MailFilter>  $filters
     */
    public static function build(string $address, ?MailAutoreply $autoreply, iterable $filters): string
    {
        $extensions = [];
        $lines = [];

        if ($autoreply?->enabled) {
            [$autoreplyExtensions, $autoreplyLines] = self::autoreply($address, $autoreply);
            $extensions = [...$extensions, ...$autoreplyExtensions];
            $lines = [...$lines, ...$autoreplyLines];
        }

        foreach ($filters as $filter) {
            [$filterExtensions, $filterLines] = self::filter($filter);
            $extensions = [...$extensions, ...$filterExtensions];
            $lines = [...$lines, ...$filterLines];
        }

        if ($lines === []) {
            return '';
        }

        $extensions = array_values(array_unique($extensions));
        sort($extensions);

        $header = ['# Written by RAYXO from the Email page; changes made here are lost.'];

        if ($extensions !== []) {
            $header[] = 'require '.self::stringList($extensions).';';
        }

        return rtrim(implode("\n", [...$header, '', ...$lines]))."\n";
    }

    /**
     * A Sieve quoted string of value.
     */
    public static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private static function autoreply(string $address, MailAutoreply $autoreply): array
    {
        $extensions = ['vacation'];
        $body = str_replace(["\r\n", "\r"], "\n", $autoreply->body);

        $vacation = sprintf(
            'vacation :days %d :from %s :addresses %s :subject %s %s;',
            max(1, $autoreply->interval_days),
            self::quote($address),
            self::stringList([$address]),
            self::quote($autoreply->subject),
            self::quote($body),
        );

        $conditions = [];

        if ($autoreply->starts_on !== null) {
            $conditions[] = 'currentdate :value "ge" "date" '.self::quote($autoreply->starts_on->format('Y-m-d'));
        }

        if ($autoreply->ends_on !== null) {
            $conditions[] = 'currentdate :value "le" "date" '.self::quote($autoreply->ends_on->format('Y-m-d'));
        }

        if ($conditions === []) {
            return [$extensions, ['# Automatic reply', $vacation, '']];
        }

        return [
            [...$extensions, 'date', 'relational'],
            ['# Automatic reply', 'if allof ('.implode(', ', $conditions).') {', '    '.$vacation, '}', ''],
        ];
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private static function filter(MailFilter $filter): array
    {
        $match = $filter->operator === 'is' ? ':is' : ':contains';
        $value = self::quote($filter->value);

        $test = match ($filter->field) {
            'from' => "address :all {$match} \"from\" {$value}",
            'to' => "address :all {$match} [\"to\", \"cc\"] {$value}",
            default => "header {$match} \"subject\" {$value}",
        };

        [$extensions, $commands] = match ($filter->action) {
            'move' => [['fileinto', 'mailbox'], ['fileinto :create '.self::quote((string) $filter->target).';', 'stop;']],
            'forward' => [['copy'], ['redirect :copy '.self::quote((string) $filter->target).';']],
            default => [[], ['discard;', 'stop;']],
        };

        return [
            $extensions,
            ['# Filter '.$filter->id, "if {$test} {", ...array_map(fn (string $command): string => '    '.$command, $commands), '}', ''],
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private static function stringList(array $values): string
    {
        return '['.implode(', ', array_map(self::quote(...), $values)).']';
    }
}
