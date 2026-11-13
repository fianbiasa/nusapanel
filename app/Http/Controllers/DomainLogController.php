<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\SiteLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The logs page of a domain or subdomain, its log downloads and its visitor
 * report. A pointer is served by its domain's website, so its visits are
 * in that website's logs.
 */
class DomainLogController extends Controller
{
    /**
     * The report runs its own scripts, made from log lines visitors wrote,
     * so it is shown in a sandbox: without the panel's origin it cannot read
     * the panel's cookies or call it, and default-src 'none' leaves it no
     * network at all. GoAccess builds its templates with new Function, so
     * its scripts need 'unsafe-eval'.
     */
    private const REPORT_POLICY = "sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox; default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; img-src data:; font-src data:";

    public function __construct(private SiteLogService $logs) {}

    /**
     * Show the end of one of the website's logs.
     */
    public function show(Request $request, Domain $domain): Response
    {
        $this->authorizeWebsite($domain);

        $validated = $request->validate([
            'kind' => ['sometimes', Rule::in(SiteLogService::KINDS)],
            'lines' => ['sometimes', 'integer', Rule::in(SiteLogService::LINE_CHOICES)],
            'filter' => ['sometimes', 'nullable', 'string', 'max:'.SiteLogService::MAX_FILTER_LENGTH],
        ]);

        $kind = $validated['kind'] ?? 'access';
        $lines = (int) ($validated['lines'] ?? SiteLogService::LINE_CHOICES[0]);
        $filter = (string) ($validated['filter'] ?? '');

        return Inertia::render('domains/logs', [
            'domain' => [
                'id' => $domain->id,
                'name' => $domain->name,
                'display_name' => $domain->display_name,
                'status' => $domain->status->value,
            ],
            'kind' => $kind,
            'lines' => $lines,
            'filter' => $filter,
            'log' => fn (): array => $this->logs->tail($domain, $kind, $lines, $filter),
            'options' => [
                'kinds' => SiteLogService::KINDS,
                'line_choices' => SiteLogService::LINE_CHOICES,
                'max_filter' => SiteLogService::MAX_FILTER_LENGTH,
            ],
        ]);
    }

    /**
     * Download every kept log of the kind, gzipped.
     */
    public function download(Request $request, Domain $domain): BinaryFileResponse|RedirectResponse
    {
        $this->authorizeWebsite($domain);

        $kind = $request->validate(['kind' => ['required', Rule::in(SiteLogService::KINDS)]])['kind'];

        try {
            $file = $this->logs->download($domain, $kind, $request->user());
        } catch (ValidationException $exception) {
            return $this->failed($exception);
        }

        $response = response()->download($file['file'], $file['name'], ['Content-Type' => 'application/gzip']);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response->deleteFileAfterSend();
    }

    /**
     * Show the GoAccess report of the website's visits.
     */
    public function statistics(Domain $domain): BinaryFileResponse|RedirectResponse
    {
        $this->authorizeWebsite($domain);

        try {
            $file = $this->logs->statistics($domain);
        } catch (ValidationException $exception) {
            return $this->failed($exception);
        }

        $response = response()->file($file, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => self::REPORT_POLICY,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);

        return $response->deleteFileAfterSend();
    }

    private function authorizeWebsite(Domain $domain): void
    {
        Gate::authorize('update', $domain);

        abort_unless($domain->hasOwnFolder(), 404);
    }

    private function failed(ValidationException $exception): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => collect($exception->errors())->flatten()->first()]);

        return back();
    }
}
