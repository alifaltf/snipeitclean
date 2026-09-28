{{--
    ERS Phase 4: live asset category hierarchy under Assets in the sidebar.

    $asset_category_nav comes from App\View\Composers\SidebarComposer and is
    built from the live database tree (App\Services\AssetCategoryNavigation);
    nothing here names a specific category. Every node links to the existing
    Assets list with a single ?asset_category=<id>; the server resolves which
    categories that id covers.

    Nodes are plain links (their <li> is never an AdminLTE ".treeview"), so
    AdminLTE's tree plugin never swallows a click on a navigation group. The
    selected node and its ancestors carry "active", which both highlights
    them and keeps their branch open; other groups open with the caret button.
--}}
@if (! empty($asset_category_nav))
    @include('partials.asset-category-nav-items', ['items' => $asset_category_nav])

    <script nonce="{{ csrf_token() }}">
        document.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('.ers-asset-category-toggle') : null;
            if (!button) {
                return;
            }
            event.preventDefault();
            var menu = document.getElementById(button.getAttribute('aria-controls'));
            if (!menu) {
                return;
            }
            var expand = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', expand ? 'true' : 'false');
            menu.style.display = expand ? 'block' : 'none';
        });
    </script>
@endif
