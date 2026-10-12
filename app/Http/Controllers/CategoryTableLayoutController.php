<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\AssetTableLayout\AssetTableColumn;
use App\Services\AssetTableLayout\AssetTableLayoutAccess;
use App\Services\AssetTableLayout\AssetTableLayoutForm;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ERS Phase 7A: asset-table column layout of a final asset category.
 *
 * Every action requires the categories.manage_asset_table_layout ability
 * (AssetTableLayoutAccess) and a live, final asset category; navigation
 * groups, other category types and deleted categories are "not found".
 * The layout is only configuration: no asset or custom-field value is read
 * or written here.
 */
class CategoryTableLayoutController extends Controller
{
    public function __construct(private readonly AssetTableLayoutService $layouts) {}

    public function edit(Category $category): View
    {
        $this->authorizeFor($category);

        $effective = $this->layouts->effectiveKeys($category);
        $selectable = $this->layouts->selectableColumns($category);

        return view('categories/table-layout', [
            'category' => $category,
            'rows' => $this->rows($selectable, $effective, old(AssetTableLayoutForm::INPUT)),
            'effectiveColumns' => $this->layouts->effectiveColumns($category),
            'explicit' => $this->layouts->hasExplicitLayout($category),
        ]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeFor($category);

        try {
            $keys = AssetTableLayoutForm::keys($request->input(AssetTableLayoutForm::INPUT));
            $this->layouts->save($category, $keys);
        } catch (ValidationException $exception) {
            return redirect()->route('categories.table-layout.edit', ['category' => $category->id])
                ->withInput()
                ->withErrors($exception->errors());
        }

        return redirect()->route('categories.table-layout.edit', ['category' => $category->id])
            ->with('success', trans('admin/categories/table_layout.message.saved'));
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorizeFor($category);

        try {
            $this->layouts->reset($category);
        } catch (ValidationException $exception) {
            return redirect()->route('categories.table-layout.edit', ['category' => $category->id])
                ->withErrors($exception->errors());
        }

        return redirect()->route('categories.table-layout.edit', ['category' => $category->id])
            ->with('success', trans('admin/categories/table_layout.message.reset'));
    }

    /**
     * Permission first (403), then the category (404), so the response does
     * not reveal anything about categories to unauthorised users.
     */
    private function authorizeFor(Category $category): void
    {
        $this->authorize(AssetTableLayoutAccess::ABILITY);

        if (! $this->layouts->isConfigurable($category)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Form rows: the effective columns first in their order, then the other
     * selectable columns. After a failed submission, the submitted choices
     * and order numbers of still-selectable columns are shown again, in
     * the submitted row order.
     *
     * @param  list<AssetTableColumn>  $selectable
     * @param  list<string>  $effective
     * @return list<array{column: AssetTableColumn, show: bool, position: string}>
     */
    private function rows(array $selectable, array $effective, mixed $old): array
    {
        $byKey = [];
        foreach ($selectable as $column) {
            $byKey[$column->key] = $column;
        }

        $rows = [];
        if (is_array($old)) {
            foreach ($old as $row) {
                $key = is_array($row) && is_string($row['key'] ?? null) ? $row['key'] : null;
                if ($key === null || ! isset($byKey[$key]) || isset($rows[$key])) {
                    continue;
                }
                $position = $row['position'] ?? '';
                $rows[$key] = [
                    'column' => $byKey[$key],
                    'show' => ($row['show'] ?? null) === '1',
                    'position' => is_string($position) ? $position : '',
                ];
            }
        } else {
            foreach ($effective as $index => $key) {
                if (isset($byKey[$key])) {
                    $rows[$key] = ['column' => $byKey[$key], 'show' => true, 'position' => (string) ($index + 1)];
                }
            }
        }

        foreach ($byKey as $key => $column) {
            $rows[$key] ??= ['column' => $column, 'show' => false, 'position' => ''];
        }

        return array_values($rows);
    }
}
