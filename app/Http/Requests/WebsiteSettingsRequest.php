<?php

namespace App\Http\Requests;

use App\Models\Domain;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A change of the web settings of the website in the route. Only the
 * account that owns it may make one; other accounts' websites are reported
 * as missing.
 */
abstract class WebsiteSettingsRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->website());
    }

    /**
     * The website in the route.
     */
    public function website(): Domain
    {
        $domain = $this->route('domain');

        abort_unless($domain instanceof Domain, 404);

        return $domain;
    }
}
