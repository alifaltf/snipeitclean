<?php

namespace App\Http\Controllers;

use App\Actions\Groups\SaveGroupAssetCategoryPermissionsAction;
use App\Actions\Permissions\NormalizePermissionsPayloadAction;
use App\Helpers\Helper;
use App\Models\Group;
use App\Models\User;
use App\Services\AssetCategoryTree;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * This controller handles all actions related to User Groups for
 * the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 */
class GroupsController extends Controller
{
    /**
     * Returns a view that invokes the ajax tables which actually contains
     * the content for the user group listing, which is generated in getDatatable.
     *
     * @author [A. Gianotto] [<snipe@snipe.net]
     *
     * @see GroupsController::getDatatable() method that generates the JSON response
     * @since [v1.0]
     */
    public function index(): View
    {
        return view('groups/index');
    }

    /**
     * Returns a view that displays a form to create a new User Group.
     *
     * @author [A. Gianotto] [<snipe@snipe.net]
     *
     * @see GroupsController::postCreate()
     * @since [v1.0]
     */
    public function create(Request $request): View
    {
        $group = new Group;
        // Get all the available permissions
        $permissions = config('permissions');
        $groupPermissions = Helper::selectedPermissionsArray($permissions, $permissions);
        $selectedPermissions = $request->old('permissions', $groupPermissions);
        $users_query = User::query()
            ->select(['users.id', 'users.first_name', 'users.last_name', 'users.username'])
            ->where('show_in_list', 1)
            ->whereNull('deleted_at');

        $users_count = $users_query->count();

        $users = collect();
        if ($users_count <= config('app.max_unpaginated_records')) {
            $users = $users_query->orderBy('first_name', 'asc')->orderBy('last_name', 'asc')->get();
        }

        // Show the page
        return view('groups/edit', compact('permissions', 'selectedPermissions', 'groupPermissions'))
            ->with('group', $group)
            ->with('associated_users', collect())
            ->with('unselected_users', $users)
            ->with('all_users_count', $users_count)
            ->with('assetCategoryMatrix', $this->assetCategoryMatrix($group));
    }

    /**
     * Validates and stores the new User Group data.
     *
     * @author [A. Gianotto] [<snipe@snipe.net]
     *
     * @see GroupsController::getCreate()
     * @since [v1.0]
     */
    public function store(Request $request): RedirectResponse
    {
        // ERS: validate the asset category matrix before anything is written.
        try {
            $assetCategoryMatrix = SaveGroupAssetCategoryPermissionsAction::fromRequest($request);
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        }

        // create a new group instance
        $group = new Group;
        $group->name = $request->input('name');
        $group->permissions = json_encode(
            Helper::selectedPermissionsArray(
                config('permissions'),
                NormalizePermissionsPayloadAction::run($request->input('permission'))
            )
        );
        $group->created_by = auth()->id();
        $group->notes = $request->input('notes');

        // ERS: the group, its members and its asset category grants are
        // saved atomically.
        try {
            $saved = DB::transaction(function () use ($group, $request, $assetCategoryMatrix) {
                if (! $group->save()) {
                    return false;
                }

                if ($request->filled('users_to_sync')) {
                    $associated_users = explode(',', $request->input('users_to_sync'));
                    $group->users()->sync($associated_users);
                }

                if ($assetCategoryMatrix !== null) {
                    SaveGroupAssetCategoryPermissionsAction::replace($group, $assetCategoryMatrix);
                }

                return true;
            });
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        }

        if ($saved) {
            return redirect()->route('groups.index')->with('success', trans('admin/groups/message.success.create'));
        }

        return redirect()->back()->withInput()->withErrors($group->getErrors());
    }

    /**
     * Returns a view that presents a form to edit a User Group.
     *
     * @author [A. Gianotto] [<snipe@snipe.net]
     *
     * @see GroupsController::postEdit()
     *
     * @param  int  $id
     *
     * @since [v1.0]
     */
    public function edit(Group $group): View|RedirectResponse
    {
        $permissions = config('permissions');
        $groupPermissions = $group->decodePermissions();

        if ((! is_array($groupPermissions)) || (! $groupPermissions)) {
            $groupPermissions = [];
        }

        $selected_array = Helper::selectedPermissionsArray($permissions, $groupPermissions);

        $users_query = User::query()
            ->select(['users.id', 'users.first_name', 'users.last_name', 'users.username'])
            ->where('show_in_list', 1)
            ->whereNull('deleted_at');

        $users_count = $users_query->count();

        $associated_users = collect();
        $unselected_users = collect();

        if ($users_count <= config('app.max_unpaginated_records')) {
            $associated_users = $group->users()->where('show_in_list', 1)->orderBy('first_name', 'asc')->orderBy('last_name', 'asc')->get();
            // Get the unselected users
            $unselected_users = User::query()
                ->select(['users.id', 'users.first_name', 'users.last_name', 'users.username'])
                ->where('show_in_list', 1)
                ->whereNotIn('id', $associated_users->pluck('id')->toArray())
                ->orderBy('first_name', 'asc')
                ->orderBy('last_name', 'asc')
                ->get();
        }

        return view('groups.edit', compact('group', 'permissions', 'selected_array', 'groupPermissions'))
            ->with('associated_users', $associated_users)
            ->with('unselected_users', $unselected_users)
            ->with('all_users_count', $users_count)
            ->with('assetCategoryMatrix', $this->assetCategoryMatrix($group));
    }

