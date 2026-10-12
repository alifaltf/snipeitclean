<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Setting;
use App\Services\AssetImport\Validation\AssetImportUniqueKeys;
use App\Services\AssetUniqueness\UniqueConstraintViolation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RegenerateAssetTags extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snipeit:regenerate-tags {--start=} {--output= : info|warn|error|all} {--no-backup : Do not run backup:run first}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This utility will regenerate all asset tags. THIS IS DATA-DESTRUCTIVE AND SHOULD BE USED WITH CAUTION. ';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * ERS: all or nothing. Every live asset row is locked in one
     * transaction (other writers wait), the new tags are computed with the
     * same formula as before and checked for duplicates among themselves
     * (compared like the database column compares them), then every tag is
     * first moved to a unique temporary value, so that swapping tags
     * between assets can never trip the live asset-tag uniqueness key, and
     * finally set to its new value through the model as before. Any failure
     * rolls back every tag and the next auto-tag base.
     *
     * @return mixed
     */
    public function handle()
    {
        if ($this->confirm('This will regenerate all of the asset tags within your system. This action is data-destructive and should be used with caution. Do you wish to continue?')) {
            $output['info'] = [];
            $output['warn'] = [];
            $output['error'] = [];
            $settings = Setting::getSettings();

            $start_tag = ($this->option('start')) ? $this->option('start') : (($settings->next_auto_tag_base) ? Setting::getSettings()->next_auto_tag_base : 1);

            $this->info('Starting at '.$start_tag);

            if (! $this->option('no-backup')) {
                try {
                    Artisan::call('backup:run');
                } catch (\Exception $e) {
                    $output['error'][] = $e;
                }
            }

            $refusal = null;
            try {
                $count = DB::transaction(function () use ($settings, $start_tag, &$output, &$refusal) {
                    // Every live asset, locked until commit: no other writer
                    // can change or add a tag while the tags are rewritten.
                    $assets = Asset::withoutGlobalScopes()->whereNull('deleted_at')->orderBy('id', 'asc')->lockForUpdate()->get();

                    $next = $start_tag;
                    $finals = [];
                    foreach ($assets as $asset) {
                        $finals[$asset->id] = $this->newTag($settings, $next);
                        $next++;
                    }

                    $keys = app(AssetImportUniqueKeys::class)->keys('asset_tag', array_values(array_unique($finals)));
                    $duplicates = count($finals) - count(array_unique(array_map(fn ($tag) => $keys[$tag], $finals)));
                    if ($duplicates > 0) {
                        $refusal = trans('admin/hardware/uniqueness.regenerate.duplicates', ['count' => $duplicates]);
                        throw new RuntimeException('The new tags are not unique.');
                    }

                    // Temporary values, unique per asset and per run.
                    $nonce = bin2hex(random_bytes(6));
                    foreach ($assets as $asset) {
                        DB::table('assets')->where('id', $asset->id)->update(['asset_tag' => '__ers_regen_'.$nonce.'_'.$asset->id]);
                    }

                    $bar = $this->output->createProgressBar(count($assets));
                    foreach ($assets as $asset) {
                        $output['info'][] = 'Asset tag:'.$asset->asset_tag;
                        if ((string) $asset->getOriginal('asset_tag') === $finals[$asset->id]) {
                            // Unchanged: only the temporary value is undone
                            // (the model sees no change, as before, so no
                            // history entry is written).
                            DB::table('assets')->where('id', $asset->id)->update(['asset_tag' => $finals[$asset->id]]);
                        } else {
                            $asset->asset_tag = $finals[$asset->id];

                            // Use forceSave here to override model level validation
                            // (the database uniqueness key still applies).
                            $asset->forceSave();
                        }
                        $output['info'][] = 'New Asset tag:'.$finals[$asset->id];
                        $bar->advance();
                    }

                    // Nothing may keep a temporary value ("_" matches any
                    // character: harmless next to a random nonce, portable).
                    if (DB::table('assets')->where('asset_tag', 'like', '__ers_regen_'.$nonce.'_%')->exists()) {
                        throw new RuntimeException('A temporary tag was left behind.');
                    }
                    $bar->finish();

                    $settings->next_auto_tag_base = Asset::zerofill($next, $settings->zerofill_count);
                    $settings->save();

                    return count($assets);
                });
            } catch (Throwable $exception) {
                // The settings cache may hold the rolled-back next tag base.
                Setting::$_cache = null;
                $this->newLine();
                // Never the exception message: database errors carry values and SQL.
                $this->error($refusal ?? trans('admin/hardware/uniqueness.regenerate.failed'));
                if (UniqueConstraintViolation::detect($exception) === null && $refusal === null) {
                    report(new RuntimeException('snipeit:regenerate-tags was rolled back ('.$exception::class.').'));
                }

                return self::FAILURE;
            }

            $this->info("\n");
            $this->info(trans('admin/hardware/uniqueness.regenerate.done', ['count' => $count]));

            if (($this->option('output') == 'all') || ($this->option('output') == 'info')) {
                foreach ($output['info'] as $key => $output_text) {
                    $this->info($output_text);
                }
            }
            if (($this->option('output') == 'all') || ($this->option('output') == 'warn')) {
                foreach ($output['warn'] as $key => $output_text) {
                    $this->warn($output_text);
                }
            }
            if (($this->option('output') == 'all') || ($this->option('output') == 'error')) {
                foreach ($output['error'] as $key => $output_text) {
                    $this->error($output_text);
                }
            }
        }

        return self::SUCCESS;
    }

    /** The tag formula, unchanged from before. */
    private function newTag(Setting $settings, int|string $number): string
    {
        $tag = $settings->auto_increment_prefix.$settings->auto_increment_prefix.$number;
        if ($settings->zerofill_count > 0) {
            $tag = $settings->auto_increment_prefix.Asset::zerofill($number, $settings->zerofill_count);
        }

        return (string) $tag;
    }
}
