<?php

namespace App\Http\Controllers;

use App\Enums\MailDomainMode;
use App\Enums\MailDomainStatus;
use App\Enums\SslStatus;
use App\Models\MailDomain;
use App\Rules\ValidDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The settings mail apps look up on their own for an address of a domain
 * whose mail this server takes: Thunderbird's autoconfig (from
 * autoconfig.<domain>) and Outlook's autodiscover (sent to the server
 * hostname by the _autodiscover._tcp record). Anyone may ask; nothing is
 * told about a domain that does not take mail here.
 */
class MailClientConfigController extends Controller
{
    /**
     * Thunderbird's config-v1.1.xml for the address in emailaddress, or for
     * the domain the request came to (autoconfig.<domain>).
     */
    public function autoconfig(Request $request): Response
    {
        $address = $request->query('emailaddress');
        $domain = is_string($address) ? self::domainOf($address) : null;

        if ($domain === null) {
            $host = strtolower($request->getHost());
            $domain = str_starts_with($host, 'autoconfig.') ? substr($host, strlen('autoconfig.')) : null;
        }

        $server = $domain === null ? null : $this->serverFor($domain);

        if ($domain === null || $server === null) {
            abort(404);
        }

        $name = e($domain);
        $host = e($server);

        $incoming = fn (string $type, int $port): string => "    <incomingServer type=\"{$type}\">\n"
            ."      <hostname>{$host}</hostname>\n      <port>{$port}</port>\n      <socketType>SSL</socketType>\n"
            ."      <authentication>password-cleartext</authentication>\n      <username>%EMAILADDRESS%</username>\n"
            ."    </incomingServer>\n";
        $outgoing = fn (int $port, string $socket): string => "    <outgoingServer type=\"smtp\">\n"
            ."      <hostname>{$host}</hostname>\n      <port>{$port}</port>\n      <socketType>{$socket}</socketType>\n"
            ."      <authentication>password-cleartext</authentication>\n      <username>%EMAILADDRESS%</username>\n"
            ."    </outgoingServer>\n";

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<clientConfig version=\"1.1\">\n"
            ."  <emailProvider id=\"{$name}\">\n    <domain>{$name}</domain>\n    <displayName>{$name}</displayName>\n"
            .$incoming('imap', 993).$incoming('pop3', 995).$outgoing(465, 'SSL').$outgoing(587, 'STARTTLS')
            ."  </emailProvider>\n</clientConfig>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * Outlook's autodiscover answer for the address in its request. The
     * request is read as text, never parsed as XML (no entities, no
     * external references).
     */
    public function autodiscover(Request $request): Response
    {
        $body = substr($request->getContent(), 0, 65536);

        preg_match('/<EMailAddress>\s*([^<\s]{3,254})\s*<\/EMailAddress>/i', $body, $match);

        $query = $request->query('email');
        $address = $match[1] ?? (is_string($query) ? $query : '');
        $domain = self::domainOf($address);
        $server = $domain === null ? null : $this->serverFor($domain);

        abort_if($server === null, 404);

        $login = e(strtolower($address));
        $host = e($server);

        $protocol = fn (string $type, int $port): string => "      <Protocol>\n        <Type>{$type}</Type>\n"
            ."        <Server>{$host}</Server>\n        <Port>{$port}</Port>\n        <DomainRequired>off</DomainRequired>\n"
            ."        <LoginName>{$login}</LoginName>\n        <SPA>off</SPA>\n        <SSL>on</SSL>\n"
            ."        <AuthRequired>on</AuthRequired>\n      </Protocol>\n";

        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
            ."<Autodiscover xmlns=\"http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006\">\n"
            ."  <Response xmlns=\"http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a\">\n"
            ."    <Account>\n      <AccountType>email</AccountType>\n      <Action>settings</Action>\n"
            .$protocol('IMAP', 993).$protocol('POP3', 995).$protocol('SMTP', 465)
            ."    </Account>\n  </Response>\n</Autodiscover>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * The domain of an email address, lowercase and in punycode, or null
     * when it is not one.
     */
    private static function domainOf(string $address): ?string
    {
        if (preg_match('/^[^@\s<>"]{1,64}@([^@\s<>"]{1,253})$/D', trim($address), $match) !== 1) {
            return null;
        }

        $domain = ValidDomain::normalize($match[1]);

        return is_string($domain) && ValidDomain::isWellFormed($domain) ? $domain : null;
    }

    /**
     * The name mail apps connect to for domain: mail.<domain> once it has
     * its own certificate, else the server hostname. Null when the domain
     * does not take mail here.
     */
    private function serverFor(string $domain): ?string
    {
        $mailDomain = MailDomain::query()
            ->whereHas('domain', fn ($query) => $query->where('name', $domain))
            ->where('status', MailDomainStatus::Active)
            ->where('mode', '!=', MailDomainMode::External)
            ->first();

        if ($mailDomain === null) {
            return null;
        }

        if ($mailDomain->ssl_status === SslStatus::Active) {
            return 'mail.'.$domain;
        }

        $hostname = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($hostname) && $hostname !== '' ? strtolower($hostname) : null;
    }
}