    /**
     * Validates and stores the updated User Group data.
     *
     * @author [A. Gianotto] [<snipe@snipe.net]
     *
     * @see GroupsController::getEdit()
     *
     * @param  int  $id
     *
     * @since [v1.0]
     */
    public function update(Request $request, Group $group): RedirectResponse
    {
        // ERS: validate the asset category matrix before anything is written.
        try {
            $assetCategoryMatrix = SaveGroupAssetCategoryPermissionsAction::fromRequest($request);
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        }

        $group->name = $request->input('name');
        $group->notes = $request->input('notes');

        if ($request->has('permission')) {
            $group->permissions = json_encode(
                Helper::selectedPermissionsArray(
                    config('permissions'),
                    NormalizePermissionsPayloadAction::run($request->input('permission'))
                )
            );
        }

        if (! config('app.lock_passwords')) {
            // ERS: the group, its members and its asset category grants are
            // saved atomically.
            try {
                $saved = DB::transaction(function () use ($group, $request, $assetCategoryMatrix) {
                    if (! $group->save()) {
                        return false;
                    }

                    if ($request->has('users_to_sync')) {
                        $associated_users = explode(',', $request->input('users_to_sync'));
                        $group->users()->sync($associated_users);
                    }

                    if ($assetCategoryMatrix !== null) {
                        SaveGroupAssetCategoryPermissionsAction::replace($group, $assetCategoryMatrix);
                    }

                    return true;
                });
            } catch (ValidationException $e) {
                return redirect()->back()->withInput()->withErrors($e->errors());
            }

            if ($saved) {
                return redirect()->route('groups.index')->with('success', trans('admin/groups/message.success.update'));
            }

            return redirect()->back()->withInput()->withErrors($group->getErrors());
        }

        return redirect()->route('groups.index')->with('error', trans('general.feature_disabled'));
    }

    /**
     * Validates and deletes the User Group.
     *
     * @author [A. Gianotto] [<snipe@snipe.net]
     *
     * @see GroupsController::getEdit()
     *
     * @param  int  $id
     *
     * @since [v1.0]
     */
    public function destroy($id): RedirectResponse
    {
        if (! config('app.lock_passwords')) {

            if (! $group = Group::find($id)) {
                return redirect()->route('groups.index')->with('error', trans('admin/groups/message.group_not_found', ['id' => $id]));
            }

            if (! $group->isDeletable()) {
                return redirect()->route('groups.index')->with('error', trans('admin/groups/message.assoc_users'));
            }

            $group->delete();

            return redirect()->route('groups.index')->with('success', trans('admin/groups/message.success.delete'));
        }

        return redirect()->route('groups.index')->with('error', trans('general.feature_disabled'));
    }

    /**
     * Returns a view that invokes the ajax tables which actually contains
     * the content for the group detail page.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     *
     * @param  $id
     *
     * @since [v4.0.11]
     */
    public function show(Group $group): View|RedirectResponse
    {
        return view('groups/view', compact('group'));
    }

    /**
     * ERS Phase 5A: data for the asset category permission matrix, or null
     * when the current user may not see it (non-Super Users).
     *
     * Rows come from the live asset category tree (tree order, depth-limited,
     * nothing hard-coded). Final categories carry checkboxes; navigation
     * groups are shown only when they have final descendants and only get
     * bulk toggles. Selected cells come from old input after a failed save,
     * otherwise from the group's stored grants.
     *
     * @return array{rows: list<array{id: int, name: string, depth: int, group: bool, ancestors: list<int>}>, selected: array<int, array<string, bool>>}|null
     */
    private function assetCategoryMatrix(Group $group): ?array
    {
        if (! Gate::allows(SaveGroupAssetCategoryPermissionsAction::ABILITY)) {
            return null;
        }

        $tree = AssetCategoryTree::load();
        $rows = [];
        foreach ($tree->flatten() as $node) {
            if ($node->isNavigationOnly() && $tree->assignableIdsWithin($node->id) === []) {
                continue;
            }
            $rows[] = [
                'id' => $node->id,
                'name' => $node->name,
                'depth' => $node->depth,
                'group' => $node->isNavigationOnly(),
                'ancestors' => array_map(fn ($ancestor) => $ancestor->id, $tree->ancestors($node->id)),
            ];
        }

        if (session()->hasOldInput(SaveGroupAssetCategoryPermissionsAction::MARKER)) {
            $old = old(SaveGroupAssetCategoryPermissionsAction::INPUT, []);
            $selected = [];
            foreach (is_array($old) ? $old : [] as $categoryId => $operations) {
                foreach (is_array($operations) ? $operations : [] as $operation => $value) {
                    if ($value === '1') {
                        $selected[(int) $categoryId][(string) $operation] = true;
                    }
                }
            }
        } else {
            $selected = SaveGroupAssetCategoryPermissionsAction::currentMatrix($group);
        }

        return ['rows' => $rows, 'selected' => $selected];
    }
}
