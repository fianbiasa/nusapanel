<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\MailDomainMode;
use App\Enums\MailDomainStatus;
use App\Enums\MailProvider;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\DnsRecord;
use App\Models\DnsTemplateRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Support\DnsRecordInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the DNS zones of hosting accounts. Every domain and pointer gets a
 * zone of its own, filled from the default template when it is added; a
 * subdomain gets its records in the zone of its domain. The panel's records
 * are the source of truth: every change sends the whole zone to the agent
 * (dns.sync_zone), which makes PowerDNS hold exactly that.
 *
 * Every zone names the same nameservers, which the admin sets on the server
 * settings page (by default ns1 and ns2 of the domain the server's hostname
 * is in).
 */
class DnsService
{
    /**
     * The setting that holds the nameservers.
     */
    public const NAMESERVERS = 'dns.nameservers';

    /**
     * The TTL of the records the template makes, in seconds.
     */
    public const DEFAULT_TTL = 3600;

    /**
     * The template RAYXO ships, which the admin can go back to. Names are
     * relative to the zone; {domain} and {ip} stand for the zone and the
     * server's address.
     *
     * @var list<array{name: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    public const DEFAULT_TEMPLATE = [
        ['name' => '@', 'type' => 'A', 'content' => '{ip}', 'ttl' => self::DEFAULT_TTL, 'priority' => null],
        ['name' => 'www', 'type' => 'A', 'content' => '{ip}', 'ttl' => self::DEFAULT_TTL, 'priority' => null],
        ['name' => 'mail', 'type' => 'A', 'content' => '{ip}', 'ttl' => self::DEFAULT_TTL, 'priority' => null],
        ['name' => 'ftp', 'type' => 'A', 'content' => '{ip}', 'ttl' => self::DEFAULT_TTL, 'priority' => null],
        ['name' => '@', 'type' => 'MX', 'content' => 'mail.{domain}', 'ttl' => self::DEFAULT_TTL, 'priority' => 10],
        ['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 a mx ip4:{ip} ~all', 'ttl' => self::DEFAULT_TTL, 'priority' => null],
    ];

    /**
     * The placeholders a template record may use.
     */
    public const PLACEHOLDERS = ['{domain}', '{ip}'];

    public function __construct(
        private TaskDispatcher $tasks,
        private AuditLogger $audit,
    ) {}

    /**
     * The nameservers every zone names: those the admin set, or ns1 and
     * ns2 of the domain the server's hostname is in.
     *
     * @return list<string>
     */
    public function nameservers(): array
    {
        $saved = Setting::query()->where('key', self::NAMESERVERS)->value('value');

        if (is_array($saved) && $saved !== [] && array_is_list($saved) && array_filter($saved, is_string(...)) === $saved) {
            return $saved;
        }

        return self::defaultNameservers();
    }

