<?php

namespace Tests\Feature\Email;

use App\Models\Domain;
use App\Models\MailDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MailDnsCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private MailDomain $mailDomain;

    protected function setUp(): void
    {
        parent::setUp();

        config(['hosting.public_ip' => '203.0.113.10']);

        $this->account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
        $this->mailDomain = MailDomain::factory()->for($domain)->create(['dkim_selector' => 'rayxo', 'dkim_public_key' => 'MIIBIjANtest']);
    }

    /**
     * Answer DNS over HTTPS like Cloudflare does, from records keyed
     * "<name> <type>" (TXT data quoted, as DNS over HTTPS gives it).
     *
     * @param  array<string, list<string>>  $records
     */
    private function dnsAnswers(array $records): void
    {
        $types = ['A' => 1, 'MX' => 15, 'TXT' => 16];

        Http::fake(function (Request $request) use ($records, $types) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $name = (string) $query['name'];
            $type = (string) $query['type'];
            $data = $records["{$name} {$type}"] ?? [];

            return Http::response([
                'Status' => 0,
                'Answer' => array_map(fn (string $value): array => ['name' => $name, 'type' => $types[$type], 'TTL' => 300, 'data' => $value], $data),
            ]);
        });
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        $records = $this->actingAs($this->account)
            ->getJson(route('email.dns-check', ['mailDomain' => $this->mailDomain, 'fresh' => 1]))
            ->assertOk()
            ->json('records');

        return collect($records)->mapWithKeys(fn (array $record): array => [$record['key'] => $record['status']])->all();
    }

    public function test_records_as_the_server_needs_them_are_ok()
    {
        $this->dnsAnswers([
            'example.com MX' => ['10 mail.example.com.'],
            'mail.example.com A' => ['203.0.113.10'],
            'example.com TXT' => ['"google-site-verification=abc"', '"v=spf1 a mx ip4:203.0.113.10 ~all"'],
            // Long DKIM keys come in several quoted strings.
            'rayxo._domainkey.example.com TXT' => ['"v=DKIM1; k=rsa; p=MIIBIj" "ANtest"'],
            '_dmarc.example.com TXT' => ['"v=DMARC1; p=none"'],
        ]);

        $this->assertSame(['mx' => 'ok', 'spf' => 'ok', 'dkim' => 'ok', 'dmarc' => 'ok'], $this->statuses());
    }

    public function test_missing_and_wrong_records_are_named()
    {
        $this->dnsAnswers([
            'example.com MX' => ['10 mx.elsewhere.example.'],
            'mx.elsewhere.example A' => ['198.51.100.7'],
            'example.com TXT' => ['"v=spf1 include:_spf.google.com ~all"'],
            'rayxo._domainkey.example.com TXT' => ['"v=DKIM1; k=rsa; p=AnOldKey"'],
        ]);

        $this->assertSame(['mx' => 'wrong', 'spf' => 'wrong', 'dkim' => 'wrong', 'dmarc' => 'missing'], $this->statuses());
    }

    public function test_two_spf_records_are_wrong_even_if_one_allows_the_server()
    {
        $this->dnsAnswers([
            'example.com TXT' => ['"v=spf1 a mx ~all"', '"v=spf1 include:_spf.google.com ~all"'],
        ]);

        $this->assertSame('wrong', $this->statuses()['spf']);
    }

    public function test_unreachable_resolvers_leave_the_status_unknown()
    {
        Http::fake(fn () => Http::response('', 503));

        $this->assertSame(['mx' => 'unknown', 'spf' => 'unknown', 'dkim' => 'unknown', 'dmarc' => 'unknown'], $this->statuses());
    }

    public function test_the_result_is_kept_for_five_minutes_unless_asked_again()
    {
        $this->dnsAnswers([]);

        $this->actingAs($this->account)->getJson(route('email.dns-check', $this->mailDomain))->assertOk();
        $sent = count(Http::recorded());

        $this->actingAs($this->account)->getJson(route('email.dns-check', $this->mailDomain))->assertOk();
        $this->assertCount($sent, Http::recorded());

        $this->actingAs($this->account)->getJson(route('email.dns-check', ['mailDomain' => $this->mailDomain, 'fresh' => 1]))->assertOk();
        $this->assertGreaterThan($sent, count(Http::recorded()));
    }

    public function test_other_accounts_cannot_check_the_domain()
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->getJson(route('email.dns-check', $this->mailDomain))
            ->assertNotFound();

        Http::assertNothingSent();
    }
}
