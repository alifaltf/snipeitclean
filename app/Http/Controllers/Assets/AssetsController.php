<?php

namespace App\Http\Controllers\Assets;

use App\Events\CheckoutableCheckedIn;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMultipleAssetRequest;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Requests\UploadFileRequest;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\CheckoutRequest;
use App\Models\Company;
use App\Models\Location;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\User;
use App\Observers\AssetObserver;
use App\View\Label;
use Carbon\Carbon;
use Com\Tecnick\Barcode\Barcode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use TypeError;

/**
 * This class controls all actions related to assets for
 * the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 *
 * @author [A. Gianotto] [<snipe@snipe.net>]
 */
class AssetsController extends Controller
{
    protected $qrCodeDimensions = ['height' => 3.5, 'width' => 3.5];

    protected $barCodeDimensions = ['height' => 2, 'width' => 22];

    public function __construct()
    {
        $this->middleware('auth');
        parent::__construct();
    }

    /**
     * Returns a view that invokes the ajax tables which actually contains
     * the content for the assets listing, which is generated in getDatatable.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see AssetController::getDatatable() method that generates the JSON response
     * @since [v1.0]
     */
    public function index(Request $request): View
    {
        $this->authorize('index', Asset::class);
        $companyId = $request->input('company_id');
        $company = is_scalar($companyId) ? Company::find($companyId) : null;

        // ERS Hardware/Software sidebar links here (see
        // config/ers_assets.php + SidebarComposer). Two independent,
        // conservatively-validated filters can arrive:
        //
        //   - asset_group: selects a virtual "Hardware"/"Software" PARENT
        //     group (a config('ers_assets') key). "Hardware" and
        //     "Software" are sidebar-only groupings, not Snipe-IT
        //     categories, so they have no category_id of their own — the
        //     underlying category id list is ALWAYS resolved here,
        //     server-side, via a fresh DB lookup of every category_type =
        //     asset category whose `ers_asset_group` column matches the
        //     requested group key. The client only ever selects *which
        //     configured group* to expand; it can never hand in its own
        //     id list (a value like "hardware,1,2,3" simply isn't a
        //     configured group key, so it resolves to nothing and falls
        //     back to unfiltered).
        //   - category_id: selects one specific EXISTING
        //     category_type=asset category directly (a Hardware child
        //     like Laptop, a Software child like Digital Systems, or any
        //     other asset category, grouped or not) — unchanged from the
        //     original single-category ERS behavior. Only a scalar,
        //     all-digit value is even considered (guards against
        //     array-shaped query input, e.g. ?category_id[]=1).
        //
        // Both are deliberately conservative: anything that doesn't
        // resolve cleanly (unknown group, empty group, missing/deleted/
        // non-asset category, non-numeric or array-shaped input) leaves
        // the page exactly as the unfiltered "All Assets" view. Nothing
        // throws, and only category_type=asset rows can ever be reached
        // this way — this can't be used to expose accessory/consumable/
        // component/license categories or any other unrelated data.
        $ersCategoryIds = null;
        $ersPageTitle = null;
        // Which virtual group (if any) the CURRENT page belongs to —
        // 'hardware', 'software', or null for "All Assets" / a filter
        // that doesn't resolve to a group (unknown asset_group, or a
        // category_id whose category has no ers_asset_group assigned).
        // Drives which of the All Assets / Hardware / Software nav
        // buttons below is shown as active — see resources/views/
        // hardware/index.blade.php. Resolved from the SAME lookups as
        // $ersCategoryIds/$ersPageTitle just below, never re-derived
        // from anything client-supplied.
        $ersActiveGroupKey = null;
        // Which specific category (if any) within the active group is
        // selected — drives the second-row "[All Hardware] [Desktop]
        // [Laptop] ..." category buttons below. Only ever set alongside
        // $ersActiveGroupKey (i.e. only for a category that actually
        // belongs to a configured, resolvable group); null means "the
        // group's own All Hardware/All Software aggregate is active",
        // which is also correct when no group is active at all, since
        // the second row isn't rendered in that case.
        $ersActiveCategoryId = null;

        $assetGroupKey = $request->input('asset_group');
        if (is_scalar($assetGroupKey) && $assetGroupKey !== '') {
            $group = $this->resolveErsAssetGroup((string) $assetGroupKey);

            if ($group !== null && $group['category_ids'] !== []) {
                $ersCategoryIds = implode(',', $group['category_ids']);
                $ersPageTitle = $group['title'];
                $ersActiveGroupKey = (string) $assetGroupKey;
            }
        }

        // asset_group takes priority when it resolves; category_id is
        // only consulted when no group filter was applied (mirrors the
        // active-state precedence used in the sidebar Blade).
        if ($ersCategoryIds === null) {
            $categoryId = $request->input('category_id');
            if (is_scalar($categoryId) && ctype_digit((string) $categoryId)) {
                $category = Category::query()
                    ->where('category_type', 'asset')
                    ->find((int) $categoryId);

                if ($category) {
                    $ersCategoryIds = (string) $category->id;
                    $ersPageTitle = $category->name.' '.trans('general.assets');

                    if (is_string($category->ers_asset_group) && array_key_exists($category->ers_asset_group, (array) config('ers_assets', []))) {
                        $ersActiveGroupKey = $category->ers_asset_group;
                        $ersActiveCategoryId = $category->id;
                    }
                }
            }
        }

        // The bare "All Assets" page (no company scoping, no status
        // filter, and no ERS asset_group/category_id filter that
        // actually resolved) shows the plain "Assets" title, matching
        // its breadcrumb leaf — see App\Providers\BreadcrumbsServiceProvider
        // 's 'hardware.index' registration. Every other existing title
        // combination (company scoping, status filters) is left
        // completely untouched.
        if ($ersPageTitle === null && ! $request->filled('company_id') && ! $request->filled('status_type')) {
            $ersPageTitle = trans('general.assets');
        }

        return view('hardware/index')
            ->with('company', $company)
            ->with('ers_category_ids', $ersCategoryIds)
            ->with('ers_page_title', $ersPageTitle)
            ->with('ers_active_group', $ersActiveGroupKey)
            ->with('ers_nav_groups', $this->ersNavGroups())
            ->with('ers_active_category_id', $ersActiveCategoryId)
            ->with('ers_category_buttons', $this->ersCategoryButtons($ersActiveGroupKey));
    }