    /**
     * ns1 and ns2 of the domain the server's hostname is in: for
     * server.example.com, ns1.example.com and ns2.example.com.
     *
     * @return list<string>
     */
    public static function defaultNameservers(): array
    {
        $hostname = strtolower(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $labels = explode('.', $hostname);
        $base = count($labels) > 2 ? implode('.', array_slice($labels, 1)) : $hostname;

        return ["ns1.{$base}", "ns2.{$base}"];
    }

    /**
     * Make $nameservers those of every zone, and send every zone again.
     *
     * @param  list<string>  $nameservers  Already validated.
     * @return int The number of zones queued.
     */
    public function setNameservers(array $nameservers, User $actor): int
    {
        $previous = $this->nameservers();

        Setting::query()->updateOrCreate(['key' => self::NAMESERVERS], ['value' => $nameservers]);

        $this->audit->record('server.nameservers_changed', null, ['from' => $previous, 'to' => $nameservers], $actor);

        return $this->syncAll($actor);
    }

    /**
     * Give a newly added website its DNS: a domain or pointer its own zone
     * from the template, a subdomain its records in its domain's zone. The
     * zone of an account that is still being created is sent once the
     * account exists ({@see self::syncAccount()}).
     */
    public function addDomain(Domain $domain, ?User $actor): void
    {
        if ($domain->type === DomainType::Subdomain) {
            $parent = $domain->parent;

            if ($parent === null) {
                return;
            }

            $zone = $this->ensureZone($parent);

            foreach ($this->withAccountAddresses($this->subdomainRecords($domain->name), $domain->account) as $record) {
                $zone->records()->firstOrCreate($record);
            }
        } else {
            $zone = $this->ensureZone($domain);
        }

        if ($domain->account->status !== AccountStatus::Pending) {
            $this->sync($zone, $actor);
        }
    }

    /**
     * Add a record to one of the account's zones.
     *
     * @param  array{name: string, type: string, content: string, ttl: int, priority: int|null}  $record  Already validated ({@see DnsRecordInput}).
     *
     * @throws ValidationException
     */
    public function addRecord(DnsZone $zone, array $record, User $actor): DnsRecord
    {
        $this->ensureAccountIsActive($zone->account, $actor);

        return DB::transaction(function () use ($zone, $record, $actor): DnsRecord {
            DnsZone::query()->lockForUpdate()->findOrFail($zone->id);

            if ($zone->records()->count() >= DnsRecordInput::MAX_RECORDS) {
                throw ValidationException::withMessages([
                    'name' => __('A zone may have at most :max records.', ['max' => DnsRecordInput::MAX_RECORDS]),
                ]);
            }

            $this->ensureFits($zone, $record, null);

            $created = $zone->records()->create($record);

            $this->audit->record('dns.record_created', $zone, $this->describe($record), $actor);
            $this->sync($zone, $actor);

            return $created;
        });
    }

    /**
     * Change a record.
     *
     * @param  array{name: string, type: string, content: string, ttl: int, priority: int|null}  $record  Already validated ({@see DnsRecordInput}).
     *
     * @throws ValidationException
     */
    public function updateRecord(DnsRecord $dnsRecord, array $record, User $actor): void
    {
        $zone = $dnsRecord->zone;

        $this->ensureAccountIsActive($zone->account, $actor);

        DB::transaction(function () use ($zone, $dnsRecord, $record, $actor): void {
            DnsZone::query()->lockForUpdate()->findOrFail($zone->id);

            $this->ensureFits($zone, $record, $dnsRecord->id);

            $before = $this->describe($dnsRecord->only(['name', 'type', 'content', 'ttl', 'priority']));
            $dnsRecord->forceFill($record)->save();

            $this->audit->record('dns.record_updated', $zone, ['from' => $before, 'to' => $this->describe($record)], $actor);
            $this->sync($zone, $actor);
        });
    }

    /**
     * Remove a record.
     *
     * @throws ValidationException
     */
    public function deleteRecord(DnsRecord $dnsRecord, User $actor): void
    {
        $zone = $dnsRecord->zone;

        $this->ensureAccountIsActive($zone->account, $actor);

        DB::transaction(function () use ($zone, $dnsRecord, $actor): void {
            $dnsRecord->delete();

            $this->audit->record('dns.record_deleted', $zone, $this->describe($dnsRecord->only(['name', 'type', 'content', 'ttl', 'priority'])), $actor);
            $this->sync($zone, $actor);
        });
    }

    /**
     * Replace every record of the zone with those a new zone starts with,
     * including the records of its subdomains.
     *
     * @throws ValidationException
     */
    public function resetZone(DnsZone $zone, User $actor): void
    {
        $this->ensureAccountIsActive($zone->account, $actor);

        DB::transaction(function () use ($zone, $actor): void {
            $zone->records()->delete();
            $zone->records()->createMany($this->withAccountAddresses($this->defaultRecords($zone->name, $zone->domain), $zone->account));

            $this->audit->record('dns.zone_reset', $zone, ['zone' => $zone->name], $actor);
            $this->sync($zone, $actor);
        });
    }

    /**
     * Put a mail domain's records into its zone: the DKIM key (replacing an
     * older one of the selector), a DMARC policy and, while this server
     * takes the domain's mail, an MX record when the zone has none of its
     * own yet, so customers' changes to those stay.
     */
    public function applyMailRecords(Domain $domain, string $selector, string $publicKey, ?User $actor): void
    {
        $zone = DnsZone::query()->with('account')->where('domain_id', $domain->id)->first();

        if ($zone === null) {
            return;
        }

        $external = $domain->mailDomain()->where('mode', MailDomainMode::External)->exists();

        DB::transaction(function () use ($zone, $domain, $selector, $publicKey, $external, $actor): void {
            $dkim = "{$selector}._domainkey.{$domain->name}";
            $zone->records()->where('name', $dkim)->where('type', 'TXT')->delete();
            $zone->records()->create($this->record($dkim, 'TXT', "v=DKIM1; k=rsa; p={$publicKey}"));

            if (! $zone->records()->where('name', "_dmarc.{$domain->name}")->where('type', 'TXT')->exists()) {
                $zone->records()->create($this->record("_dmarc.{$domain->name}", 'TXT', 'v=DMARC1; p=none'));
            }

            if (! $external && ! $zone->records()->where('name', $domain->name)->where('type', 'MX')->exists()) {
                $zone->records()->create($this->record($domain->name, 'MX', "mail.{$domain->name}", 10));

                $ip = $this->publicIp();

                if ($ip !== null && ! $zone->records()->where('name', "mail.{$domain->name}")->exists()) {
                    $zone->records()->create($this->record("mail.{$domain->name}", 'A', $ip));
                }
            }

            if (! $external) {
                $this->addMailClientRecords($zone, $domain->name);
            }

            if ($zone->account->status !== AccountStatus::Pending) {
                $this->sync($zone, $actor);
            }
        });
    }

    /**
     * Point a domain's mail at another service, or back at this server, in
     * its zone. The panel only takes back what it set: the MX records and
     * SPF term of Google Workspace or Zoho. Going to one of those, the
     * domain's MX records become the service's and its SPF record lets the
     * service send too (this server still may, for websites' mail). Coming
     * back here, a domain left without MX records gets mail.<domain> again.
     * Any other service's records are the customer's to set.
     *
     * @param  MailProvider|null  $from  The service mail went to; null is this server.
     * @param  MailProvider|null  $to  The service mail goes to now; null is this server.
     * @return bool Whether the zone was changed.
     */
    public function useMailProvider(Domain $domain, ?MailProvider $from, ?MailProvider $to, ?User $actor): bool
    {
        $zone = DnsZone::query()->with('account')->where('domain_id', $domain->id)->first();

        if ($zone === null || $from === $to) {
            return false;
        }

        return DB::transaction(function () use ($zone, $domain, $from, $to, $actor): bool {
            $name = $domain->name;
            $exchangers = fn () => $zone->records()->where('name', $name)->where('type', 'MX');
            $changed = false;

            if ($from?->managesDns()) {
                $hosts = array_column($from->exchangers(), 'host');

                foreach ($exchangers()->get() as $record) {
                    if (in_array(strtolower(rtrim($record->content, '.')), $hosts, true)) {
                        $record->delete();
                        $changed = true;
                    }
                }

                $changed = $this->editSpf($zone, $name, fn (array $terms): array => array_values(array_diff($terms, [(string) $from->spfInclude()]))) || $changed;
            }

            if ($to?->managesDns()) {
                $exchangers()->delete();

                foreach ($to->exchangers() as $exchanger) {
                    $zone->records()->create($this->record($name, 'MX', $exchanger['host'], $exchanger['priority']));
                }

                $this->editSpf($zone, $name, fn (array $terms): array => $this->withSpfTerm($terms, (string) $to->spfInclude()), create: true);
                $changed = true;
            } elseif ($to === null && ! $exchangers()->exists()) {
                $zone->records()->create($this->record($name, 'MX', "mail.{$name}", 10));

                $ip = $this->publicIp();

                if ($ip !== null && ! $zone->records()->where('name', "mail.{$name}")->exists()) {
                    $zone->records()->create($this->record("mail.{$name}", 'A', $ip));
                }

                $changed = true;
            }

            if ($changed && $zone->account->status !== AccountStatus::Pending) {
                $this->sync($zone, $actor);
            }

            return $changed;
        });
    }

    /**
     * Change the terms of the SPF records at name with edit; with create, a
     * name without one gets one that lets this server send.
     *
     * @param  callable(list<string>): list<string>  $edit
     * @return bool Whether a record changed.
     */
    private function editSpf(DnsZone $zone, string $name, callable $edit, bool $create = false): bool
    {
        $records = $zone->records()->where('name', $name)->where('type', 'TXT')->get()
            ->filter(fn (DnsRecord $record): bool => preg_match('/^v=spf1(\s|$)/i', $record->content) === 1);

        if ($records->isEmpty()) {
            if (! $create) {
                return false;
            }

            $ip = $this->publicIp();
            $terms = $edit(['v=spf1', 'a', 'mx', ...($ip !== null ? ["ip4:{$ip}"] : []), '~all']);
            $zone->records()->create($this->record($name, 'TXT', implode(' ', $terms)));

            return true;
        }

        $changed = false;

        foreach ($records as $record) {
            $content = implode(' ', $edit(preg_split('/\s+/', trim($record->content)) ?: []));

            if ($content !== $record->content) {
                $record->forceFill(['content' => $content])->save();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * SPF terms with term added before the closing all (or redirect), once.
     *
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function withSpfTerm(array $terms, string $term): array
    {
        if (in_array(strtolower($term), array_map('strtolower', $terms), true)) {
            return $terms;
        }

        foreach ($terms as $index => $existing) {
            if (preg_match('/^([+?~-]?all|redirect=.*)$/iD', $existing) === 1) {
                array_splice($terms, $index, 0, [$term]);

                return $terms;
            }
        }

        return [...$terms, $term];
    }

    /**
     * Add a record to the template new zones start with. Zones that exist
     * keep their records.
     *
     * @param  array{name: string, type: string, content: string, ttl: int, priority: int|null}  $record  Already validated, name relative.
     *
     * @throws ValidationException
     */
    public function addTemplateRecord(array $record, User $actor): DnsTemplateRecord
    {
        $this->ensureNotInTemplate($record, null);

        $created = DnsTemplateRecord::query()->create($record);

        $this->audit->record('dns.template_changed', null, ['added' => $this->describe($record)], $actor);

        return $created;
    }

    /**
     * Change a record of the template.
     *
     * @param  array{name: string, type: string, content: string, ttl: int, priority: int|null}  $record  Already validated, name relative.
     *
     * @throws ValidationException
     */
    public function updateTemplateRecord(DnsTemplateRecord $templateRecord, array $record, User $actor): void
    {
        $this->ensureNotInTemplate($record, $templateRecord->id);

        $before = $this->describe($templateRecord->only(['name', 'type', 'content', 'ttl', 'priority']));
        $templateRecord->forceFill($record)->save();

        $this->audit->record('dns.template_changed', null, ['from' => $before, 'to' => $this->describe($record)], $actor);
    }

    /**
     * Remove a record from the template.
     */
    public function deleteTemplateRecord(DnsTemplateRecord $templateRecord, User $actor): void
    {
        $templateRecord->delete();

        $this->audit->record('dns.template_changed', null, ['removed' => $this->describe($templateRecord->only(['name', 'type', 'content', 'ttl', 'priority']))], $actor);
    }

    /**
     * Put the template back to the one RAYXO ships.
     */
    public function resetTemplate(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            DnsTemplateRecord::query()->delete();

            foreach (self::DEFAULT_TEMPLATE as $record) {
                DnsTemplateRecord::query()->create($record);
            }

            $this->audit->record('dns.template_reset', null, [], $actor);
        });
    }

    /**
     * Where the account's latest DNS change stands: whether the agent is
     * still applying it, and the error when it failed.
     *
     * @return array{pending: bool, error: string|null}
     */
    public function syncState(User $account): array
    {
        $task = Task::query()
            ->where('user_id', $account->id)
            ->whereIn('action', ['dns.sync_zone', 'dns.delete_zone'])
            ->latest('created_at')
            ->latest('id')
            ->first();

        return [
            'pending' => in_array($task?->status, [TaskStatus::Queued, TaskStatus::Running], true),
            'error' => $task?->status === TaskStatus::Failed ? ($task->error ?? '') : null,
        ];
    }

    /**
     * The nameservers the zones of a hosting account name: its reseller's
     * own, when the reseller set them, else the panel's.
     *
     * @return list<string>
     */
    public function nameserversFor(User $account): array
    {
        $owner = $account->owner;
        $own = $owner !== null && $owner->isReseller() ? $owner->resellerSetting?->nameservers : null;

        if (is_array($own) && $own !== []) {
            return array_values(array_filter($own, is_string(...)));
        }

        return $this->nameservers();
    }

    /**
     * Queue every zone of a reseller's accounts again, e.g. after its
     * nameservers changed. Zones of accounts still being created are sent
     * once they exist.
     *
     * @return int The number of zones queued.
     */
    public function syncResellerAccounts(User $reseller, ?User $actor): int
    {
        $queued = 0;

        DnsZone::query()
            ->with('account.owner.resellerSetting')
            ->whereIn('user_id', User::query()->select('id')->where('owner_id', $reseller->id)->where('status', '!=', AccountStatus::Pending))
            ->orderBy('id')
            ->each(function (DnsZone $zone) use ($actor, &$queued): void {
                $this->sync($zone, $actor);
                $queued++;
            });

        return $queued;
    }

    /**
     * Point the website names of the account's zones (its domains,
     * subdomains and pointers, and their www) from its old addresses to its
     * new ones; null is the server's main IPv4 address, or no IPv6 address.
     * IPv4 records are moved; IPv6 records are moved, removed with the
     * address, or added next to the website names' IPv4 records. Other
     * names, such as mail, stay with the server. Changed zones are queued.
     */
    public function moveAccountAddresses(User $account, ?string $fromIpv4, ?string $toIpv4, ?string $fromIpv6, ?string $toIpv6, ?User $actor): void
    {
        $fromIpv4 ??= $this->publicIp();
        $toIpv4 ??= $this->publicIp();
        $names = $this->websiteNames($account);

        $account->dnsZones()->orderBy('id')->each(function (DnsZone $zone) use ($account, $actor, $names, $fromIpv4, $toIpv4, $fromIpv6, $toIpv6): void {
            $changed = false;

            if ($fromIpv4 !== null && $toIpv4 !== null && $fromIpv4 !== $toIpv4) {
                $changed = $zone->records()->where('type', 'A')->where('content', $fromIpv4)->whereIn('name', $names)->update(['content' => $toIpv4]) > 0;
            }

            if ($fromIpv6 !== $toIpv6) {
                if ($fromIpv6 !== null) {
                    $records = $zone->records()->where('type', 'AAAA')->where('content', $fromIpv6)->whereIn('name', $names);
                    $changed = ($toIpv6 === null ? $records->delete() : $records->update(['content' => $toIpv6])) > 0 || $changed;
                }

                if ($toIpv6 !== null) {
                    $hasIpv6 = $zone->records()->where('type', 'AAAA')->pluck('name')->all();

                    foreach ($zone->records()->where('type', 'A')->whereIn('name', $names)->whereNotIn('name', $hasIpv6)->pluck('name')->unique() as $name) {
                        $zone->records()->create($this->record($name, 'AAAA', $toIpv6));
                        $changed = true;
                    }
                }
            }

            if ($changed && $account->status !== AccountStatus::Pending) {
                $zone->setRelation('account', $account);
                $this->sync($zone, $actor);
            }
        });
    }

    /**
     * Send every zone of an account that has just been created on the
     * server, made while it was still pending.
     */
    public function syncAccount(User $account, ?User $actor): void
    {
        $this->ensureZones(User::query()->whereKey($account->id));

        $account->dnsZones()
            ->orderBy('id')
            ->each(function (DnsZone $zone) use ($account, $actor): void {
                $zone->setRelation('account', $account);
                $this->sync($zone, $actor);
            });
    }

    /**
     * Take a website that is being deleted out of DNS: the zone of a domain
     * or pointer is removed from the server, a subdomain's address records
     * are removed from its domain's zone.
     */
    public function removeDomain(Domain $domain, ?User $actor): void
    {
        if ($domain->type === DomainType::Subdomain) {
            $zone = DnsZone::query()->where('domain_id', $domain->parent_id)->first();

            if ($zone === null) {
                return;
            }

            $zone->records()
                ->whereIn('name', [$domain->name, 'www.'.$domain->name])
                ->whereIn('type', ['A', 'AAAA'])
                ->delete();

            $this->sync($zone, $actor);

            return;
        }

        $zone = DnsZone::query()->with('account')->where('domain_id', $domain->id)->first();

        if ($zone === null) {
            return;
        }

        $this->dispatchDelete($zone, $actor);
        $zone->delete();
    }

    /**
     * Remove every zone of an account that is being deleted from the
     * server. The panel's rows go with the account.
     */
    public function removeAccount(User $account, ?User $actor): void
    {
        DnsZone::query()
            ->where('user_id', $account->id)
            ->orderBy('id')
            ->each(function (DnsZone $zone) use ($account, $actor): void {
                $zone->setRelation('account', $account);
                $this->dispatchDelete($zone, $actor);
            });
    }

    /**
     * Give every domain and pointer of active and suspended accounts its
     * zone (from the template when it has none yet, such as after an
     * upgrade from a release without DNS), and send every zone of those
     * accounts again. Used by rayxo:sync-dns and rayxo:rebuild.
     *
     * @return int The number of zones queued.
     */
    public function syncAll(?User $actor = null): int
    {
        $accounts = User::query()
            ->where('role', Role::User)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended]);

        $this->ensureZones($accounts);
        $this->ensureMailClientRecords($accounts);

        $queued = 0;

        DnsZone::query()
            ->with('account')
            ->whereIn('user_id', $accounts->clone()->select('id'))
            ->orderBy('id')
            ->each(function (DnsZone $zone) use ($actor, &$queued): void {
                $this->sync($zone, $actor);
                $queued++;
            });

        return $queued;
    }

