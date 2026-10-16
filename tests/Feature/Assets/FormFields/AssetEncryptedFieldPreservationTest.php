<?php

namespace Tests\Feature\Assets\FormFields;

use App\Models\CustomField;
use App\Models\User;
use App\Services\AssetFields\AssetCustomFieldEligibility;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 7C: encrypted custom fields are never sent in plaintext to a
 * user without "view encrypted custom fields", and are never overwritten
 * or cleared by the masked placeholder, an omitted value, or a user who
 * may not see them (web, API and bulk).
 */
class AssetEncryptedFieldPreservationTest extends TestCase
{
    use BuildsFormFieldFixtures;

    private CustomField $secretBox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFormFields();
        // An encrypted checkbox field in Model One's fieldset.
        // (Saved as a plain checkbox, then flagged encrypted directly: the
        // field editor's own rules are not what is tested here.)
        $field = CustomField::factory()->create(['name' => 'SecretBox '.uniqid(), 'format' => '', 'element' => 'checkbox', 'field_values' => "Alpha\r\nBeta"]);
        DB::table('custom_fields')->where('id', $field->id)->update(['field_encrypted' => 1]);
        $this->secretBox = $field->fresh();
        $this->setOne->fields()->attach($this->secretBox->id, ['order' => 4, 'required' => 0]);
    }

    /**
     * An asset with an encrypted value. With $withSecretBox the encrypted
     * checkbox holds one too; Snipe-IT's own checkbox-option rule cannot
     * validate an encrypted checkbox value on save (the field editor does
     * not allow such fields), so that case is only used for rendering and
     * for writes that never reach a save.
     */
    private function encryptedAsset(bool $withSecretBox = false)
    {
        $asset = $this->assetOf($this->modelOne, [$this->secret->db_column => 'TOP-SECRET-VALUE', $this->plain->db_column => 'plain']);
        if ($withSecretBox) {
            DB::table('assets')->where('id', $asset->id)->update([$this->secretBox->db_column => Crypt::encrypt('Beta')]);
        } else {
            $this->setOne->fields()->detach($this->secretBox->id);
        }

        return $asset->fresh();
    }

    #[Test]
    public function the_edit_form_never_contains_plaintext_without_the_permission(): void
    {
        $asset = $this->encryptedAsset(true);
        $html = (string) $this->actingAs($this->editor(), 'web')->get(route('hardware.edit', $asset))->assertOk()->getContent();

        $this->assertStringNotContainsString('TOP-SECRET-VALUE', $html);
        $this->assertStringNotContainsString('name="'.$this->secret->db_column.'"', $html, 'The masked field is not submitted.');
        $this->assertStringNotContainsString('name="'.$this->secretBox->db_column.'[]"', $html, 'Encrypted checkboxes are masked too.');
        $this->assertStringContainsString(AssetCustomFieldEligibility::maskedPlaceholder(), $html);

        $json = $this->actingAsForApi($this->editor())->getJson(route('api.assets.show', $asset))->assertOk()->getContent();
        $this->assertStringNotContainsString('TOP-SECRET-VALUE', $json);
    }

    #[Test]
    public function a_permitted_user_sees_the_decrypted_value_and_checkbox_state(): void
    {
        $asset = $this->encryptedAsset(true);
        $html = (string) $this->actingAs($this->editor(['assets.view.encrypted_custom_fields' => '1']), 'web')->get(route('hardware.edit', $asset))->assertOk()->getContent();

        $this->assertStringContainsString('TOP-SECRET-VALUE', $html);
        $this->assertMatchesRegularExpression('/value="Beta" name="'.$this->secretBox->db_column.'\[\]"\s+checked/', $html, 'The checkbox state comes from the decrypted value.');
        $this->assertDoesNotMatchRegularExpression('/value="Alpha" name="'.$this->secretBox->db_column.'\[\]"\s+checked/', $html);
    }

    #[Test]
    public function web_edit_without_the_permission_preserves_encrypted_values(): void
    {
        $asset = $this->encryptedAsset();
        $user = $this->editor();

        // Placeholder submitted, field omitted, checkbox omitted.
        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [
            $this->secret->db_column => AssetCustomFieldEligibility::maskedPlaceholder(),
            $this->plain->db_column => 'changed',
        ]))->assertSessionHasNoErrors();
        $this->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->secret->db_column => 'attempted overwrite']))->assertSessionHasNoErrors();

        $this->assertSame('TOP-SECRET-VALUE', $this->stored($asset)['secret']);
        $this->assertSame('changed', $this->stored($asset)['plain']);
    }

    #[Test]
    public function the_placeholder_never_overwrites_even_for_a_permitted_user(): void
    {
        $asset = $this->encryptedAsset();
        $user = $this->editor(['assets.view.encrypted_custom_fields' => '1']);

        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [
            $this->secret->db_column => AssetCustomFieldEligibility::maskedPlaceholder(),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('TOP-SECRET-VALUE', $this->stored($asset)['secret']);

        $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset), [$this->secret->db_column => AssetCustomFieldEligibility::maskedPlaceholder()])
            ->assertJsonPath('status', 'success');
        $this->assertSame('TOP-SECRET-VALUE', $this->stored($asset)['secret']);

        // A real new value is stored, encrypted.
        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->secret->db_column => 'NEW-SECRET']))->assertSessionHasNoErrors();
        $this->assertSame('NEW-SECRET', $this->stored($asset)['secret']);
        $this->assertStringNotContainsString('NEW-SECRET', (string) DB::table('assets')->where('id', $asset->id)->value($this->secret->db_column));
    }

    #[Test]
    public function api_edit_without_the_permission_preserves_encrypted_values(): void
    {
        $asset = $this->encryptedAsset();
        $this->actingAsForApi($this->editor())->patchJson(route('api.assets.update', $asset), [
            $this->secret->db_column => 'attempted overwrite',
            $this->plain->db_column => 'api',
        ])->assertJsonPath('status', 'success');

        $this->assertSame('TOP-SECRET-VALUE', $this->stored($asset)['secret']);
        $this->assertSame('api', $this->stored($asset)['plain']);
    }

    #[Test]
    public function an_unreadable_stored_value_survives_an_unchanged_edit(): void
    {
        $this->setOne->fields()->detach($this->secretBox->id);
        $asset = $this->assetOf($this->modelOne);
        DB::table('assets')->where('id', $asset->id)->update([$this->secret->db_column => 'not-a-valid-payload']);
        $user = $this->editor(['assets.view.encrypted_custom_fields' => '1']);

        // The form shows the "Error Decrypting" text; submitting it back keeps the stored value.
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.edit', $asset))->assertOk()->getContent();
        preg_match('/value="([^"]*)"[^>]*name="'.$this->secret->db_column.'"/', $html, $match);
        $this->assertStringStartsWith('Error Decrypting:', html_entity_decode($match[1] ?? ''));
        $this->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->secret->db_column => html_entity_decode($match[1])]))->assertSessionHasNoErrors();

        $this->assertSame('not-a-valid-payload', DB::table('assets')->where('id', $asset->id)->value($this->secret->db_column));
    }

    #[Test]
    public function bulk_edit_keeps_encrypted_values_for_the_placeholder(): void
    {
        $asset = $this->encryptedAsset();
        // Snipe-IT's bulk edit writes encrypted fields only for admins.
        $admin = User::factory()->superuser()->create();

        $this->actingAs($admin, 'web')->post(route('hardware/bulksave'), [
            'ids' => [$asset->id],
            $this->secret->db_column => AssetCustomFieldEligibility::maskedPlaceholder(),
            $this->plain->db_column => 'bulk',
        ])->assertRedirect();

        $this->assertSame('TOP-SECRET-VALUE', $this->stored($asset)['secret']);
        $this->assertSame('bulk', $this->stored($asset)['plain']);
    }
}
