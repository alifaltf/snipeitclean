<?php

namespace App\Http\Controllers;

use App\Actions\Categories\DestroyCategoryAction;
use App\Exceptions\ItemStillHasChildren;
use App\Helpers\Helper;
use App\Http\Requests\ImageUploadRequest;
use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;

/**
 * This class controls all actions related to Categories for
 * the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 *
 * @author [A. Gianotto] [<snipe@snipe.net>]
 */
class CategoriesController extends Controller
{
    /**
     * Returns a view that invokes the ajax tables which actually contains
     * the content for the categories listing, which is generated in getDatatable.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see CategoriesController::getDatatable() method that generates the JSON response
     * @since [v1.0]
     */
    public function index(): View
    {
        // Show the page
        $this->authorize('view', Category::class);

        return view('categories/index');
    }

    /**
     * Returns a form view to create a new category.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see CategoriesController::store() method that stores the data
     * @since [v1.0]
     */
    public function create(): View
    {
        // Show the page
        $this->authorize('create', Category::class);

        return view('categories/edit')->with('item', new Category)
            ->with('category_types', Helper::categoryTypeList())
            ->with('ers_asset_group_options', $this->ersAssetGroupOptions());
    }

    /**
     * Validates and stores the new category data.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see CategoriesController::create() method that makes the form.
     * @since [v1.0]
     */
    public function store(ImageUploadRequest $request): RedirectResponse
    {
        $this->authorize('create', Category::class);
        $category = new Category;
        $category->name = $request->input('name');
        $category->category_type = $request->input('category_type');
        $category->eula_text = $request->input('eula_text');
        $category->use_default_eula = $request->input('use_default_eula', '0');
        $category->require_acceptance = $request->input('require_acceptance', '0');
        $category->alert_on_response = $request->input('alert_on_response', '0');
        $category->checkin_email = $request->input('checkin_email', '0');
        $category->tag_color = $request->input('tag_color');
        $category->notes = $request->input('notes');
        $category->created_by = auth()->id();
        $this->validateErsAssetGroupInput($request, $category->category_type);
        $category->ers_asset_group = $this->resolveErsAssetGroupInput($request, $category->category_type);

        $category = $request->handleImages($category);
        if ($category->save()) {
            return redirect()->route('categories.index')->with('success', trans('admin/categories/message.create.success'));
        }

        return redirect()->back()->withInput()->withErrors($category->getErrors());
    }

    /**
     * Returns a view that makes a form to update a category.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see CategoriesController::postEdit() method saves the data
     *
     * @param  int  $categoryId
     *
     * @since [v1.0]
     */
    public function edit(Category $category): RedirectResponse|View
    {
        $this->authorize('update', Category::class);

        return view('categories/edit')->with('item', $category)
            ->with('category_types', Helper::categoryTypeList())
            ->with('ers_asset_group_options', $this->ersAssetGroupOptions());
    }

    /**
     * Validates and stores the updated category data.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see CategoriesController::getEdit() method that makes the form.
     *
     * @param  int  $categoryId
     *
     * @since [v1.0]
     */
    public function update(ImageUploadRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', Category::class);
        $category->name = $request->input('name');

        // Don't allow the user to change the category_type once it's been created
        if (($request->filled('category_type') && ($category->itemCount() > 0))) {
            $request->validate(['category_type' => 'in:'.$category->category_type]);
        }

        $category->category_type = $request->input('category_type', $category->category_type);

        $category->fill($request->all());

        $category->eula_text = $request->input('eula_text');
        $category->use_default_eula = $request->input('use_default_eula', '0');
        $category->require_acceptance = $request->input('require_acceptance', '0');
        $category->alert_on_response = $request->input('alert_on_response', '0');
        $category->checkin_email = $request->input('checkin_email', '0');
        $category->tag_color = $request->input('tag_color');
        $category->notes = $request->input('notes');
        // Always assigned explicitly (never via the fill() above —
        // ers_asset_group is deliberately not fillable, see the Category
        // model) so a raw request value can never bypass the
        // category_type gate below.
        $this->validateErsAssetGroupInput($request, $category->category_type);
        $category->ers_asset_group = $this->resolveErsAssetGroupInput($request, $category->category_type);

        $category = $request->handleImages($category);

        if ($category->save()) {
            // Redirect to the new category page
            return redirect()->route('categories.index')->with('success', trans('admin/categories/message.update.success'));
        }

        // The given data did not pass validation
        return redirect()->back()->withInput()->withErrors($category->getErrors());
    }

    /**
     * Validates and marks a category as deleted.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @since [v1.0]
     *
     * @param  int  $categoryId
     */
    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', Category::class);
        try {
            DestroyCategoryAction::run($category);
        } catch (ItemStillHasChildren $e) {
            return redirect()->route('categories.index')->with('error', trans('general.bulk_delete_associations.general_assoc_warning', ['item' => trans('general.category')]));
        } catch (\Exception $e) {
            report($e);

            return redirect()->route('categories.index')->with('error', trans('admin/categories/message.delete.error'));
        }