    /**
     * Builds the [All Assets] [Hardware] [Software] nav button list shown
     * on resources/views/hardware/index.blade.php. Iterates
     * config('ers_assets')'s own keys — never a hard-coded 'hardware'/
     * 'software' literal — so an admin-configured group automatically
     * gets a button the moment it has at least one category_type=asset
     * category assigned to it, and loses its button the moment it has
     * none, with no code change required. "All Assets" itself is always
     * shown (rendered directly in the Blade, not from this list) since
     * it is never hidden.
     *
     * @return array<string, array{label: string, url: string}>
     */
    private function ersNavGroups(): array
    {
        $groups = [];

        foreach ((array) config('ers_assets', []) as $groupKey => $group) {
            if (! is_string($groupKey) || $groupKey === '' || ! is_array($group)) {
                continue;
            }

            $hasAssignedCategories = Category::query()
                ->where('category_type', 'asset')
                ->where('ers_asset_group', $groupKey)
                ->exists();

            if (! $hasAssignedCategories) {
                continue;
            }

            $groups[$groupKey] = [
                'label' => is_string($group['label'] ?? null) ? $group['label'] : ucfirst($groupKey),
                'url' => route('hardware.index', ['asset_group' => $groupKey]),
            ];
        }

        return $groups;
    }

