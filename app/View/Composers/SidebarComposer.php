<?php

// A View Composer is a callback Laravel runs right before a specific view renders.
// It's registered in AppServiceProvider bound to 'layouts.default', so it only fires
// when a full page is rendered — not on modal AJAX responses, select2 searches, or
// API requests. This replaces the old AssetCountForSidebar middleware, which ran on
// every web request regardless of what was returned.

namespace App\View\Composers;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SidebarComposer
{
    public function compose(View $view): void
    {
        // Guard against the setup wizard, where DB tables may not exist yet
        try {
            $settings = Setting::getSettings();
        } catch (\Exception $e) {
            Log::debug($e);

            return;
        }

        try {
            $due_for_checkin = Asset::DueForCheckin($settings)->count();
            $overdue_for_checkin = Asset::OverdueForCheckin()->count();
            $due_for_audit = Asset::DueForAudit($settings)->count();
            $overdue_for_audit = Asset::OverdueForAudit()->count();

            $view->with([
                'total_assets' => Asset::AssetsForShow()->count(),
                'total_rtd_sidebar' => Asset::RTD()->count(),
                'total_deployed_sidebar' => Asset::Deployed()->count(),
                'total_archived_sidebar' => Asset::Archived()->count(),
                'total_pending_sidebar' => Asset::Pending()->count(),
                'total_undeployable_sidebar' => Asset::Undeployable()->count(),
                'total_byod_sidebar' => Asset::where('byod', 1)->count(),
                'total_due_for_audit' => $due_for_audit,
                'total_overdue_for_audit' => $overdue_for_audit,
                'total_due_for_checkin' => $due_for_checkin,
                'total_overdue_for_checkin' => $overdue_for_checkin,
                'total_due_and_overdue_for_checkin' => $due_for_checkin + $overdue_for_checkin,
                'total_due_and_overdue_for_audit' => $due_for_audit + $overdue_for_audit,
                'ers_sidebar' => $this->resolveErsAssetsSidebar(),
            ]);
        } catch (\Exception $e) {
            Log::debug($e);
        }
    }

    /**
     * Resolves the ERS-specific Assets sidebar. config('ers_assets') only
     * supplies each virtual group's PRESENTATION (sidebar label, "All ..."
     * label/title) — its top-level keys ("hardware", "software") are the
     * only allowed values of a category's `ers_asset_group` column. Which
     * categories actually appear under each group is resolved fresh from
     * the database on every request:
     *
     *     Category::where('category_type', 'asset')
     *         ->where('ers_asset_group', $groupKey)
     *         ->orderBy('name')
     *         ->get();
     *
     * "hardware" and "software" are VIRTUAL grouping labels only — neither
     * is itself a Snipe-IT category, so neither ever gets a category_id.
     * No category name or category_id is ever hard-coded here: a category
     * shows up the moment an administrator sets its Asset Group to match,
     * and disappears the moment they clear or change it. A group with no
     * assigned categories is left with an empty 'categories' array, which
     * the sidebar Blade uses to hide the whole group.
     *
     * Centralizing this lookup here (rather than in the Blade view) keeps
     * the sidebar free of direct database queries, per the same pattern
     * already used for the counts above.
     *
     * @return array<string, array{
     *     label: ?string,
     *     all_label: ?string,
     *     all_title: ?string,
     *     all_category_ids: ?string,
     *     categories: list<array{key: string, label: string, title: ?string, category_id: int}>,
     * }>
     */
    private function resolveErsAssetsSidebar(): array
    {
        $resolved = [];

        foreach ((array) config('ers_assets', []) as $groupKey => $group) {
            if (! is_array($group) || ! is_string($groupKey) || $groupKey === '') {
                continue;
            }

            $categories = Category::query()
                ->where('category_type', 'asset')
                ->where('ers_asset_group', $groupKey)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => [
                    'key' => (string) $category->id,
                    'label' => $category->name,
                    'title' => null,
                    'category_id' => $category->id,
                ])
                ->all();

            $resolved[$groupKey] = [
                'label' => $group['label'] ?? null,
                'all_label' => $group['all_label'] ?? null,
                'all_title' => $group['all_title'] ?? null,
                // "All {Group}" aggregates every category currently
                // assigned to this group.
                'all_category_ids' => $categories === [] ? null : implode(',', array_column($categories, 'category_id')),
                'categories' => $categories,
            ];
        }

        return $resolved;
    }
}