    /**
     * The records mail apps find their settings with: autoconfig.<domain>
     * (Thunderbird) and autodiscover.<domain> at this server, and
     * _autodiscover._tcp sending Outlook to the server hostname, whose
     * certificate it trusts. Only names the zone does not have yet are
     * added, so the customer's own records stay.
     *
     * @return bool Whether a record was added.
     */
    private function addMailClientRecords(DnsZone $zone, string $domain): bool
    {
        $added = false;
        $ip = $this->publicIp();

        foreach (["autoconfig.{$domain}", "autodiscover.{$domain}"] as $name) {
            if ($ip !== null && ! $zone->records()->where('name', $name)->exists()) {
                $zone->records()->create($this->record($name, 'A', $ip));
                $added = true;
            }
        }

        $hostname = parse_url((string) config('app.url'), PHP_URL_HOST);
        $srv = "_autodiscover._tcp.{$domain}";

        if (is_string($hostname) && $hostname !== '' && ! $zone->records()->where('name', $srv)->exists()) {
            $zone->records()->create($this->record($srv, 'SRV', '0 443 '.strtolower($hostname), 0));
            $added = true;
        }

        return $added;
    }

    /**
     * Give the zones of the accounts' domains that take mail here the
     * records of mail apps they lack, e.g. after an upgrade from a release
     * without them.
     *
     * @param  Builder<User>  $accounts
     */
    private function ensureMailClientRecords(Builder $accounts): void
    {
        DnsZone::query()
            ->with('domain')
            ->whereIn('user_id', $accounts->clone()->select('id'))
            ->whereHas('domain.mailDomain', fn (Builder $query) => $query
                ->where('status', MailDomainStatus::Active)
                ->where('mode', '!=', MailDomainMode::External))
            ->orderBy('id')
            ->each(function (DnsZone $zone): void {
                if ($zone->domain !== null) {
                    $this->addMailClientRecords($zone, $zone->domain->name);
                }
            });
    }

