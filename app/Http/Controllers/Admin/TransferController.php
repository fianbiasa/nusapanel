<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountTransfer;
use App\Services\AccountTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hosting accounts moved here from another RAYXO server: connecting to the
 * other panel with a login key, choosing its accounts, and how each move
 * goes (see AccountTransferService).
 */
class TransferController extends Controller
{
    public function __construct(private AccountTransferService $transfers) {}

    /**
     * The latest moves.
     */
    public function index(): Response
    {
        $transfers = AccountTransfer::query()->with('account:id,username,status')->latest('id')->limit(50)->get();

        return Inertia::render('admin/transfers/index', [
            'transfers' => $transfers->map(fn (AccountTransfer $transfer): array => [
                'id' => $transfer->id,
                'username' => $transfer->username,
                'source' => (string) parse_url($transfer->source_url, PHP_URL_HOST),
                'status' => $transfer->status,
                'error' => $transfer->error,
                'account_status' => $transfer->account?->status->value,
                'created_at' => $transfer->created_at?->toIso8601String(),
            ]),
            'going' => $transfers->contains(fn (AccountTransfer $transfer): bool => $transfer->isGoing()),
            'permissions' => AccountTransferService::PERMISSIONS,
        ]);
    }

    /**
     * Sign in to the other panel and send back its hosting accounts.
     */
    public function connect(Request $request): RedirectResponse
    {
        $validated = $this->validateSource($request);

        $found = $this->transfers->accountsOn(
            $this->transfers->normalizeUrl($validated['source_url']),
            $validated['api_key'],
            (bool) $validated['verify_tls'],
        );

        Inertia::flash('transfer_source', $found);

        return back();
    }

    /**
     * Move the accounts chosen here.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateSource($request, [
            'usernames' => ['required', 'array', 'min:1', 'max:500'],
            'usernames.*' => ['required', 'string', 'max:32', 'distinct'],
        ]);

        $started = $this->transfers->start(
            $validated['source_url'],
            $validated['api_key'],
            (bool) $validated['verify_tls'],
            array_values($validated['usernames']),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moving :count account(s) here. This page shows how each one goes.', ['count' => count($started)])]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function validateSource(Request $request, array $more = []): array
    {
        return $request->validate([
            'source_url' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'max:255'],
            'verify_tls' => ['required', 'boolean'],
            ...$more,
        ]);
    }
}
