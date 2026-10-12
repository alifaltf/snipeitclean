<?php

namespace App\Http\Controllers\Assets;

use App\Actions\AssetImport\ConfigureAssetImportTarget;
use App\Actions\AssetImport\CreateAssetImportSession;
use App\Actions\AssetImport\ExecuteAssetImport;
use App\Actions\AssetImport\SaveAssetImportMapping;
use App\Actions\AssetImport\ValidateAssetImportSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetImport\StoreAssetImportMappingRequest;
use App\Http\Requests\AssetImport\StoreAssetImportTargetRequest;
use App\Http\Requests\AssetImport\StoreAssetImportUploadRequest;
use App\Models\Asset;
use App\Models\AssetCategoryViewScope;
use App\Models\AssetImportSession;
use App\Models\User;
use App\Services\AssetImport\AssetImportAuthorizer;
use App\Services\AssetImport\AssetImportCsvSampler;
use App\Services\AssetImport\AssetImportFieldCatalog;
use App\Services\AssetImport\AssetImportFileException;
use App\Services\AssetImport\AssetImportMappingSuggester;
use App\Services\AssetImport\AssetImportReadiness;
use App\Services\AssetImport\AssetImportSessions;
use App\Services\AssetImport\AssetImportTargetCheck;
use App\Services\AssetImport\Execution\AssetImportExecutionOutcome;
use App\Services\AssetImport\Validation\AssetImportErrorReport;
use App\Services\AssetImport\Validation\AssetImportValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

/**
 * ERS Phase 6A/6B/6C: the secure asset CSV import, steps 1-6 (upload,
 * target, mapping, review, validate, preview) and the confirmed import.
 * Only execute() creates anything, and only new assets, through
 * ExecuteAssetImport (all rows or none). No master data is ever created.
 *
 * Every action requires the global import permission (route middleware),
 * then AssetImportAuthorizer::mayUse (assets.create plus category Create
 * on at least one final category), then, for every session-specific
 * step, AssetImportSessions::findOpen: owner, open state, expiry and the
 * stored file's SHA-256 (generic 404 otherwise). The review, validate,
 * results and error-report steps also require AssetImportReadiness: the
 * target still valid for this user and the mapping saved for this file.
 */
class AssetImportController extends Controller
{
    public function __construct(
        private readonly AssetImportAuthorizer $authorizer,
        private readonly AssetImportSessions $sessions,
        private readonly AssetImportTargetCheck $targets,
        private readonly AssetImportFieldCatalog $catalog,
    ) {}

    /** How many of the created assets the final result lists (all are counted). */
    private const RESULT_LIST_SIZE = 100;

    public function index(Request $request): View
    {
        $user = $this->authorizedUser($request);

        // ?category= comes from a category's "Import CSV" button. It is only
        // honoured for a final category the user may import into.
        $categoryId = $this->preselectedCategory($user, $request->query('category'));

        return view('hardware/import/index', [
            'sessions' => AssetImportSession::query()->openFor($user)->latest('id')->limit(20)->get(),
            'categoryId' => $categoryId,
            'categoryLabel' => $categoryId !== null ? ($this->authorizer->categoryOptions($user)[$categoryId] ?? null) : null,
            'completed' => $this->completedImport($request),
        ]);
    }