    /**
     * Builds the second-row "[All Hardware] [Desktop] [Laptop] ..."
     * category filter buttons shown below the [All Assets] [Hardware]
     * [Software] row on resources/views/hardware/index.blade.php, for
     * whichever group (if any) is currently active.
     *
     * Returns [] whenever there's nothing to show for a second row:
     * $activeGroupKey is null (bare "All Assets", or a filter that
     * didn't resolve to a group), the key isn't a configured
     * config('ers_assets') group, or — belt-and-suspenders, since
     * $activeGroupKey can only ever be set elsewhere in this class once
     * at least one matching category was already confirmed to exist —
     * the group currently has zero category_type=asset categories
     * assigned to it. Categories are loaded fresh from the database on
     * every call, ordered alphabetically by name; nothing here is a
     * hard-coded category name or ID, and a category from a different
     * group (or with no group at all) can never appear, since the query
     * is scoped to category_type = asset AND ers_asset_group =
     * $activeGroupKey.
     *
     * @return array{all: array{label: string, url: string}, categories: list<array{id: int, label: string, url: string}>}|array{}
     */
    private function ersCategoryButtons(?string $activeGroupKey): array
    {
        if ($activeGroupKey === null || $activeGroupKey === '') {
            return [];
        }

        $groups = (array) config('ers_assets', []);

        if (! array_key_exists($activeGroupKey, $groups) || ! is_array($groups[$activeGroupKey])) {
            return [];
        }

        $group = $groups[$activeGroupKey];

        $categories = Category::query()
            ->where('category_type', 'asset')
            ->where('ers_asset_group', $activeGroupKey)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($categories->isEmpty()) {
            return [];
        }

        $groupLabel = is_string($group['label'] ?? null) ? $group['label'] : ucfirst($activeGroupKey);
        $allLabel = is_string($group['all_label'] ?? null) ? $group['all_label'] : (trans('general.all').' '.$groupLabel);

        return [
            'group_label' => $groupLabel,
            'all' => [
                'label' => $allLabel,
                'url' => route('hardware.index', ['asset_group' => $activeGroupKey]),
            ],
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'label' => $category->name,
                'url' => route('hardware.index', ['category_id' => $category->id]),
            ])->all(),
        ];
    }

    /**
     * Resolves a single ERS virtual sidebar group (e.g. "hardware") into
     * the category IDs it currently covers.
     *
     * $groupKey is untrusted request input, but it is only ever used two
     * ways: (1) an exact array key lookup against the server-defined
     * config('ers_assets') array, which supplies presentation only — never
     * interpolated into a dotted config path; and (2) an exact-match value
     * in a `where('ers_asset_group', $groupKey)` query, which is safely
     * parameter-bound by the query builder, never raw SQL. So it cannot be
     * used to reach anything outside config('ers_assets')'s own top-level
     * groups or to inject anything into the query. Category IDs are
     * resolved fresh from the database on every call, by
     * category_type = asset AND ers_asset_group = $groupKey — never
     * hard-coded, and a category is included the moment an administrator
     * assigns it to this group and excluded the moment they change or
     * clear it.
     *
     * @return array{category_ids: list<int>, title: ?string}|null null when
     *                                                             $groupKey isn't a configured group at all.
     */
    private function resolveErsAssetGroup(string $groupKey): ?array
    {
        $groups = (array) config('ers_assets', []);

        if (! array_key_exists($groupKey, $groups) || ! is_array($groups[$groupKey])) {
            return null;
        }

        $group = $groups[$groupKey];

        $categoryIds = Category::query()
            ->where('category_type', 'asset')
            ->where('ers_asset_group', $groupKey)
            ->orderBy('name')
            ->pluck('id')
            ->all();

        return [
            'category_ids' => $categoryIds,
            'title' => is_string($group['all_title'] ?? null) ? $group['all_title'] : ($group['all_label'] ?? null),
        ];
    }

    /**
     * Returns a view that presents a form to create a new asset.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v1.0]
     *
     * @internal param int $model_id
     */
    public function create(Request $request): View
    {
        $this->authorize('create', Asset::class);
        $view = view('hardware/edit')
            ->with('statuslabel_list', Helper::statusLabelList())
            ->with('item', new Asset)
            ->with('statuslabel_types', Helper::statusTypeList());

        if ($request->filled('model_id')) {
            $selected_model = AssetModel::find($request->input('model_id'));
            $view->with('selected_model', $selected_model);
        }

        return $view;
    }

    /**
     * Validate and process new asset form data.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v1.0]
     */
    public function store(CreateMultipleAssetRequest $request): RedirectResponse
    {
        $this->authorize(Asset::class);

        // There are a lot more rules to add here but prevents
        // errors around `asset_tags` not being present below.
        $this->validate($request, ['asset_tags' => ['required', 'array']]);

        // Handle asset tags - there could be one, or potentially many.
        // This is only necessary on create, not update, since bulk editing is handled
        // differently
        $asset_tags = $request->input('asset_tags');
        $model = AssetModel::find($request->input('model_id'));
        $serial_errors = [];
        $serials = $request->input('serials');

        $settings = Setting::getSettings();

        // Validate required serial based on model setting
        for ($a = 1, $aMax = count($asset_tags); $a <= $aMax; $a++) {
            if ($model && $model->require_serial === 1 && empty($serials[$a])) {
                $serial_errors["serials.$a"] = trans('admin/hardware/form.serial_required', ['number' => $a]);
            }

        }

        if (! empty($serial_errors)) {
            return redirect()->back()
                ->withInput()
                ->withErrors($serial_errors);
        }

        $asset = null;
        $companyId = Company::getIdForCurrentUser($request->input('company_id'));
        $successes = [];
        $failures = [];

        try {
            DB::beginTransaction();
            for ($a = 1, $aMax = count($asset_tags); $a <= $aMax; $a++) {
                $asset = new Asset;

                $asset->model()->associate($model);
                $asset->name = $request->input('name');

                // Check for a corresponding serial
                if (($serials) && (array_key_exists($a, $serials))) {
                    $asset->serial = $serials[$a];
                }

                if (($asset_tags) && (array_key_exists($a, $asset_tags))) {
                    $asset->asset_tag = $asset_tags[$a];
                }

                $asset->company_id = $companyId;
                $asset->model_id = $request->input('model_id');
                $asset->order_number = $request->input('order_number');
                $asset->notes = $request->input('notes');
                $asset->created_by = auth()->id();
                $asset->status_id = request('status_id');
                $asset->warranty_months = request('warranty_months', null);
                $asset->purchase_cost = request('purchase_cost');
                $asset->purchase_date = request('purchase_date', null);
                $asset->asset_eol_date = request('asset_eol_date', null);
                $asset->assigned_to = request('assigned_to', null);
                $asset->supplier_id = request('supplier_id', null);
                $asset->requestable = request('requestable', 0);
                $asset->rtd_location_id = request('rtd_location_id', null);
                $asset->byod = request('byod', 0);

                if (! empty($settings->audit_interval)) {
                    $asset->next_audit_date = Carbon::now()->addMonths((int) $settings->audit_interval)->toDateString();
                }

                // Set location_id to rtd_location_id ONLY if the asset isn't being checked out
                if (! request('assigned_user') && ! request('assigned_asset') && ! request('assigned_location')) {
                    $asset->location_id = $request->input('rtd_location_id', null);
                }

                if ($request->has('use_cloned_image')) {
                    $cloned_model_img = Asset::select('image')->find($request->input('clone_image_from_id'));
                    if ($cloned_model_img) {
                        $new_image_name = 'clone-'.date('U').'-'.$cloned_model_img->image;
                        $new_image = 'assets/'.$new_image_name;
                        Storage::disk('public')->copy('assets/'.$cloned_model_img->image, $new_image);
                        $asset->image = $new_image_name;
                    }

                } else {
                    $asset = $request->handleImages($asset);
                }

                // Update custom fields in the database.
                // Validation for these fields is handled through the AssetRequest form request

                if (($model) && ($model->fieldset)) {
                    foreach ($model->fieldset->fields as $field) {
                        if ($field->field_encrypted == '1') {
                            if (Gate::allows('assets.view.encrypted_custom_fields')) {
                                if (is_array($request->input($field->db_column))) {
                                    $asset->{$field->db_column} = Crypt::encrypt(implode(', ', $request->input($field->db_column)));
                                } else {
                                    $asset->{$field->db_column} = Crypt::encrypt($request->input($field->db_column));
                                }
                            }
                        } else {
                            if (is_array($request->input($field->db_column))) {
                                $asset->{$field->db_column} = implode(', ', $request->input($field->db_column));
                            } else {
                                $asset->{$field->db_column} = $request->input($field->db_column);
                            }
                        }
                    }
                }

                // Validate the asset before saving
                // Note - it can be tempting to instead want to call saveOrFail(), to automatically throw when an object
                // is invalid (and can't save). But this won't work, because Custom Fields _overrides_ the save() method
                // to inject the Custom Field Rules into the $rules property right before invoking the _real_ save.
                // so, instead, we have to catch failures on the 'else' clause and throw there.
                if ($asset->isValid() && $asset->save()) {
                    $target = null;
                    $location = null;

                    if ($userId = request('assigned_user')) {
                        $target = User::find($userId);

                        if (! $target) {
                            return redirect()->back()->withInput()->with('error', trans('admin/hardware/message.create.target_not_found.user'));
                        }
                        $location = $target->location_id;

                    } elseif ($assetId = request('assigned_asset')) {
                        $target = Asset::find($assetId);

                        if (! $target) {
                            return redirect()->back()->withInput()->with('error', trans('admin/hardware/message.create.target_not_found.asset'));
                        }
                        $location = $target->location_id;

                    } elseif ($locationId = request('assigned_location')) {
                        $target = Location::find($locationId);

                        if (! $target) {
                            return redirect()->back()->withInput()->with('error', trans('admin/hardware/message.create.target_not_found.location'));
                        }
                        $location = $target->id;
                    }

                    if (isset($target)) {
                        $asset->checkOut($target, auth()->user(), date('Y-m-d H:i:s'), $request->input('expected_checkin', null), 'Checked out on asset creation', $request->input('name'), $location);
                    }

                    $successes[] = "<a href='".route('hardware.show', $asset)."' style='color: white;'>".e($asset->asset_tag).'</a>';

                } else {
                    $asset->throwValidationException(); // we have to do this for the reason listed above - can't use saveOrFail()
                    $failures[] = implode(',', $asset->getErrors()->all()); // TODO - this can probably go away soon
                }
            }
        } catch (\Throwable $e) {
            \Log::debug('Caught exception in multi-create - rolling back: '.$e->getMessage());
            DB::rollBack();
            throw $e;
        }
        DB::commit();

        if ($request->input('redirect_option') === 'back') {
            session()->put(['redirect_option' => 'index']);
        } else {
            session()->put(['redirect_option' => $request->input('redirect_option')]);
        }

        session()->put(['checkout_to_type' => $request->input('checkout_to_type'),
            'other_redirect' => 'model']);

        if ($successes) {
            if ($failures) {
                // some succeeded, some failed
                return Helper::getRedirectOption($request, $asset->id, 'Assets') // FIXME - not tested
                    ->with('success-unescaped', trans_choice('admin/hardware/message.create.multi_success_linked', $successes, ['links' => implode(', ', $successes)]))
                    ->with('warning', trans_choice('admin/hardware/message.create.partial_failure', $failures, ['failures' => implode('; ', $failures)]));
            } else {
                if (count($successes) == 1) {
                    // the most common case, keeping it so we don't have to make every use of that translation string be trans_choice'ed
                    // and re-translated
                    return Helper::getRedirectOption($request, $asset->id, 'Assets')
                        ->with('success-unescaped', trans('admin/hardware/message.create.success_linked', ['link' => route('hardware.show', $asset), 'id', 'tag' => e($asset->asset_tag)]));
                } else {
                    // multi-success
                    return Helper::getRedirectOption($request, $asset->id, 'Assets')
                        ->with('success-unescaped', trans_choice('admin/hardware/message.create.multi_success_linked', $successes, ['links' => implode(', ', $successes)]));
                }
            }

        }

        return redirect()->back()->withInput()->withErrors($asset->getErrors());
    }

    /**
     * Returns a view that presents a form to edit an existing asset.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v1.0]
     *
     * @return View
     */
    public function edit(Asset $asset): View|RedirectResponse
    {
        $this->authorize($asset);
        if ($safeReferer = Helper::sameOriginUrl(url()->previous())) {
            session()->put('url.intended', $safeReferer);
        }

        return view('hardware/edit')
            ->with('item', $asset)
            ->with('statuslabel_list', Helper::statusLabelList())
            ->with('statuslabel_types', Helper::statusTypeList());
    }

    /**
     * Returns a view that presents information about an asset for detail view.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  int  $assetId
     *
     * @since [v1.0]
     *
     * @return View
     */
    public function show(Asset $asset): View|RedirectResponse
    {
        $this->authorize('view', $asset);
        $settings = Setting::getSettings();

        if (isset($asset)) {
            $audit_log = Actionlog::where('action_type', '=', 'audit')
                ->where('item_id', '=', $asset->id)
                ->where('item_type', '=', Asset::class)
                ->orderBy('created_at', 'DESC')->first();

            if ($asset->location) {
                $use_currency = $asset->location->currency;
            } else {
                if ($settings->default_currency != '') {
                    $use_currency = $settings->default_currency;
                } else {
                    $use_currency = trans('general.currency');
                }
            }

            $qr_code = (object) [
                'display' => $settings->qr_code == '1',
                'url' => route('qr_code/common', ['object_type' => 'hardware', 'id' => $asset->id]),
            ];

            $total_maintenance_cost = $asset->maintenances?->sum('cost');
            $total_asset_cost = ($asset->assignedAssets()?->AssetsForShow()) ? $asset->assignedAssets()?->AssetsForShow()?->sum('purchase_cost') : 0;
            $total_license_cost = ($asset->licenses) ? $asset->licenses->sum('purchase_cost') : 0;
            // accessories.purchase_cost no longer exists; getAccessoryCost()
            // walks lastOrderDefaults() per attached accessory so the total
            // reflects each item's last acquisition (with the parent's
            // default_purchase_cost as fallback).
            $total_accessory_cost = $asset->getAccessoryCost();
            $total_component_cost = ($asset->components) ? $asset->components->sum('calculated_purchase_cost') : 0;

            $total_cost_for_asset = $asset->purchase_cost + $total_maintenance_cost + $total_asset_cost + $total_license_cost + $total_accessory_cost + $total_component_cost;

            $audit_custom_field_columns = [];
            if ($asset->model && $asset->model->fieldset) {
                $audit_custom_field_columns = $asset->model->fieldset->fields
                    ->where('display_audit', '1')
                    ->map(fn ($field) => [
                        'field' => $field->db_column,
                        'searchable' => false,
                        'sortable' => false,
                        'switchable' => true,
                        'title' => e($field->name),
                        'visible' => true,
                    ])
                    ->values()
                    ->all();
            }

            return view('hardware/view', compact('asset', 'qr_code', 'settings'))
                ->with('total_maintenance_cost', $total_maintenance_cost)
                ->with('total_asset_cost', $total_asset_cost)
                ->with('total_license_cost', $total_license_cost)
                ->with('total_accessory_cost', $total_accessory_cost)
                ->with('total_component_cost', $total_component_cost)
                ->with('total_cost_for_asset', $total_cost_for_asset)
                ->with('use_currency', $use_currency)
                ->with('audit_log', $audit_log)
                ->with('audit_custom_field_columns', $audit_custom_field_columns);
        }

        return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
    }

    /**
     * Validate and process asset edit form.
     *
     * @param  int  $assetId
     *
     * @since [v1.0]
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     */
    public function update(ImageUploadRequest $request, Asset $asset): RedirectResponse
    {

        $this->authorize($asset);

        $asset->status_id = $request->input('status_id', null);
        $asset->warranty_months = $request->input('warranty_months', null);
        $asset->purchase_cost = $request->input('purchase_cost', null);
        $asset->purchase_date = $request->input('purchase_date', null);
        $asset->next_audit_date = $request->input('next_audit_date', null);
        if ($request->filled('purchase_date') && ! $request->filled('asset_eol_date') && ($asset->model?->eol > 0)) {
            $asset->purchase_date = $request->input('purchase_date', null);
            $asset->asset_eol_date = Carbon::parse($request->input('purchase_date'))->addMonths($asset->model->eol)->format('Y-m-d');
            $asset->eol_explicit = false;
        } elseif ($request->filled('asset_eol_date')) {
            $asset->asset_eol_date = $request->input('asset_eol_date', null);
            $months = (int) Carbon::parse($asset->asset_eol_date)->diffInMonths($asset->purchase_date, true);
            if ($asset->model->eol) {
                if ($months != $asset->model->eol > 0) {
                    $asset->eol_explicit = true;
                } else {
                    $asset->eol_explicit = false;
                }
            } else {
                $asset->eol_explicit = true;
            }
        } elseif (! $request->filled('asset_eol_date') && (($asset->model?->eol) == 0)) {
            $asset->asset_eol_date = null;
            $asset->eol_explicit = false;
        }
        $asset->supplier_id = $request->input('supplier_id', null);
        $asset->expected_checkin = $request->input('expected_checkin', null);
        $asset->requestable = $request->input('requestable', 0);
        $asset->rtd_location_id = $request->input('rtd_location_id', null);
        // Current location is editable from the asset edit form as of
        // the location-dropdown addition. Only overwrite when the key
        // is actually present in the request — a client that omits
        // location_id entirely (a partial update API caller, an older
        // form) still leaves the existing value intact. Present-but-
        // blank clears via the mutator (see setLocationIdAttribute).
        if ($request->has('location_id')) {
            $asset->location_id = $request->input('location_id');
        }
        $asset->byod = $request->input('byod', 0);

        $status = Statuslabel::find($request->input('status_id'));

        // This is an archived or undeployable - we should check the asset back in.
        // Pending is allowed here
        if (($status) && (($status->getStatuslabelType() != 'pending') && ($status->getStatuslabelType() != 'deployable')) && ($target = $asset->assignedTo)) {
            $originalValues = $asset->getRawOriginal();
            $asset->assigned_to = null;
            $asset->assigned_type = null;
            $asset->accepted = null;
            $asset->last_checkin = now();
            event(new CheckoutableCheckedIn($asset, $target, auth()->user(), 'Checkin on asset update with '.$status->getStatuslabelType().' status', date('Y-m-d H:i:s'), $originalValues));
        }

        if ($request->filled('image_delete')) {
            try {
                unlink(public_path().'/uploads/assets/'.basename($asset->image));
                $asset->image = '';
            } catch (\Exception $e) {
                Log::info($e);
            }
        }

        // Update the asset data

        $serial = $request->input('serials');
        $asset->serial = $request->input('serials');

        if (is_array($request->input('serials'))) {
            $asset->serial = $serial[1];
        }

        $asset->name = $request->input('name');
        $asset->company_id = Company::getIdForCurrentUser($request->input('company_id'));
        $asset->model_id = $request->input('model_id');
        $asset->order_number = $request->input('order_number');

        $asset_tags = $request->input('asset_tags');
        $asset->asset_tag = $request->input('asset_tags');

        if (is_array($request->input('asset_tags'))) {
            $asset->asset_tag = $asset_tags[1];
        }

        $asset->notes = $request->input('notes');

        $asset = $request->handleImages($asset);

        // Update custom fields in the database.
        // FIXME: No idea why this is returning a Builder error on db_column_name.
        // Need to investigate and fix. Using static method for now.
        $model = AssetModel::find($request->input('model_id'));
        if (($model) && ($model->fieldset)) {
            foreach ($model->fieldset->fields as $field) {
                if ($field->element == 'checkbox' && ! $request->has($field->db_column)) {
                    $asset->{$field->db_column} = null;
                }
                if ($request->has($field->db_column)) {
                    if ($field->field_encrypted == '1') {
                        if (Gate::allows('assets.view.encrypted_custom_fields')) {
                            if (is_array($request->input($field->db_column))) {
                                $asset->{$field->db_column} = Crypt::encrypt(implode(', ', $request->input($field->db_column)));
                            } else {
                                $asset->{$field->db_column} = Crypt::encrypt($request->input($field->db_column));
                            }
                        }
                    } else {
                        if (is_array($request->input($field->db_column))) {
                            $asset->{$field->db_column} = implode(', ', $request->input($field->db_column));
                        } else {
                            $asset->{$field->db_column} = $request->input($field->db_column);
                        }
                    }
                }
            }
        }
        session()->put([
            'redirect_option' => $request->input('redirect_option'),
            'checkout_to_type' => $request->input('checkout_to_type'),
            'other_redirect' => $request->input('redirect_option') === 'other_redirect' ? 'model' : null,
        ]);

        // Validate required serial based on model setting
        if ($model && $model->require_serial === 1 && empty($serial[1])) {
            return Helper::getRedirectOption($request, $asset->id, 'Assets')
                ->with('warning', trans('admin/hardware/form.serial_required_post_model_update', [
                    'asset_model' => $model->name,
                ]));
        }
        if ($asset->save()) {
            return Helper::getRedirectOption($request, $asset->id, 'Assets')
                ->with('success', trans('admin/hardware/message.update.success'));
        }

        return redirect()->back()->withInput()->withErrors($asset->getErrors());
    }

    /**
     * Delete a given asset (mark as deleted).
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  int  $assetId
     *
     * @since [v1.0]
     */
    public function destroy(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('delete', $asset);
        if ($asset->assignedTo) {

            $target = $asset->assignedTo;
            $checkin_at = date('Y-m-d H:i:s');
            $originalValues = $asset->getRawOriginal();
            event(new CheckoutableCheckedIn($asset, $target, auth()->user(), 'Checkin on delete', $checkin_at, $originalValues));
            DB::table('assets')
                ->where('id', $asset->id)
                ->update(['assigned_to' => null, 'assigned_type' => null]);
        }

        // Note: the image file is deliberately preserved across this
        // soft-delete. Snipe-IT's `snipeit:purge` command permanently
        // removes it later when the row is force-deleted. Keeping the
        // file here means a restored soft-deleted row still has its
        // image.
        $asset->delete();

        return redirect()->route('hardware.index')->with('success', trans('admin/hardware/message.delete.success'));
    }

    /**
     * Searches the assets table by serial, and redirects if it finds one
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v3.0]
     */
    public function getAssetBySerial(Request $request, $serial = null): RedirectResponse
    {
        $serial = $serial ?: $request->input('serial');
        $topsearch = ($request->input('topsearch') == 'true');

        if (! $asset = Asset::where('serial', '=', $serial)->first()) {
            return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
        }
        $this->authorize('view', $asset);

        return redirect()->route('hardware.show', $asset->id)->with('topsearch', $topsearch);
    }

    /**
     * Searches the assets table by asset tag, and redirects if it finds one
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v3.0]
     */
    public function getAssetByTag(Request $request, $tag = null): RedirectResponse
    {
        $tag = $tag ? $tag : $request->input('assetTag');
        $topsearch = ($request->input('topsearch') == 'true');

        // Search for an exact and unique asset tag match
        $assets = Asset::where('asset_tag', '=', $tag);

        // If not a unique result, redirect to the index view
        if ($assets->count() != 1) {
            return redirect()->route('hardware.index')
                ->with('search', $tag)
                ->with('warning', trans('admin/hardware/message.does_not_exist_var', ['asset_tag' => $tag]));
        }
        $asset = $assets->first();
        $this->authorize('view', $asset);

        return redirect()->route('hardware.show', $asset->id)->with('topsearch', $topsearch);
    }

    /**
     * Return a QR code for the asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  int  $assetId
     *
     * @since [v1.0]
     */
    public function getQrCode(Asset $asset): Response|BinaryFileResponse|string|bool
    {
        $settings = Setting::getSettings();

        if ($settings->label2_2d_type !== 'none') {

            if ($asset) {
                $size = Helper::barcodeDimensions($settings->label2_2d_type);
                $qr_file = public_path().'/uploads/barcodes/qr-'.str_slug($asset->asset_tag).'-'.str_slug($asset->id).'.png';

                if (isset($asset->id, $asset->asset_tag)) {
                    if (file_exists($qr_file)) {
                        $header = ['Content-type' => 'image/png'];

                        return response()->file($qr_file, $header);
                    } else {
                        $barcode = new Barcode;
                        $barcode_obj = $barcode->getBarcodeObj($settings->label2_2d_type, route('hardware.show', $asset->id), $size['height'], $size['width'], 'black', [-2, -2, -2, -2]);
                        file_put_contents($qr_file, $barcode_obj->getPngData());

                        return response($barcode_obj->getPngData())->header('Content-type', 'image/png');
                    }
                }
            }

            return 'That asset is invalid';
        }

        return false;
    }

    /**
     * Return a 2D barcode for the asset
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  int  $assetId
     *
     * @since [v1.0]
     *
     * @return Response
     */
    public function getBarCode($assetId = null)
    {
        $settings = Setting::getSettings();
        if ($asset = Asset::withTrashed()->find($assetId)) {
            // Gate on the asset view policy so this endpoint enforces
            // the same object-level authorization as its sibling detail
            // / label / QR-code routes. Previously any authenticated
            // user could pull the barcode PNG for any asset regardless
            // of company scope, letting them enumerate protected asset
            // tags.
            $this->authorize('view', $asset);

            $barcode_file = public_path().'/uploads/barcodes/'.str_slug($settings->label2_1d_type).'-'.str_slug($asset->asset_tag).'.png';

            if (isset($asset->id, $asset->asset_tag)) {
                if (file_exists($barcode_file)) {
                    $header = ['Content-type' => 'image/png'];

                    return response()->file($barcode_file, $header);
                } else {
                    // Calculate barcode width in pixel based on label width (inch)
                    $barcode_width = ($settings->labels_width - $settings->labels_display_sgutter) * 200.000000000001;

                    $barcode = new Barcode;
                    try {
                        $barcode_obj = $barcode->getBarcodeObj($settings->label2_1d_type, $asset->asset_tag, ($barcode_width < 300 ? $barcode_width : 300), 50);
                        file_put_contents($barcode_file, $barcode_obj->getPngData());

                        return response($barcode_obj->getPngData())->header('Content-type', 'image/png');
                    } catch (\Exception|TypeError $e) {
                        Log::debug('The barcode format is invalid.');

                        return response(file_get_contents(public_path('uploads/barcodes/invalid_barcode.gif')))->header('Content-type', 'image/gif');
                    }
                }
            }
        }

        return null;
    }

    /**
     * Return a label for an individual asset.
     *
     * @author [L. Swartzendruber] [<logan.swartzendruber@gmail.com>
     *
     * @param  int  $assetId
     * @return View
     */
    public function getLabel($assetId = null)
    {
        if (isset($assetId)) {
            $asset = Asset::find($assetId);
            $this->authorize('view', $asset);

            return (new Label)
                ->with('assets', collect([$asset]))
                ->with('settings', Setting::getSettings())
                ->with('template', request()->input('template'))
                ->with('offset', request()->input('offset'))
                ->with('bulkedit', false)
                ->with('count', 0);
        }
    }

    /**
     * Returns a view that presents a form to clone an asset.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  int  $assetId
     *
     * @since [v1.0]
     *
     * @return View
     */
    public function getClone(Asset $asset)
    {
        $this->authorize('create', Asset::class);
        $cloned = clone $asset;
        $cloned_model = $asset;
        $cloned->id = null;
        $cloned->asset_tag = '';
        $cloned->serial = '';
        $cloned->assigned_to = '';
        $cloned->deleted_at = '';

        return view('hardware/edit')
            ->with('statuslabel_list', Helper::statusLabelList())
            ->with('statuslabel_types', Helper::statusTypeList())
            ->with('cloned_model', $cloned_model)
            ->with('item', $cloned);
    }

    public function sortByName(array $recordA, array $recordB): int
    {
        return strcmp($recordB['Full Name'], $recordA['Full Name']);
    }

    /**
     * Restore a deleted asset.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  int  $assetId
     *
     * @since [v1.0]
     *
     * @return View
     */
    public function getRestore($assetId = null)
    {
        if ($asset = Asset::withTrashed()->find($assetId)) {
            $this->authorize('delete', $asset);

            if ($asset->deleted_at == '') {
                return redirect()->back()->with('error', trans('general.not_deleted', ['item_type' => trans('general.asset')]));
            }

            if ($asset->restore()) {
                // Redirect them to the deleted page if there are more, otherwise the section index
                $deleted_assets = Asset::onlyTrashed()->count();
                if ($deleted_assets > 0) {
                    return redirect()->back()->with('success', trans('admin/hardware/message.restore.success'));
                }

                return redirect()->route('hardware.index')->with('success', trans('admin/hardware/message.restore.success'));
            }

            // Check validation to make sure we're not restoring an asset with the same asset tag (or unique attribute) as an existing asset
            return redirect()->back()->with('error', trans('general.could_not_restore', ['item_type' => trans('general.asset'), 'error' => $asset->getErrors()->first()]));
        }

        return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
    }

    public function quickScan()
    {
        $this->authorize('audit', Asset::class);
        $settings = Setting::getSettings();
        $dt = Carbon::now()->addMonths($settings->audit_interval)->toDateString();

        return view('hardware/quickscan')->with('next_audit_date', $dt);
    }

    public function quickScanCheckin()
    {
        $this->authorize('checkin', Asset::class);

        return view('hardware/quickscan-checkin')->with('statusLabel_list', Helper::statusLabelList());
    }

    public function dueForAudit()
    {
        $this->authorize('audit', Asset::class);

        return view('hardware/audit-due');
    }

    public function dueForCheckin()
    {
        $this->authorize('checkin', Asset::class);

        return view('hardware/checkin-due');
    }

    public function audit(Asset $asset): View|RedirectResponse
    {
        $this->authorize('audit', Asset::class);
        // Per-instance authorize so SnipePermissionsPolicy::before()
        // runs Company::isCurrentUserHasAccess($asset) at the policy
        // layer instead of leaving FMCS scoping solely to the route-
        // model-binding + CompanyableScope combo.
        $this->authorize('audit', $asset);
        $settings = Setting::getSettings();

        // Invoke the validation to see if the audit will complete successfully
        $asset->setRules($asset->getRules() + $asset->customFieldValidationRules());

        if ($asset->isInvalid()) {
            return redirect()->route('hardware.edit', $asset)->withErrors($asset->getErrors());
        }

        $dt = Carbon::now()->addMonths((int) $settings->audit_interval)->toDateString();

        return view('hardware/audit')->with('asset', $asset)->with('item', $asset)->with('next_audit_date', $dt)->with('locations_list');
    }

    public function auditStore(UploadFileRequest $request, Asset $asset)
    {

        $this->authorize('audit', Asset::class);
        // Per-instance authorize: without this, FMCS enforcement on
        // an audit write depends entirely on route-model binding
        // firing CompanyableScope. Explicit instance authorize means
        // the policy layer independently rejects cross-company writes.
        $this->authorize('audit', $asset);

        session()->put('redirect_option', $request->input('redirect_option'));
        session()->put('other_redirect', 'audit');

        $originalValues = $asset->getRawOriginal();

        $asset->next_audit_date = $request->input('next_audit_date');
        $asset->last_audit_date = date('Y-m-d H:i:s');

        // Check to see if they checked the box to update the physical location,
        // not just note it in the audit notes
        if ($request->input('update_location') == '1') {
            $asset->location_id = $request->input('location_id');
        }

        // Update custom fields in the database
        if (($asset->model) && ($asset->model->fieldset)) {
            foreach ($asset->model->fieldset->fields as $field) {
                if (($field->display_audit == '1') && ($request->has($field->db_column))) {
                    if ($field->field_encrypted == '1') {
                        if (Gate::allows('assets.view.encrypted_custom_fields')) {
                            if (is_array($request->input($field->db_column))) {
                                $asset->{$field->db_column} = Crypt::encrypt(implode(', ', $request->input($field->db_column)));
                            } else {
                                $asset->{$field->db_column} = Crypt::encrypt($request->input($field->db_column));
                            }
                        }
                    } else {
                        if (is_array($request->input($field->db_column))) {
                            $asset->{$field->db_column} = implode(', ', $request->input($field->db_column));
                        } else {
                            $asset->{$field->db_column} = $request->input($field->db_column);
                        }
                    }
                }
            }
        }

        // Invoke the validation to see if the audit will complete successfully
        $asset->setRules($asset->getRules() + $asset->customFieldValidationRules());

        // Validate the rest of the data before we turn off the event dispatcher
        if ($asset->isInvalid()) {
            return redirect()->back()->withInput()->withErrors($asset->getErrors());
        }

        /**
         * Even though we do a save() further down, we don't want to log this as a "normal" asset update,
         * which would trigger the Asset Observer and would log an asset *update* log entry (because the
         * de-normed fields like next_audit_date on the asset itself will change on save()) *in addition* to
         * the audit log entry we're creating through this controller.
         *
         * To prevent this double-logging (one for update and one for audit), we skip the observer and bypass
         * that de-normed update log entry by using unsetEventDispatcher(), BUT invoking unsetEventDispatcher()
         * will bypass normal model-level validation that's usually handled at the observer )
         *
         * We handle validation on the save() by checking if the asset is valid via the ->isValid() method,
         * which manually invokes Watson Validating to make sure the asset's model is valid.
         *
         * @see AssetObserver::updating()
         * @see Asset::save()
         */
        $asset->unsetEventDispatcher();

        /**
         * Invoke Watson Validating to check the asset itself and check to make sure it saved correctly.
         * We have to invoke this manually because of the unsetEventDispatcher() above.)
         */
        if ($asset->isValid() && $asset->save()) {

            $file_name = null;
            // Create the image (if one was chosen.)
            if ($request->hasFile('image')) {
                $file_name = $request->handleFile('private_uploads/audits/', 'audit-'.$asset->id, $request->file('image'));
            }

            $asset->logAudit($request->input('note'), $request->input('location_id'), $file_name, $originalValues);

            return Helper::getRedirectOption($request, $asset->id, 'Assets')->with('success', trans('admin/hardware/message.audit.success'));
        }

        return redirect()->back()->withInput()->withErrors($asset->getErrors());
    }

    public function getRequestedIndex($user_id = null)
    {
        $this->authorize('index', Asset::class);

        $requestedItems = CheckoutRequest::with('user', 'requestedItem')->whereNull('canceled_at');

        if ($user_id) {
            $requestedItems->where('user_id', $user_id);
        }

        $requestedItems = $requestedItems->orderBy('created_at', 'desc')->get();

        if (Company::isFullMultipleCompanySupportEnabled() && ! auth()->user()->isSuperUser()) {
            $requestedItems = $requestedItems->filter(
                fn (CheckoutRequest $request) => $request->requestable
                    && Company::isCurrentUserHasAccess($request->requestable)
            )->values();
        }

        return view('hardware/requested', compact('requestedItems'));
    }
}
