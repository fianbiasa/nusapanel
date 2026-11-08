<?php

namespace App\Http\Controllers;

use App\Enums\PointerMode;
use App\Http\Requests\StorePointerRequest;
use App\Models\Domain;
use App\Services\DomainService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PointerController extends Controller
{
    public function __construct(private DomainService $domains) {}

    /**
     * Add a pointer to one of the signed-in account's domains; the agent
     * sets it up on the server in the background.
     */
    public function store(StorePointerRequest $request): RedirectResponse
    {
        $parent = Domain::query()->findOrFail($request->integer('domain_id'));

        $pointer = $this->domains->createPointer(
            $parent,
            $request->string('name')->toString(),
            $request->enum('mode', PointerMode::class) ?? PointerMode::Alias,
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Pointer :domain is being set up.', ['domain' => $pointer->display_name]),
        ]);

        return to_route('domains.index');
    }
}