    /**
     * Make the missing zones of the domains and pointers of the accounts.
     *
     * @param  Builder<User>  $accounts
     */
    private function ensureZones(Builder $accounts): void
    {
        Domain::query()
            ->whereIn('user_id', $accounts->clone()->select('id'))
            ->whereIn('type', [DomainType::Domain, DomainType::Pointer])
            ->where('status', '!=', DomainStatus::Deleting)
            ->whereDoesntHave('dnsZone')
            ->orderBy('id')
            ->each(fn (Domain $domain) => $this->ensureZone($domain));
    }

    /**
     * The zone of a domain or pointer, made from the template when it has
     * none yet, with the records of its subdomains.
     */
    private function ensureZone(Domain $domain): DnsZone
    {
        $existing = DnsZone::query()->where('domain_id', $domain->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($domain): DnsZone {
            $zone = new DnsZone(['name' => $domain->name]);
            $zone->forceFill(['user_id' => $domain->user_id, 'domain_id' => $domain->id])->save();

            $zone->records()->createMany($this->withAccountAddresses($this->defaultRecords($domain->name, $domain), $domain->account));

            return $zone;
        });
    }

    /**
     * The records a zone starts with: the template's, and those of the
     * domain's subdomains.
     *
     * @return list<array{name: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    private function defaultRecords(string $zone, ?Domain $domain): array
    {
        $records = $this->templateRecords($zone);

        if ($domain === null) {
            return $records;
        }

        $subdomains = $domain->children()
            ->where('type', DomainType::Subdomain)
            ->where('status', '!=', DomainStatus::Deleting)
            ->orderBy('name')
            ->pluck('name');

        foreach ($subdomains as $subdomain) {
            $records = [...$records, ...$this->subdomainRecords($subdomain)];
        }

        return $records;
    }

    /**
     * Refuse a record that is already there, a CNAME next to other records
     * of its name, or another record next to a CNAME.
     *
     * @param  array{name: string, type: string, content: string, ttl: int, priority: int|null}  $record
     *
     * @throws ValidationException
     */
    private function ensureFits(DnsZone $zone, array $record, ?int $ignoreId): void
    {
        $others = $zone->records()
            ->where('name', $record['name'])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get();

        $name = DnsRecordInput::relativeName($record['name'], $zone->name);

        $message = match (true) {
            $others->contains(fn (DnsRecord $other): bool => $other->type === $record['type'] && $other->content === $record['content']) => ['content' => __('This record already exists.')],
            $record['type'] === 'CNAME' && $others->isNotEmpty() => ['name' => __(':name already has other records, so it cannot be a CNAME.', ['name' => $name])],
            $record['type'] !== 'CNAME' && $others->contains('type', 'CNAME') => ['name' => __(':name is a CNAME, so it can have no other record.', ['name' => $name])],
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages($message);
        }
    }

