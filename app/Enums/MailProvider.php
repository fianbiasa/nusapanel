<?php

namespace App\Enums;

/**
 * The service an external mail domain's mail goes to. For Google Workspace
 * and Zoho Mail the panel sets the MX records and the SPF include of the
 * service in the domain's zone (as their help pages give them); for any
 * other service the customer sets those themselves.
 */
enum MailProvider: string
{
    case Google = 'google';
    case Zoho = 'zoho';
    case Other = 'other';

    /**
     * The MX records the service takes mail on; none for another service.
     *
     * @return list<array{host: string, priority: int}>
     */
    public function exchangers(): array
    {
        return match ($this) {
            self::Google => [['host' => 'smtp.google.com', 'priority' => 1]],
            self::Zoho => [
                ['host' => 'mx.zoho.com', 'priority' => 10],
                ['host' => 'mx2.zoho.com', 'priority' => 20],
                ['host' => 'mx3.zoho.com', 'priority' => 50],
            ],
            self::Other => [],
        };
    }

    /**
     * The SPF term that lets the service send for the domain.
     */
    public function spfInclude(): ?string
    {
        return match ($this) {
            self::Google => 'include:_spf.google.com',
            self::Zoho => 'include:zohomail.com',
            self::Other => null,
        };
    }

    /**
     * Whether the panel sets the service's DNS records.
     */
    public function managesDns(): bool
    {
        return $this->exchangers() !== [];
    }
}
