<?php

/*
|--------------------------------------------------------------------------
| ERS Assets Sidebar Configuration
|--------------------------------------------------------------------------
|
| Configuration for the ERS-specific Assets sidebar. "hardware" and
| "software" below are VIRTUAL grouping labels for the sidebar only —
| neither is a Snipe-IT category and neither has a category_id of its
| own.
|
| This file contains ONLY presentation settings for each virtual group
| (its sidebar label, and the label/page title used for its "All ..."
| aggregate). It does NOT list, allow-list, or otherwise reference any
| Snipe-IT category by name or ID.
|
| Which existing categories (category_type = asset) belong under each
| group is determined entirely by the database, via each category's own
| `ers_asset_group` column (see the
| 2026_08_24_000000_add_ers_asset_group_to_categories_table migration,
| App\Models\Category, and App\Http\Controllers\CategoriesController's
| "Asset Group" field on the category create/edit form). A category
| shows up under a group here if and only if:
|
|     category_type = 'asset' AND ers_asset_group = '<the group's key
|     below>'
|
| The top-level array keys used here ("hardware", "software") ARE the
| valid values an administrator can pick for a category's ers_asset_group
| — see App\Http\Controllers\Assets\AssetsController@index and
| App\View\Composers\SidebarComposer, which both resolve category IDs
| dynamically at request time via a query like:
|
|     Category::query()
|         ->where('category_type', 'asset')
|         ->where('ers_asset_group', $groupKey)
|         ->orderBy('name')
|         ->get();
|
| A brand-new category automatically appears under its group the moment
| an administrator saves it with that ers_asset_group value — no config
| change required. A group with zero categories currently assigned to it
| is hidden from the sidebar entirely. This feature never creates,
| renames, or deletes a category on its own, and existing categories
| (including the legacy "Hardware Devices" category) keep
| ers_asset_group = null until an administrator explicitly opts them in.
|
*/

return [

    'hardware' => [
        // Sidebar label for the virtual "Hardware" parent group.
        'label' => 'Hardware',
        // Sidebar label + page title for the "All Hardware" aggregate,
        // which combines every category currently assigned to this group.
        'all_label' => 'All Hardware',
        'all_title' => 'Hardware Assets',
    ],

    'software' => [
        'label' => 'Software',
        'all_label' => 'All Software',
        'all_title' => 'Software Assets',
    ],

];
