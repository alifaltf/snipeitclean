<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Setting;
use App\Services\AssetCategorySelection;
use App\Services\AssetTableLayout\AssetTableColumn;
use App\Services\AssetTableLayout\AssetTableLayoutQuery;
use App\Services\AssetTableLayout\AssetTableValueFormatter;
use Illuminate\Http\Request;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ERS Phase 7B: CSV export of a final asset category's asset table, with
 * the category's effective columns in their saved order.
 *
 * - The same assets, in the same order, as the category table shows: the
 *   export applies the same AssetTableLayoutQuery calls the asset API uses
 *   in layout mode (list filters such as company, status type, status,
 *   order number; search; per-column filters with their and/or operator;
 *   sorting), with the same validation and the same rule that only the
 *   layout's columns may filter, search or sort. The page's export link
 *   carries the table's current parameters; whatever arrives is validated
 *   again here, never trusted.
 * - Only assets the user may see: the Asset query's global scopes (company
 *   scope, category View permissions) apply, as everywhere.
 * - The category comes only from ?asset_category=<id> resolved server-side
 *   (AssetCategorySelection): a category the user may not view, a
 *   navigation group or anything invalid is "not found".
 * - Encrypted custom fields are exported only for users with the existing
 *   "View Encrypted Custom Fields" permission (otherwise "ENCRYPTED").
 * - Every cell, the header included, is protected against spreadsheet
 *   formulas (League\Csv\EscapeFormula, Snipe-IT's backtick prefix),
 *   whatever CSV_ESCAPE_FORMULAS says.
 *
 * Read-only: nothing is written.
 */
class CategoryAssetExportController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('index', Asset::class);

        $layout = AssetTableLayoutQuery::forSelection(AssetCategorySelection::forRequest($request));
        if ($layout === null) {
            throw new NotFoundHttpException;
        }

        // Exactly the table's list: the same three calls the asset API makes
        // in layout mode, with the parameters the table sends.
        $assets = Asset::query()->select('assets.*')->with($layout->eagerLoads());
        $layout->applyListFilters($assets, $request, Setting::getSettings());
        $layout->applySearch($assets, $request->input('search'), $request->input('filter'), $request->input('filter_operator'));
        $layout->applySort($assets, $request->input('sort'), $request->input('order'));

        $formatter = new AssetTableValueFormatter;
        $columns = $layout->columns;
        $filename = 'export-'.(str_slug((string) $layout->category->name) ?: 'category').'-'.trans('admin/categories/table_layout.export_filename').'-'.date('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($assets, $columns, $formatter) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM, as Snipe-IT's own CSV exports
            $writer = Writer::createFromStream($handle);
            $writer->addFormatter(new EscapeFormula('`'));

            $writer->insertOne(array_map(fn (AssetTableColumn $column) => $column->label, $columns));
            foreach ($assets->lazy(500) as $asset) {
                $writer->insertOne(array_map(fn (AssetTableColumn $column) => $formatter->value($asset, $column), $columns));
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
