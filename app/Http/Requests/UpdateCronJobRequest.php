<?php

namespace App\Http\Requests;

use App\Models\CronJob;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class UpdateCronJobRequest extends StoreCronJobRequest
{
    /**
     * Only the account that owns the job; other accounts' jobs are reported
     * as missing.
     */
    public function authorize(): Response
    {
        $cronJob = $this->route('cronJob');

        abort_unless($cronJob instanceof CronJob, 404);

        return Gate::inspect('update', $cronJob);
    }

    /**
     * The rules of a new job, and whether it runs.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'enabled' => ['required', 'boolean']];
    }
}
