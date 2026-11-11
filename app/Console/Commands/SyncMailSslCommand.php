<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\MailDomainMode;
use App\Enums\MailDomainStatus;
use App\Models\MailDomain;
use App\Services\MailService;
use Illuminate\Console\Command;

/**
 * Asks a certificate for mail.<domain> of every domain that takes mail
 * here and never had one asked (such as those set up before mail
 * certificates existed), and has Dovecot and Postfix serve every
 * mail.<domain> with the certificate it has now, so a domain's new or
 * renewed wildcard or custom certificate is picked up too. The scheduler
 * runs it daily; setup.sh after each install or upgrade.
 */
class SyncMailSslCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:sync-mail-ssl';

    /**
     * @var string
     */
    protected $description = 'Queue certificates for mail.<domain> and serve them in Dovecot and Postfix';

    public function handle(MailService $mail): int
    {
        $requested = 0;

        MailDomain::query()
            ->with('domain.account')
            ->where('status', MailDomainStatus::Active)
            ->where('mode', MailDomainMode::Local)
            ->whereNull('ssl_status')
            ->each(function (MailDomain $mailDomain) use ($mail, &$requested): void {
                if ($mailDomain->domain->account->status === AccountStatus::Active) {
                    $mail->requestMailSsl($mailDomain, null);
                    $requested++;
                }
            });

        $mail->syncMailSni();

        $this->components->info("Certificates asked for {$requested} mail domain(s); serving them queued. The queue worker does the rest in the background; see Tasks in the panel.");

        return self::SUCCESS;
    }
}
