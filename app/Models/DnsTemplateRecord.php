<?php

namespace App\Models;

use Database\Factories\DnsTemplateRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One record of the template new DNS zones start with, which the admin may
 * change. {domain} and {ip} in the content stand for the zone and the
 * server's address.
 *
 * @property int $id
 * @property string $name Relative to the zone; "@" is the zone itself.
 * @property string $type
 * @property string $content
 * @property int $ttl
 * @property int|null $priority MX and SRV only.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'content', 'ttl', 'priority'])]
class DnsTemplateRecord extends Model
{
    /** @use HasFactory<DnsTemplateRecordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'priority' => 'integer',
        ];
    }
}
