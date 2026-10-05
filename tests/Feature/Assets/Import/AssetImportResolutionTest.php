<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetModel;
use App\Models\Company;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6B: resolving models, status labels, companies, locations and
 * suppliers. Exact, case-insensitive name matches only; missing and
 * ambiguous names are row errors; nothing outside the selected category or
 * the user's company scope ever resolves; nothing is created.
 */
class AssetImportResolutionTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function msg(string $key): string
    {
        return trans('admin/hardware/import.validation.'.$key);
    }

    #[Test]
    public function a_fixed_model_resolves_for_every_row_and_breaks_when_it_leaves_the_category(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag\nT-1\nT-2\n", [], [0 => 'standard:asset_tag']);

        $result = $this->validationOf($user, $session);
        $this->assertTrue($result->isEligible());
        $this->assertSame([$this->asset['leaf1']->model_id, $this->asset['leaf1']->model_id], array_map(fn ($r) => $r->attributes['model_id'], $result->rows));

        DB::table('models')->where('id', $this->asset['leaf1']->model_id)->update(['category_id' => $this->cat['leaf2']->id]);
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.target', $session->public_id));
    }

    #[Test]
    public function mapped_models_resolve_only_among_live_models_of_the_selected_category(): void
    {
        $user = $this->importer();
        $leaf1 = $this->modelIn('leaf1');
        $twinA = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => 'Twin Model', 'model_number' => 'TA-1']);
        AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => 'Twin Model', 'model_number' => 'TB-2']);
        $deleted = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => 'Retired Model']);
        $deleted->delete();
        $navigation = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => 'Navigation Model']);
        DB::table('models')->where('id', $navigation->id)->update(['category_id' => $this->cat['groupA']->id]);

        $csv = "Asset Tag,Model,Model Number\n"
            .'T-1,  '.strtoupper($leaf1->name)."  ,\n"
            ."T-2,Twin Model,\n"
            ."T-3,twin model,ta-1\n"
            ."T-4,Twin Model,ZZ-9\n"
            ."T-5,{$this->modelIn('leaf2')->name},\n"
            ."T-6,Retired Model,\n"
            ."T-7,Navigation Model,\n"
            ."T-8,,\n"
            .'T-9,'.substr($leaf1->name, 0, 4).",\n";
        $session = $this->configuredSession($user, $csv, ['model_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:model', 2 => 'standard:model_number']);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $this->assertSame([], $errors[2], 'Exact match after trimming and ignoring case.');
        $this->assertSame($leaf1->id, $result->rows[0]->attributes['model_id']);
        $this->assertSame(['standard:model: '.$this->msg('model_ambiguous')], $errors[3]);
        $this->assertSame([], $errors[4], 'Model Number disambiguates.');
        $this->assertSame($twinA->id, $result->rows[2]->attributes['model_id']);
        foreach ([5 => 'wrong number', 6 => 'other category', 7 => 'deleted', 8 => 'navigation category', 10 => 'partial name'] as $row => $case) {
            $this->assertSame(['standard:model: '.$this->msg('model_not_found')], $errors[$row], $case);
        }
        $this->assertSame(['standard:model: '.$this->msg('required')], $errors[9]);
        $this->assertSame(1, AssetModel::query()->where('name', 'Twin Model')->where('model_number', 'TA-1')->count(), 'Nothing created.');
    }

    #[Test]
    public function statuses_resolve_fixed_or_by_exact_name(): void
    {
        $user = $this->importer();
        Statuslabel::factory()->create(['name' => 'Twin Status']);
        Statuslabel::factory()->create(['name' => 'twin status']);
        $deleted = Statuslabel::factory()->create(['name' => 'Old Status']);
        $deleted->delete();

        $session = $this->configuredSession($user, "Asset Tag,Status\nT-1,{$this->status->name}\nT-2,Twin Status\nT-3,Old Status\nT-4,Nope\nT-5,\n", ['status_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:status']);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $this->assertSame([], $errors[2]);
        $this->assertSame($this->status->id, $result->rows[0]->attributes['status_id']);
        $this->assertSame(['standard:status: '.$this->msg('ambiguous')], $errors[3]);
        $this->assertSame(['standard:status: '.$this->msg('not_found')], $errors[4]);
        $this->assertSame(['standard:status: '.$this->msg('not_found')], $errors[5]);
        $this->assertSame(['standard:status: '.$this->msg('required')], $errors[6]);

        $fixed = $this->configuredSession($user, "Asset Tag\nT-1\n", [], [0 => 'standard:asset_tag']);
        $this->assertSame($this->status->id, $this->validationOf($user, $fixed)->rows[0]->attributes['status_id']);
    }

    #[Test]
    public function companies_and_locations_resolve_in_scope_and_must_fit_together(): void
    {
        $user = $this->importer();
        $mine = Company::factory()->create(['name' => 'Mine '.Str::random(4)]);
        $sister = Company::factory()->create(['name' => 'Sister '.Str::random(4)]);
        $foreign = Company::factory()->create(['name' => 'Foreign '.Str::random(4)]);
        $mine->users()->attach($user->id);
        $sister->users()->attach($user->id);
        Company::flushCompanyIdsCache();
        $myLocation = Location::factory()->create(['name' => 'My Site', 'company_id' => $mine->id]);
        $child = Location::factory()->create(['name' => 'Child Site', 'company_id' => null, 'parent_id' => $myLocation->id]);
        Location::factory()->create(['name' => 'Sister Site', 'company_id' => $sister->id]);
        Location::factory()->create(['name' => 'Foreign Site', 'company_id' => $foreign->id]);
        Location::factory()->create(['name' => 'Twin Site', 'company_id' => $mine->id]);
        Location::factory()->create(['name' => 'Twin Site', 'company_id' => $mine->id, 'parent_id' => $myLocation->id]);
        $this->settings->enableMultipleFullCompanySupport()->set(['scope_locations_fmcs' => 1]);

        $csv = "Asset Tag,Company,Location\n"
            ."T-1,{$mine->name},My Site\n"
            ."T-2,{$mine->name},Child Site\n"
            ."T-3,{$mine->name},Sister Site\n"
            ."T-4,{$mine->name},Foreign Site\n"
            ."T-5,{$foreign->name},My Site\n"
            ."T-6,{$mine->name},Twin Site\n"
            ."T-7,{$mine->name},\n";
        $session = $this->configuredSession($user, $csv, ['company_source' => 'column', 'location_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:company', 2 => 'standard:location']);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $this->assertSame([], $errors[2]);
        $this->assertSame([$mine->id, $myLocation->id], [$result->rows[0]->attributes['company_id'], $result->rows[0]->attributes['location_id']]);
        // Snipe-IT's company scope hides company-less locations from this
        // user, so the child is simply not available to them.
        $this->assertSame(['standard:location: '.$this->msg('not_found')], $errors[3]);
        $this->assertSame(['standard:location: '.$this->msg('location_company_mismatch')], $errors[4]);
        $this->assertSame(['standard:location: '.$this->msg('not_found')], $errors[5], 'Out-of-scope locations are not found.');
        $this->assertSame(['standard:company: '.$this->msg('not_found')], $errors[6], 'Out-of-scope companies are not found.');
        $this->assertSame(['standard:location: '.$this->msg('ambiguous')], $errors[7]);
        $this->assertSame([], $errors[8], 'Location optional.');

        foreach ($result->errors() as $error) {
            $this->assertStringNotContainsString($foreign->name, $error->message);
            $this->assertStringNotContainsString('Foreign Site', $error->message);
        }

        // A Super Admin sees every location; a company-less child location
        // takes its parent's company, as in Snipe-IT's fmcs_location rule.
        $admin = $this->superUser();
        $session = $this->configuredSession($admin, "Asset Tag,Company,Location
A-1,{$mine->name},Child Site
A-2,{$foreign->name},Child Site
", ['company_source' => 'column', 'location_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:company', 2 => 'standard:location']);
        $result = $this->validationOf($admin, $session);
        $this->assertSame([2 => [], 3 => ['standard:location: '.$this->msg('location_company_mismatch')]], $this->errorsByRow($result));
        $this->assertSame($child->id, $result->rows[0]->attributes['location_id']);
    }

    #[Test]
    public function location_and_company_compatibility_matches_snipe_its_own_rule(): void
    {
        $admin = $this->superUser();
        $first = Company::factory()->create(['name' => 'First '.Str::random(4)]);
        $second = Company::factory()->create(['name' => 'Second '.Str::random(4)]);
        $parent = Location::factory()->create(['name' => 'Parent '.Str::random(4), 'company_id' => $first->id]);
        $child = Location::factory()->create(['name' => 'Child '.Str::random(4), 'company_id' => null, 'parent_id' => $parent->id]);
        $orphan = Location::factory()->create(['name' => 'Orphan '.Str::random(4), 'company_id' => null]);
        $this->settings->enableMultipleFullCompanySupport()->set(['scope_locations_fmcs' => 1]);

        foreach ([false, true] as $floater) {
            $this->settings->set(['null_company_is_floater' => $floater ? 1 : 0]);
            $csv = 'Asset Tag,Company,Location
';
            $pairs = [];
            $i = 0;
            foreach ([$first, $second] as $company) {
                foreach ([$parent, $child, $orphan] as $location) {
                    $csv .= 'P-'.(++$i).",{$company->name},{$location->name}\n";
                    $pairs[$i + 1] = [$company, $location];
                }
            }
            $session = $this->configuredSession($admin, $csv, ['company_source' => 'column', 'location_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:company', 2 => 'standard:location']);
            $errors = $this->errorsByRow($this->validationOf($admin, $session));

            foreach ($pairs as $row => [$company, $location]) {
                $native = \Illuminate\Support\Facades\Validator::make(['company_id' => $company->id, 'location_id' => $location->id], ['location_id' => 'fmcs_location'])->passes();
                $this->assertSame($native, $errors[$row] === [], "floater=$floater {$company->name} / {$location->name}");
            }
        }
    }

    #[Test]
    public function fixed_company_and_location_apply_to_every_row(): void
    {
        $admin = $this->superUser();
        $company = Company::factory()->create();
        $location = Location::factory()->create(['company_id' => $company->id]);
        $session = $this->configuredSession($admin, "Asset Tag\nT-1\nT-2\n", ['company_source' => 'fixed', 'company_id' => $company->id, 'location_source' => 'fixed', 'location_id' => $location->id], [0 => 'standard:asset_tag']);

        $result = $this->validationOf($admin, $session);
        $this->assertTrue($result->isEligible());
        foreach ($result->rows as $row) {
            $this->assertSame([$company->id, $location->id], [$row->attributes['company_id'], $row->attributes['location_id']]);
        }

        // A fixed company that disappears sends the user back to the target.
        $company->delete();
        $this->postValidate($admin, $session)->assertRedirect(route('hardware.import.target', $session->public_id));
    }

    #[Test]
    public function suppliers_must_already_exist(): void
    {
        $user = $this->importer();
        $supplier = Supplier::factory()->create(['name' => 'Acme '.Str::random(4)]);
        $suppliers = Supplier::query()->count();

        $session = $this->configuredSession($user, "Asset Tag,Supplier\nT-1,".strtolower($supplier->name)."\nT-2,Brand New Supplier\nT-3,\n", [], [0 => 'standard:asset_tag', 1 => 'standard:supplier']);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $this->assertSame([], $errors[2]);
        $this->assertSame($supplier->id, $result->rows[0]->attributes['supplier_id']);
        $this->assertSame(['standard:supplier: '.$this->msg('not_found')], $errors[3]);
        $this->assertSame([], $errors[4]);
        $this->assertSame($suppliers, Supplier::query()->count());
    }
}
