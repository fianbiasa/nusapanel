<?php

namespace App\Models;

use App\Enums\FtpAccountStatus;
use Database\Factories\FtpAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An FTP login of a hosting account: <name>@<domain>, shut in a folder of
 * the account's home and acting as the account's Linux user.
 *
 * @property int $id
 * @property int $user_id
 * @property int $domain_id
 * @property string $login
 * @property string $password_hash SHA512-CRYPT, as Pure-FTPd checks it.
 * @property string $directory Relative to the home; "" is the whole home.
 * @property FtpAccountStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['login', 'directory'])]
#[Hidden(['password_hash'])]
class FtpAccount extends Model
{
    /** @use HasFactory<FtpAccountFactory> */
    use HasFactory;

    /**
     * The SHA512-CRYPT hash of password, which Pure-FTPd checks with the
     * system's crypt().
     */
    public static function hashPassword(string $password): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $salt = '';

        for ($i = 0; $i < 16; $i++) {
            $salt .= $alphabet[random_int(0, 63)];
        }

        return crypt($password, '$6$'.$salt.'$');
    }

    /**
     * Whether password is the login's password.
     */
    public function passwordMatches(string $password): bool
    {
        return hash_equals($this->password_hash, crypt($password, $this->password_hash));
    }

    /**
     * The folder as the customer sees it: "/" for the home, else
     * "/<directory>".
     */
    public function displayDirectory(): string
    {
        return '/'.Str::of($this->directory)->trim('/');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FtpAccountStatus::class,
        ];
    }

    /**
     * The hosting account the login belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The domain in the login's name.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
