<?php

namespace App\Http\Controllers\Api;

use App\Actions\Categories\DestroyCategoryAction;
use App\Actions\Categories\SaveCategoryHierarchyAction;
use App\Exceptions\CategoryStillHasChildCategories;
use App\Exceptions\ItemStillHasChildren;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Requests\ImageUploadRequest;
use App\Http\Transformers\CategoriesTransformer;
use App\Http\Transformers\SelectlistTransformer;
use App\Models\Category;
use App\Services\AssetCategoryAccess;
use App\Services\AssetCategoryPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CategoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v4.0]
     *
     * @return Response
     */
    public function index(FilterRequest $request): array
    {
        $this->authorize('view', Category::class);
        $allowed_columns = [
            'accessories_count',
            'assets_count',
            'category_type',
            'checkin_email',
            'components_count',
            'consumables_count',
            'created_at',
            'eula_text',
            'id',
            'image',
            'licenses_count',
            'name',
            'notes',
            'require_acceptance',
            'tag_color',
            'updated_at',
            'use_default_eula',
        ];

        $categories = Category::select([
            'category_type',
            'checkin_email',
            'created_at',
            'created_by',
            'eula_text',
            'id',
            'image',
            'is_assignable',
            'name',
            'notes',
            'parent_id',
            'require_acceptance',
            'sort_order',
            'tag_color',
            'updated_at',
            'use_default_eula',
        ])
            ->with('adminuser', 'parent')
            ->withCount('accessories as accessories_count', 'consumables as consumables_count', 'components as components_count', 'licenses as licenses_count', 'models as models_count', 'children as children_count');

        // This invokes the Searchable model trait scopeTextSearch and will handle input by search or by advanced search filter
        if ($request->filled('filter') || $request->filled('search')) {
            $categories->TextSearch($request->input('filter') ? $request->input('filter') : $request->input('search'));
        }

        /*
         * This checks to see if we should override the Admin Setting to show archived assets in list.
         * We don't currently use it within the Snipe-IT GUI, but will be useful for API integrations where they
         * may actually need to fetch assets that are archived.
         *
         * @see \App\Models\Category::showableAssets()
         */
        if ($request->input('archived') == 'true') {
            $categories = $categories->withCount('assets as assets_count');
        } else {
            $categories = $categories->withCount('showableAssets as assets_count');
        }

        if ($request->filled('name')) {
            $categories->where('name', '=', $request->input('name'));
        }

        if ($request->filled('category_type')) {
            $categories->where('category_type', '=', $request->input('category_type'));
        }

        if ($request->filled('use_default_eula')) {
            $categories->where('use_default_eula', '=', $request->input('use_default_eula'));
        }

        if ($request->filled('require_acceptance')) {
            $categories->where('require_acceptance', '=', $request->input('require_acceptance'));
        }

        if ($request->filled('checkin_email')) {
            $categories->where('checkin_email', '=', $request->input('checkin_email'));
        }

        if ($request->filled('created_by')) {
            $categories->where('created_by', '=', $request->input('created_by'));
        }

        if ($request->filled('created_at')) {
            $categories->where('created_at', '=', $request->input('created_at'));
        }

        if ($request->filled('updated_at')) {
            $categories->where('updated_at', '=', $request->input('updated_at'));
        }

        // Make sure the offset and limit are actually integers and do not exceed system limits
        $total = $categories->count();
        $offset = ($request->input('offset') > $total) ? $total : app('api_offset_value');
        $limit = app('api_limit_value');
        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort_override = $request->input('sort');
        $column_sort = in_array($sort_override, $allowed_columns) ? $sort_override : 'assets_count';

        switch ($sort_override) {
            case 'created_by':
                $categories = $categories->OrderByCreatedBy($order);
                break;
                // This is annoying, since it's not a real relationship, which is what we usually use these switches for, but
                // we call the field has_eula, not eula_text, so there won't be a matching field
            case 'has_eula':
                $categories = $categories->orderBy('eula_text', $order);
                break;
            default:
                $categories = $categories->orderBy($column_sort, $order);
                break;
        }

        $categories = $categories->skip($offset)->take($limit)->get();

        return (new CategoriesTransformer)->transformCategories($categories, $total);

    }

    /**
     * Store a newly created resource in storage.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v4.0]
     *
     * @return Response
     */
    public function store(ImageUploadRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        // Hierarchy fields from anyone but a Super User are a 403, never ignored.
        $hierarchyInput = SaveCategoryHierarchyAction::inputFrom($request);
        SaveCategoryHierarchyAction::authorizeInput($hierarchyInput);

        $category = new Category;
        $category->fill($request->all());
        $category->created_by = auth()->id();
        $category->category_type = strtolower($request->input('category_type'));
        $category = $request->handleImages($category);

        try {
            SaveCategoryHierarchyAction::run($category, $hierarchyInput);
        } catch (ValidationException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->errors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $category, trans('admin/categories/message.create.success')));

    }

    /**
     * Display the specified resource.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v4.0]
     *
     * @param  int  $id
     */
    public function show($id): array
    {
        $this->authorize('view', Category::class);
        $category = Category::with('parent')->withCount('assets as assets_count', 'accessories as accessories_count', 'consumables as consumables_count', 'components as components_count', 'licenses as licenses_count', 'children as children_count')->findOrFail($id);

        return (new CategoriesTransformer)->transformCategory($category);

    }

    /**
     * Update the specified resource in storage.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v4.0]
     *
     * @param  int  $id
     * @return Response
     */
    public function update(ImageUploadRequest $request, $id): JsonResponse
    {
        $this->authorize('update', Category::class);
        $category = Category::findOrFail($id);

        // Hierarchy fields from anyone but a Super User are a 403, never ignored.
        $hierarchyInput = SaveCategoryHierarchyAction::inputFrom($request);
        SaveCategoryHierarchyAction::authorizeInput($hierarchyInput);

        // Don't allow the user to change the category_type once it's been created
        if (($request->filled('category_type')) && ($category->category_type != $request->input('category_type'))) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, ['category_type' => trans('admin/categories/message.update.cannot_change_category_type')], 422)
            );
        }
        $category->fill($request->all());
        $category = $request->handleImages($category);

        try {
            SaveCategoryHierarchyAction::run($category, $hierarchyInput);
        } catch (ValidationException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->errors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $category, trans('admin/categories/message.update.success')));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v4.0]
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', Category::class);
        try {
            DestroyCategoryAction::run(category: $category);
        } catch (CategoryStillHasChildCategories $e) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('admin/categories/message.delete.has_child_categories'))
            );
        } catch (ItemStillHasChildren $e) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('general.bulk_delete_associations.general_assoc_warning', ['asset_type' => $category->category_type]))
            );
        } catch (\Exception $e) {
            report($e);

            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('general.something_went_wrong'))
            );
        }

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/categories/message.delete.success')));
    }

    /**
     * Gets a paginated collection for the select2 menus
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v4.0.16]
     * @see SelectlistTransformer
     */
    public function selectlist(Request $request, $category_type = 'asset'): array
    {
        $this->authorize('view.selectlists');
        $categories = Category::select([
            'id',
            'name',
            'image',
        ]);

        if ($request->filled('search')) {
            $categories = $categories->where('name', 'LIKE', '%'.$request->input('search').'%');
        }

        $categories = $categories->where('category_type', $category_type);

        // ERS: asset category pickers (Asset Model create/edit/clone, bulk
        // edit, the new-model modal) must only offer final/assignable
        // categories. Navigation groups are never selectable here. Other
        // category types are unchanged.
        if ($category_type === 'asset') {
            $categories = $categories->where('is_assignable', true);

            // ERS Phase 5B1: category-restricted users only see asset
            // categories they may view.
            $access = app(AssetCategoryPermissionService::class)->forCurrentUser();
            if ($access !== null) {
                $categories = $categories->whereIn('id', $access->categoryIds(AssetCategoryAccess::VIEW));
            }
        }

        $categories = $categories->orderBy('name', 'ASC')->paginate(50);

        // Loop through and set some custom properties for the transformer to use.
        // This lets us have more flexibility in special cases like assets, where
        // they may not have a ->name value but we want to display something anyway
        foreach ($categories as $category) {
            $category->use_image = ($category->image) ? Storage::disk('public')->url('categories/'.$category->image, $category->image) : null;
        }

        return (new SelectlistTransformer)->transformSelectlist($categories);
    }
}