        return redirect()->route('categories.index')->with('success', trans('admin/categories/message.delete.success'));
    }

    /**
     * Returns a view that invokes the ajax tables which actually contains
     * the content for the categories detail view, which is generated in getDataView.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @see CategoriesController::getDataView() method that generates the JSON response
     *
     * @param  $id
     *
     * @since [v1.8]
     */
    public function show(Category $category): View|RedirectResponse
    {
        $this->authorize('view', Category::class);

        if ($category->category_type == 'asset') {
            $category_type = 'hardware';
            $category_type_route = 'assets';
        } elseif ($category->category_type == 'accessory') {
            $category_type = 'accessories';
            $category_type_route = 'accessories';
        } else {
            $category_type = $category->category_type;
            $category_type_route = $category->category_type.'s';
        }

        return view('categories/view', compact('category'))
            ->with('category_type', $category_type)
            ->with('category_type_route', $category_type_route);
    }

    /**
     * Validates the raw 'ers_asset_group' request input against the
     * category's EFFECTIVE category_type (already resolved by the caller
     * — the existing "locked after items exist" category_type rule, not
     * necessarily the raw request value). Throws Laravel's normal
     * ValidationException (redirect back with field-scoped errors +
     * flashed input, exactly like the category_type lock-check a few
     * lines above this call in update()) when an asset category is
     * submitted without picking Hardware or Software, or with anything
     * other than 'hardware'/'software'.
     *
     * This "required for asset categories" rule is deliberately enforced
     * HERE — one request-validation call in the human-facing create/
     * update controller actions — rather than as a required_if on
     * App\Models\Category's own $rules. Category::$rules still always
     * enforces the narrower "if ers_asset_group is set at all, it must
     * be 'hardware' or 'software'" invariant for every write path
     * (imports, the API, artisan/tinker, seeders/factories). But making
     * it *required* for every asset-category save at the model level
     * would also block those internal, non-form writers — including
     * every pre-existing category_type = asset category left with
     * ers_asset_group = null by the migration, the moment anything
     * re-saves them — which is not what this feature asks for. Gating it
     * here means only a human actually filling out this form is asked to
     * pick a group.
     */
    private function validateErsAssetGroupInput(ImageUploadRequest $request, ?string $categoryType): void
    {
        Validator::make(
            [
                'category_type' => $categoryType,
                'ers_asset_group' => $request->input('ers_asset_group'),
            ],
            [
                'ers_asset_group' => ['nullable', 'in:hardware,software', 'required_if:category_type,asset'],
            ]
        )->validate();
    }

    /**
     * Resolves the raw 'ers_asset_group' request input down to a value
     * that's safe to assign to the model, given the category's EFFECTIVE
     * category_type. Called only after validateErsAssetGroupInput() above
     * has already confirmed the raw input is acceptable for that type, so
     * this is purely a coercion step, not a second validation pass.
     *
     * - Non-'asset' category types ALWAYS get null here, regardless of
     *   what was submitted — satisfies "License/Accessory/Consumable/
     *   Component categories must be saved with ers_asset_group = null"
     *   even if a client sends a stray value for one of those (validation
     *   above already allows a non-asset category to submit an
     *   ers_asset_group of null, '', or a valid group value — 'nullable'
     *   accepts the first two, and 'in:hardware,software' alone, without
     *   required_if, permits a stray valid-looking value through
     *   validation; this line is what actually discards it).
     * - For 'asset' categories, only the exact strings 'hardware' or
     *   'software' are accepted; anything else (missing, empty, an
     *   unexpected string, or array-shaped input like
     *   ?ers_asset_group[]=hardware) becomes null here — though for
     *   'asset' categories, validateErsAssetGroupInput() above will
     *   already have rejected the request before this method is ever
     *   reached in that case, via required_if.
     *
     * This is the ONLY place ers_asset_group is ever assigned from
     * request input — the field is deliberately excluded from $fillable
     * on the model so it can never arrive via mass assignment instead.
     */
    private function resolveErsAssetGroupInput(ImageUploadRequest $request, ?string $categoryType): ?string
    {
        if ($categoryType !== 'asset') {
            return null;
        }

        $raw = $request->input('ers_asset_group');

        return (is_string($raw) && in_array($raw, ['hardware', 'software'], true)) ? $raw : null;
    }

    /**
     * Options for the category form's "Asset Group" <select>, sourced
     * from config('ers_assets') so the form's group labels stay in sync
     * with the sidebar's — never a second, independently hard-coded list.
     *
     * @return array<string, string>
     */
    private function ersAssetGroupOptions(): array
    {
        $options = ['' => 'Select Asset Group'];

        foreach ((array) config('ers_assets', []) as $key => $group) {
            if (is_string($key) && $key !== '' && is_array($group)) {
                $options[$key] = is_string($group['label'] ?? null) ? $group['label'] : ucfirst($key);
            }
        }

        return $options;
    }
}
