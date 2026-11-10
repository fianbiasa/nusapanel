<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSftpPasswordRequest;
use App\Services\SftpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SftpPasswordController extends Controller
{
    public function __construct(private SftpService $sftp) {}

    /**
     * Set the signed-in account's SFTP password.
     */
    public function update(UpdateSftpPasswordRequest $request): RedirectResponse
    {
        $account = $request->user();

        $this->sftp->setPassword($account, $request->string('password')->toString(), $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The SFTP password is being set.')]);

        return back();
    }

    /**
     * Remove the signed-in account's SFTP password.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $account = $request->user();

        $this->sftp->setPassword($account, null, $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The SFTP password is being removed.')]);

        return back();
    }
}
