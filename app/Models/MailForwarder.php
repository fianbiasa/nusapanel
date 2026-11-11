<?php

namespace App\Models;

use Database\Factories\MailForwarderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An email forwarder <local_part>@<domain>: Postfix sends its mail on to
 * its destinations (the v_mail_forwarders view). When a mailbox has the
 * same address, the mailbox keeps a copy.
 *
 * @property int $id
 * @property int $mail_domain_id
 * @property string $local_part
 * @property string $destinations Comma-separated addresses, as Postfix reads them.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['local_part'])]
class MailForwarder extends Model
{
    /** @use HasFactory<MailForwarderFactory> */
    use HasFactory;

    /**
     * The addresses mail goes on to.
     *
     * @return list<string>
     */
    public function destinationList(): array
    {
        return array_values(array_filter(explode(',', $this->destinations)));
    }

    /**
     * @return BelongsTo<MailDomain, $this>
     */
    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }
}