    public function store(StoreAssetImportUploadRequest $request, CreateAssetImportSession $create): RedirectResponse
    {
        try {
            $session = $create->handle($request->user(), $request->file('csv_file'));
        } catch (AssetImportFileException $e) {
            return redirect()->route('hardware.import.index')->withErrors(['csv_file' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('hardware.import.index')->with('error', trans('admin/hardware/import.upload.failed'));
        }

        // Carry a re-validated category forward to pre-select it; the target
        // step validates it again and only saving the target stores it.
        $categoryId = $this->preselectedCategory($request->user(), $request->input('category'));

        return redirect()->route('hardware.import.target', array_filter([$session->public_id, 'category' => $categoryId]));
    }

    public function editTarget(Request $request, string $session): View
    {
        $user = $this->authorizedUser($request);
        $importSession = $this->sessions->findOpen($session, $user);

        // ?category= only chooses which models to list; it is never saved here.
        $requested = $request->query('category');
        $categoryId = $this->authorizer->allowsCategory($user, $requested)
            ? (int) $requested
            : ($this->authorizer->allowsCategory($user, $importSession->category_id) ? $importSession->category_id : null);

        return view('hardware/import/target', [
            'importSession' => $importSession,
            'categoryId' => $categoryId,
            'categories' => $this->authorizer->categoryOptions($user),
            'models' => $this->authorizer->modelOptions($user, $categoryId),
            'statuses' => $this->authorizer->statusOptions(),
            'companies' => $this->authorizer->companyOptions(),
            'locations' => $this->authorizer->locationOptions(),
        ]);
    }

    public function updateTarget(StoreAssetImportTargetRequest $request, string $session, ConfigureAssetImportTarget $configure): RedirectResponse
    {
        $importSession = $configure->handle($request->user(), $session, $request->all());

        return redirect()->route('hardware.import.mapping', $importSession->public_id);
    }

    public function editMapping(Request $request, string $session, AssetImportMappingSuggester $suggester, AssetImportCsvSampler $sampler): View|RedirectResponse
    {
        $user = $this->authorizedUser($request);
        $importSession = $this->sessions->findOpen($session, $user);

        if (! $this->targets->isValid($importSession, $user)) {
            return $this->backToTarget($importSession);
        }

        $destinations = $this->catalog->destinations($importSession, $user);
        $saved = $importSession->mappedDestinations();

        return view('hardware/import/mapping', [
            'importSession' => $importSession,
            'destinations' => $destinations,
            'selected' => $saved !== [] ? $saved : $suggester->suggest($importSession->headerList(), $destinations),
            'suggested' => $saved === [],
            'samples' => $sampler->samples($importSession),
        ]);
    }

    public function updateMapping(StoreAssetImportMappingRequest $request, string $session, SaveAssetImportMapping $save): RedirectResponse
    {
        $importSession = $save->handle($request->user(), $session, $request->input('mapping'));

        return redirect()->route('hardware.import.review', $importSession->public_id);
    }

    public function review(Request $request, string $session, AssetImportReadiness $readiness): View|RedirectResponse
    {
        $user = $this->authorizedUser($request);
        $importSession = $this->sessions->findOpen($session, $user);

        $problem = $readiness->problem($importSession, $user);
        if ($problem !== null) {
            return $this->backTo($importSession, $problem);
        }

        return view('hardware/import/review', [
            'importSession' => $importSession,
            'destinations' => $this->catalog->destinations($importSession, $user),
            'requiredKeys' => $this->catalog->requiredKeys($importSession),
            'categoryLabel' => $this->authorizer->categoryOptions($user)[$importSession->category_id] ?? '',
            'model' => $this->authorizer->model($user, $importSession->category_id, $importSession->model_id),
            'status' => $this->authorizer->status($importSession->status_id),
            'company' => $this->authorizer->company($importSession->company_id),
            'location' => $this->authorizer->location($importSession->location_id),
        ]);
    }

    public function validateRows(Request $request, string $session, ValidateAssetImportSession $validate): RedirectResponse
    {
        $user = $this->authorizedUser($request);
        [$importSession, $problem] = $validate->handle($user, $session);

        if ($problem !== null) {
            return $this->backTo($importSession, $problem);
        }

        return redirect()->route('hardware.import.results', $importSession->public_id);
    }

    public function results(Request $request, string $session, AssetImportReadiness $readiness, AssetImportValidator $validator): View|RedirectResponse
    {
        $user = $this->authorizedUser($request);
        $importSession = $this->sessions->findOpen($session, $user);

        $redirect = $this->resultsRedirect($importSession, $user, $readiness);
        if ($redirect !== null) {
            return $redirect;
        }

        // Validation runs again from the stored file on every view, so the
        // results always reflect the current data and nothing derived from
        // the CSV is ever stored.
        $result = $validator->validate($importSession, $user);
        $invalidOnly = $request->query('filter') === 'invalid';
        $rows = $invalidOnly ? array_values(array_filter($result->rows, fn ($row) => ! $row->isValid())) : $result->rows;

        $perPage = max(1, (int) config('asset_import.preview_page_size'));
        $page = max(1, min(AssetImportAuthorizer::positiveInt($request->query('page')) ?? 1, (int) ceil(max(1, count($rows)) / $perPage)));
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page, [
            'path' => route('hardware.import.results', $importSession->public_id),
            'query' => $invalidOnly ? ['filter' => 'invalid'] : [],
        ]);

        return view('hardware/import/results', [
            'importSession' => $importSession,
            'result' => $result,
            'rows' => $paginator,
            'invalidOnly' => $invalidOnly,
            'destinations' => $this->catalog->destinations($importSession, $user),
            'categoryLabel' => $this->authorizer->categoryOptions($user)[$importSession->category_id] ?? '',
            // Eligible only when the recorded validation passed AND it still
            // passes now; Phase 6C validates once more before writing.
            'eligible' => $eligible = $importSession->state === AssetImportSession::STATE_VALIDATED && $result->isEligible() && ExecuteAssetImport::confirmationToken($importSession) !== null,
            'stale' => ($importSession->state === AssetImportSession::STATE_VALIDATED) !== $result->isEligible(),
            'confirmationToken' => $eligible ? ExecuteAssetImport::confirmationToken($importSession) : null,
            'executionErrors' => $importSession->state === AssetImportSession::STATE_EXECUTION_FAILED ? (array) $importSession->execution_errors : [],
        ]);
    }

    public function errorReport(Request $request, string $session, AssetImportReadiness $readiness, AssetImportValidator $validator, AssetImportErrorReport $report): Response|RedirectResponse
    {
        $user = $this->authorizedUser($request);
        $importSession = $this->sessions->findOpen($session, $user);

        $redirect = $this->resultsRedirect($importSession, $user, $readiness);
        if ($redirect !== null) {
            return $redirect;
        }

        $result = $validator->validate($importSession, $user);
        if ($result->errorCount() === 0) {
            return redirect()->route('hardware.import.results', $importSession->public_id)
                ->with('info', trans('admin/hardware/import.results.no_errors'));
        }

        return response($report->csv($result), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="asset-import-errors-'.substr($importSession->public_id, 0, 8).'.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Phase 6C: import every row of a validated session, or none. POST only
     * (CSRF-protected); the user must tick the confirmation and send back
     * the token of the validation they reviewed. Every check of the
     * earlier steps runs again inside the import transaction.
     */
    public function execute(Request $request, string $session, ExecuteAssetImport $execute): RedirectResponse
    {
        $user = $this->authorizedUser($request);
        $request->validate([
            'confirm' => ['required', 'accepted'],
            'confirmation' => ['required', 'string', 'size:64'],
        ], [
            'confirm.*' => trans('admin/hardware/import.execution.confirm_required'),
            'confirmation.*' => trans('admin/hardware/import.execution.not_confirmed'),
        ]);

        $outcome = $execute->handle($user, $session, $request->input('confirmation'));
        $results = fn () => redirect()->route('hardware.import.results', $outcome->session?->public_id ?? $session);

        return match ($outcome->status) {
            AssetImportExecutionOutcome::COMPLETED => redirect()->route('hardware.import.index')
                ->with('success', trans_choice('admin/hardware/import.execution.completed', $outcome->session->imported_count, ['count' => number_format((int) $outcome->session->imported_count)]))
                ->with('asset_import_completed', [
                    'count' => (int) $outcome->session->imported_count,
                    'file' => (string) $outcome->session->original_filename,
                    'asset_ids' => array_slice($outcome->assetIds, 0, self::RESULT_LIST_SIZE),
                ]),
            AssetImportExecutionOutcome::NEEDS_RECONFIGURATION => $this->backTo($outcome->session, (string) $outcome->problem),
            AssetImportExecutionOutcome::NOT_VALIDATED => $results()->with('warning', trans('admin/hardware/import.execution.not_validated')),
            AssetImportExecutionOutcome::NOT_CONFIRMED => $results()->with('warning', trans('admin/hardware/import.execution.not_confirmed')),
            AssetImportExecutionOutcome::REVALIDATION_FAILED => $results()->with('error', trans('admin/hardware/import.execution.revalidation_failed')),
            AssetImportExecutionOutcome::EXECUTION_FAILED => $results()->with('error', trans('admin/hardware/import.execution.failed')),
            AssetImportExecutionOutcome::BUSY => $results()->with('warning', trans('admin/hardware/import.execution.busy')),
        };
    }

    /** Phase 6C: the safe diagnostics of the last rolled-back execution, as CSV. */
    public function executionErrorReport(Request $request, string $session, AssetImportReadiness $readiness, AssetImportErrorReport $report): Response|RedirectResponse
    {
        $user = $this->authorizedUser($request);
        $importSession = $this->sessions->findOpen($session, $user);

        $redirect = $this->resultsRedirect($importSession, $user, $readiness);
        if ($redirect !== null) {
            return $redirect;
        }

        $errors = $importSession->state === AssetImportSession::STATE_EXECUTION_FAILED ? (array) $importSession->execution_errors : [];
        if ($errors === []) {
            return redirect()->route('hardware.import.results', $importSession->public_id)
                ->with('info', trans('admin/hardware/import.execution.no_errors'));
        }

        return response($report->rows(array_map(fn ($error) => [
            $error['row'] ?? '', $error['asset_tag'] ?? '', $error['field'] ?? '', $error['message'] ?? '',
        ], $errors)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="asset-import-execution-errors-'.substr($importSession->public_id, 0, 8).'.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The result of an import completed in the previous request, from the
     * ids the execution flashed into this user's session: the count, and
     * the tags of those assets that this user created. Category View is
     * not required to import (approved Phase 6 decision), so only the
     * category-view scope is lifted, and only for the user's own new
     * assets; the company scope still applies. The view links a tag only
     * when the user may view that asset.
     *
     * @return array{count: int, file: string, assets: \Illuminate\Support\Collection<int, Asset>, viewable: array<int, int>}|null
     */
    private function completedImport(Request $request): ?array
    {
        $flash = $request->session()->get('asset_import_completed');
        if (! is_array($flash) || ! isset($flash['count'], $flash['asset_ids']) || ! is_array($flash['asset_ids'])) {
            return null;
        }
        $ids = array_values(array_filter($flash['asset_ids'], 'is_int'));

        $assets = $ids === [] ? collect() : Asset::query()
            ->withoutGlobalScope(AssetCategoryViewScope::class)
            ->where('created_by', $request->user()->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'asset_tag', 'name', 'company_id', 'model_id']);

        return [
            'count' => (int) $flash['count'],
            'file' => (string) ($flash['file'] ?? ''),
            'assets' => $assets,
            // Linked only when the normal (category-scoped) asset pages
            // would show it; the view also checks the native view policy.
            'viewable' => $assets->isEmpty() ? [] : array_flip(Asset::query()->whereIn('id', $assets->modelKeys())->pluck('id')->all()),
        ];
    }

    /** Where to send the user instead of the results, or null when results can be shown. */
    private function resultsRedirect(AssetImportSession $importSession, User $user, AssetImportReadiness $readiness): ?RedirectResponse
    {
        $problem = $readiness->problem($importSession, $user);
        if ($problem !== null) {
            return $this->backTo($importSession, $problem);
        }

        if (! in_array($importSession->state, AssetImportSession::VALIDATION_STATES, true)) {
            return redirect()->route('hardware.import.review', $importSession->public_id)
                ->with('warning', trans('admin/hardware/import.results.validate_first'));
        }

        return null;
    }

    private function backTo(AssetImportSession $importSession, string $problem): RedirectResponse
    {
        if ($problem === AssetImportReadiness::NEEDS_TARGET) {
            return $this->backToTarget($importSession);
        }

        return redirect()->route('hardware.import.mapping', $importSession->public_id)
            ->with('warning', trans('admin/hardware/import.review.mapping_needed'));
    }

    private function preselectedCategory(User $user, mixed $categoryId): ?int
    {
        return $this->authorizer->allowsCategory($user, $categoryId) ? (int) $categoryId : null;
    }

    private function authorizedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->authorizer->mayUse($user), 403);

        return $user;
    }

    private function backToTarget(AssetImportSession $importSession): RedirectResponse
    {
        return redirect()->route('hardware.import.target', $importSession->public_id)
            ->with('warning', trans('admin/hardware/import.target.reconfigure'));
    }
}
