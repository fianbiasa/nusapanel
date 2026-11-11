<?php

namespace App\Support;

use App\Models\MailAutoreply;
use App\Models\MailFilter;
use App\Models\MailSpamSetting;

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
    public static function build(string $address, ?MailAutoreply $autoreply, iterable $filters, ?MailSpamSetting $spam = null): string
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

        if ($lines === [] && $spam === null) {
            return '';
        }

        // A mailbox with its own spam filter handles spam first, so filters
        // never act on it and no automatic reply answers it. Dovecot skips
        // the server's spam script (sieve_before) for such a mailbox: its
        // userdb gives it an empty one (v_mail_mailboxes.sieve_before).
        if ($spam !== null) {
            [$spamExtensions, $spamLines] = self::spam($spam);
            $extensions = [...$spamExtensions, ...$extensions];
            $lines = [...$spamLines, ...$lines];
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
     * The mailbox's own spam filter.
     *
     * @return array{list<string>, list<string>}
     */
    private static function spam(MailSpamSetting $spam): array
    {
        [$extensions, $commands] = match ($spam->action) {
            'delete' => [[], ['discard;', 'stop;']],
            'tag' => [[], ['keep;', 'stop;']],
            default => [['fileinto', 'mailbox'], ['fileinto :create "Junk";', 'stop;']],
        };
        $indented = array_map(fn (string $command): string => '    '.$command, $commands);

        $lines = ['# Spam'];

        $blacklist = self::senderTests($spam->blacklist);
        if ($blacklist !== []) {
            $lines = [...$lines, '# Senders whose mail is always spam', 'if anyof ('.implode(', ', $blacklist).') {', ...$indented, '}'];
        }

        // Rspamd's X-Spam-Level holds one star per whole point of the score.
        $tests = ['header :contains "X-Spam-Level" '.self::quote(str_repeat('*', max(1, $spam->threshold)))];

        $whitelist = self::senderTests($spam->whitelist);
        if ($whitelist !== []) {
            $tests[] = 'not anyof ('.implode(', ', $whitelist).')';
        }

        $lines = [...$lines, '# A spam score of '.$spam->threshold.' or more', 'if allof ('.implode(', ', $tests).') {', ...$indented, '}', ''];

        return [$extensions, $lines];
    }

    /**
     * Sieve tests for the From address of senders: full addresses and
     *
     * @domains.
     *
     * @param  list<string>  $senders
     * @return list<string>
     */
    private static function senderTests(array $senders): array
    {
        $addresses = [];
        $domains = [];

        foreach ($senders as $sender) {
            if (str_starts_with($sender, '@')) {
                $domains[] = substr($sender, 1);
            } else {
                $addresses[] = $sender;
            }
        }

        $tests = [];

        if ($addresses !== []) {
            $tests[] = 'address :is :all "from" '.self::stringList($addresses);
        }

        if ($domains !== []) {
            $tests[] = 'address :is :domain "from" '.self::stringList($domains);
        }

        return $tests;
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