    /**
     * Refuse a template record that is already in the template.
     *
     * @param  array{name: string, type: string, content: string, ttl: int, priority: int|null}  $record
     *
     * @throws ValidationException
     */
    private function ensureNotInTemplate(array $record, ?int $ignoreId): void
    {
        $exists = DnsTemplateRecord::query()
            ->where('name', $record['name'])
            ->where('type', $record['type'])
            ->get()
            ->contains(fn (DnsTemplateRecord $other): bool => $other->id !== $ignoreId && $other->content === $record['content']);

        if ($exists) {
            throw ValidationException::withMessages(['content' => __('This record already exists.')]);
        }
    }

    /**
     * Customers change their DNS while their account is active; admins
     * also while it is suspended.
     *
     * @throws ValidationException
     */
    private function ensureAccountIsActive(User $account, ?User $actor = null): void
    {
        $allowed = $actor?->isAdmin() ? [AccountStatus::Active, AccountStatus::Suspended] : [AccountStatus::Active];

        if (! $account->hasRole(Role::User) || ! in_array($account->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'name' => __('DNS records can only be changed while the account is active.'),
            ]);
        }
    }

    /**
     * A record as the audit log keeps it.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function describe(array $record): array
    {
        return array_filter([
            'name' => $record['name'] ?? null,
            'type' => $record['type'] ?? null,
            'content' => $record['content'] ?? null,
            'ttl' => $record['ttl'] ?? null,
            'priority' => $record['priority'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Queue the zone, with all its records, for the agent.
     */
    private function sync(DnsZone $zone, ?User $actor): void
    {
        $account = $zone->account;

        $this->tasks->dispatch('dns.sync_zone', [
            'username' => $account->username,
            'zone' => $zone->name,
            'nameservers' => $this->nameserversFor($account),
            'records' => $zone->records()
                ->orderBy('name')
                ->orderBy('type')
                ->orderBy('id')
                ->get()
                ->map(fn (DnsRecord $record): array => $record->toAgent())
                ->all(),
        ], $account, $actor);
    }

    private function dispatchDelete(DnsZone $zone, ?User $actor): void
    {
        $this->tasks->dispatch('dns.delete_zone', [
            'username' => $zone->account->username,
            'zone' => $zone->name,
        ], $zone->account, $actor);
    }

    /**
     * The records a new zone starts with: the website, www, mail and ftp at
     * this server's address, mail for the domain delivered to mail.<zone>,
     * and an SPF record that lets this server send for it.
     *
     * @return list<array{name: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    private function templateRecords(string $zone): array
    {
        $ip = $this->publicIp();
        $records = [];

        foreach (DnsTemplateRecord::query()->orderBy('id')->get() as $template) {
            // Without a known address, records that need it are left out.
            if ($ip === null && str_contains($template->content, '{ip}')) {
                continue;
            }

            $records[] = [
                'name' => DnsRecordInput::absoluteName($template->name, $zone),
                'type' => $template->type,
                'content' => strtr($template->content, ['{domain}' => $zone, '{ip}' => (string) $ip]),
                'ttl' => $template->ttl,
                'priority' => $template->priority,
            ];
        }

        return $records;
    }

    /**
     * The address records of a subdomain and its www name.
     *
     * @return list<array{name: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    private function subdomainRecords(string $subdomain): array
    {
        $ip = $this->publicIp();

        if ($ip === null) {
            return [];
        }

        return [
            $this->record($subdomain, 'A', $ip),
            $this->record("www.{$subdomain}", 'A', $ip),
        ];
    }

    /**
     * @return array{name: string, type: string, content: string, ttl: int, priority: int|null}
     */
    private function record(string $name, string $type, string $content, ?int $priority = null): array
    {
        return ['name' => $name, 'type' => $type, 'content' => $content, 'ttl' => self::DEFAULT_TTL, 'priority' => $priority];
    }

    /**
     * The records with the website names of an account that has addresses
     * of its own pointing there: its IPv4 address instead of the server's,
     * and an IPv6 record next to each of them.
     *
     * @param  list<array{name: string, type: string, content: string, ttl: int, priority: int|null}>  $records
     * @return list<array{name: string, type: string, content: string, ttl: int, priority: int|null}>
     */
    private function withAccountAddresses(array $records, ?User $account): array
    {
        $ipv4 = $account?->ipv4Address?->address;
        $ipv6 = $account?->ipv6Address?->address;

        if ($account === null || ($ipv4 === null && $ipv6 === null)) {
            return $records;
        }

        $names = $this->websiteNames($account);
        $serverIp = $this->publicIp();
        $withIpv6 = [];

        foreach ($records as $index => $record) {
            if ($record['type'] === 'AAAA') {
                $withIpv6[$record['name']] = true;
            }

            if ($record['type'] !== 'A' || ! in_array($record['name'], $names, true)) {
                continue;
            }

            if ($ipv4 !== null && $record['content'] === $serverIp) {
                $records[$index]['content'] = $ipv4;
            }
        }

        if ($ipv6 !== null) {
            foreach ($records as $record) {
                if ($record['type'] === 'A' && in_array($record['name'], $names, true) && ! isset($withIpv6[$record['name']])) {
                    $records[] = $this->record($record['name'], 'AAAA', $ipv6);
                    $withIpv6[$record['name']] = true;
                }
            }
        }

        return $records;
    }

    /**
     * The names the account's websites answer to: its domains, subdomains
     * and pointers, and their www.
     *
     * @return list<string>
     */
    private function websiteNames(User $account): array
    {
        $names = [];

        foreach (Domain::query()->where('user_id', $account->id)->where('status', '!=', DomainStatus::Deleting)->pluck('name') as $name) {
            $names[] = $name;
            $names[] = 'www.'.$name;
        }

        return $names;
    }

    /**
     * The server's public IPv4 address, as setup.sh found it.
     */
    private function publicIp(): ?string
    {
        $ip = config('hosting.public_ip');

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null;
    }
}
