<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\Role;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The server's extra IP addresses and the hosting accounts that have them.
 *
 * Administrators add an address (the agent puts it on the network
 * interface, unless the server has it already) and give it to a reseller
 * or keep it; the owner marks it dedicated (one account at most) or shared.
 * An account's websites are served on its own addresses instead of the
 * server's, and the website names in its DNS zones point at them.
 */
class IpAddressService
{
    /**
     * How long the agent may take to change the network interface.
     */
    private const TIMEOUT = 30;

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
        private DomainService $domains,
        private DnsService $dns,
    ) {}

    /**
     * The global addresses of the server's interfaces, as the agent sees
     * them, or null when it cannot tell.
     *
     * @return list<array{address: string, prefix: int, interface: string, added: bool, primary: bool}>|null
     */
    public function serverAddresses(): ?array
    {
        try {
            $result = $this->agent->call('network.addresses', [], self::TIMEOUT);
        } catch (AgentException $exception) {
            report($exception);

            return null;
        }

        $addresses = [];

        foreach (is_array($result['addresses'] ?? null) ? $result['addresses'] : [] as $address) {
            if (! is_array($address) || ! is_string($address['address'] ?? null)) {
                continue;
            }

            $addresses[] = [
                'address' => $address['address'],
                'prefix' => (int) ($address['prefix'] ?? 0),
                'interface' => (string) ($address['interface'] ?? ''),
                'added' => ($address['added'] ?? false) === true,
                'primary' => ($address['primary'] ?? false) === true,
            ];
        }

        return $addresses;
    }

    /**
     * Add an address of the administrators. The agent puts it on the
     * interface of the default route unless the server has it already.
     * The server's main addresses, which websites without an address of
     * their own use, cannot be added.
     *
     * @throws ValidationException
     */
    public function add(string $address, int $prefix, ?string $note, User $actor): IpAddress
    {
        $address = $this->canonical($address);
        $version = str_contains($address, ':') ? 6 : 4;

        if ($address === config('hosting.public_ip') || $this->isMainAddress($address)) {
            throw ValidationException::withMessages(['address' => __(':address is the server\'s main address, which websites without an address of their own use.', ['address' => $address])]);
        }

        if (IpAddress::query()->where('address', $address)->exists()) {
            throw ValidationException::withMessages(['address' => __(':address has been added already.', ['address' => $address])]);
        }

        try {
            $result = $this->agent->call('network.add_address', ['address' => $address, 'prefix' => $prefix], self::TIMEOUT);
        } catch (AgentException $exception) {
            report($exception);

            throw ValidationException::withMessages(['address' => __(':address could not be added to the server. Check the address and try again.', ['address' => $address])]);
        }

        $ipAddress = new IpAddress(['note' => $note]);
        $ipAddress->forceFill([
            'address' => $address,
            'version' => $version,
            'prefix' => $prefix,
            'added' => ($result['added'] ?? false) === true,
        ])->save();

        $this->audit->record('ip.added', $ipAddress, ['address' => $address, 'prefix' => $prefix], $actor);

        return $ipAddress;
    }

    /**
     * Remove an address no account has. The agent takes it off the
     * interface when it put it there.
     *
     * @throws ValidationException
     */
    public function remove(IpAddress $ipAddress, User $actor): void
    {
        $this->ensureUnused($ipAddress, __('Accounts still have this address. Give them another one first.'));

        if ($ipAddress->added) {
            try {
                $this->agent->call('network.remove_address', ['address' => $ipAddress->address], self::TIMEOUT);
            } catch (AgentException $exception) {
                report($exception);

                throw ValidationException::withMessages(['address' => __(':address could not be taken off the server. Try again in a moment.', ['address' => $ipAddress->address])]);
            }
        }

        $ipAddress->delete();

        $this->audit->record('ip.removed', $ipAddress, ['address' => $ipAddress->address], $actor);
    }

    /**
     * Give an address to a reseller, or back to the administrators (null),
     * while no account has it.
     *
     * @throws ValidationException
     */
    public function assign(IpAddress $ipAddress, ?User $owner, User $actor): void
    {
        if ($owner !== null && ! $owner->hasRole(Role::Reseller)) {
            throw ValidationException::withMessages(['owner_id' => __('Addresses can only be given to resellers.')]);
        }

        if ($ipAddress->owner_id === $owner?->id) {
            return;
        }

        $this->ensureUnused($ipAddress, __('Accounts still have this address. Give them another one before it changes hands.'));

        $from = $ipAddress->owner?->username;
        $ipAddress->forceFill(['owner_id' => $owner?->id])->save();

        $this->audit->record('ip.assigned', $ipAddress, [
            'address' => $ipAddress->address,
            'from' => $from,
            'to' => $owner?->username,
        ], $actor);
    }

    /**
     * Change whether an address is dedicated, and its note. It can only be
     * dedicated while one account at most has it.
     *
     * @throws ValidationException
     */
    public function update(IpAddress $ipAddress, bool $dedicated, ?string $note, User $actor): void
    {
        if ($dedicated && $ipAddress->accounts()->count() > 1) {
            throw ValidationException::withMessages(['dedicated' => __('Several accounts have this address, so it cannot be dedicated to one.')]);
        }

        $ipAddress->fill(['dedicated' => $dedicated, 'note' => $note]);

        if (! $ipAddress->isDirty()) {
            return;
        }

        $changed = array_keys($ipAddress->getDirty());
        $from = array_intersect_key($ipAddress->getOriginal(), array_flip($changed));
        $ipAddress->save();

        $this->audit->record('ip.updated', $ipAddress, [
            'address' => $ipAddress->address,
            'from' => $from,
            'to' => $ipAddress->only($changed),
        ], $actor);
    }

    /**
     * The addresses the account may have: those of its owner that are
     * shared, or dedicated and free or its own.
     *
     * @return list<IpAddress>
     */
    public function availableFor(User $account): array
    {
        $owner = $account->owner;

        if ($owner === null) {
            return [];
        }

        $available = [];

        foreach (IpAddress::handedOutBy($owner)->orderBy('version')->orderBy('address')->get() as $ipAddress) {
            if ($this->mayHave($account, $ipAddress)) {
                $available[] = $ipAddress;
            }
        }

        return $available;
    }

    /**
     * Give a hosting account its own IPv4 and IPv6 address, or the server's
     * (null). Its websites are configured again to be served there, and
     * the website names in its DNS zones follow.
     *
     * @throws ValidationException
     */
    public function setAccountAddresses(User $account, ?IpAddress $ipv4, ?IpAddress $ipv6, User $actor): void
    {
        if (! $account->hasRole(Role::User)) {
            throw ValidationException::withMessages(['ipv4_address_id' => __('Only hosting accounts have addresses of their own.')]);
        }

        foreach (['ipv4_address_id' => [$ipv4, 4], 'ipv6_address_id' => [$ipv6, 6]] as $key => [$ipAddress, $version]) {
            if ($ipAddress === null) {
                continue;
            }

            if ($ipAddress->version !== $version) {
                throw ValidationException::withMessages([$key => __(':address is not an IPv:version address.', ['address' => $ipAddress->address, 'version' => $version])]);
            }

            if (! $this->mayHave($account, $ipAddress)) {
                throw ValidationException::withMessages([$key => __(':address is not available for this account.', ['address' => $ipAddress->address])]);
            }
        }

        DB::transaction(function () use ($account, $ipv4, $ipv6, $actor): void {
            $account = User::query()->lockForUpdate()->findOrFail($account->id);
            $previousIpv4 = $account->ipv4Address;
            $previousIpv6 = $account->ipv6Address;

            if ($previousIpv4?->id === $ipv4?->id && $previousIpv6?->id === $ipv6?->id) {
                return;
            }

            $account->forceFill(['ipv4_address_id' => $ipv4?->id, 'ipv6_address_id' => $ipv6?->id])->save();
            $account->setRelation('ipv4Address', $ipv4);
            $account->setRelation('ipv6Address', $ipv6);

            $this->domains->reapplyAccountWebsites($account, $actor);
            $this->dns->moveAccountAddresses($account, $previousIpv4?->address, $ipv4?->address, $previousIpv6?->address, $ipv6?->address, $actor);

            $this->audit->record('account.addresses_changed', $account, [
                'username' => $account->username,
                'from' => ['ipv4' => $previousIpv4?->address, 'ipv6' => $previousIpv6?->address],
                'to' => ['ipv4' => $ipv4?->address, 'ipv6' => $ipv6?->address],
            ], $actor);
        });
    }

    /**
     * Whether the account may have the address: one of its owner's, and
     * for a dedicated one, no other account has it.
     */
    private function mayHave(User $account, IpAddress $ipAddress): bool
    {
        $owner = $account->owner;

        if ($owner === null || ! $ipAddress->isHandedOutBy($owner)) {
            return false;
        }

        return ! $ipAddress->dedicated || ! $ipAddress->accounts()->whereKeyNot($account->id)->exists();
    }

    /**
     * @throws ValidationException
     */
    private function ensureUnused(IpAddress $ipAddress, string $message): void
    {
        if ($ipAddress->accounts()->exists()) {
            throw ValidationException::withMessages(['address' => $message]);
        }
    }

    /**
     * Whether the server uses the address as a main address of its
     * interface; when the agent cannot tell, it is not.
     */
    private function isMainAddress(string $address): bool
    {
        foreach ($this->serverAddresses() ?? [] as $serverAddress) {
            if ($serverAddress['address'] === $address && $serverAddress['primary'] && ! $serverAddress['added']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address as the agent writes it: IPv6 compressed and lowercase.
     */
    private function canonical(string $address): string
    {
        $packed = @inet_pton($address);

        return $packed === false ? $address : (string) inet_ntop($packed);
    }
}
