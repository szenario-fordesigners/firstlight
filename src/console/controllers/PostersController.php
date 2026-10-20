<?php

namespace szenario\craftfirstlight\console\controllers;

use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use szenario\craftfirstlight\FirstLight;
use szenario\craftfirstlight\services\Posters;
use Throwable;
use yii\console\ExitCode;

/**
 * Generates video posters and checks whether the server can make them.
 *
 * @author szenario
 * @since 1.0.0
 */
class PostersController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Regenerate posters for every video, replacing posters editors picked.
     */
    public bool $force = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'generate') {
            $options[] = 'force';
        }

        return $options;
    }

    /**
     * Generates posters for videos that have none yet.
     *
     * @return int
     *
     * @author szenario
     * @since 1.0.0
     */
    public function actionGenerate(): int
    {
        $posters = FirstLight::getInstance()->getPosters();
        $done = 0;
        $failed = 0;

        foreach ($posters->eachVideo() as $video) {
            /** @var Asset $video */
            if (!$posters->getPosterHandle($video)) {
                continue;
            }
            if (!$this->force && !$posters->needsPoster($video)) {
                continue;
            }

            $this->stdout("$video->filename ... ");
            try {
                $posters->extract($video);
                $done++;
                $this->stdout("done\n", Console::FG_GREEN);
            } catch (Throwable $e) {
                $failed++;
                $this->stdout("failed\n", Console::FG_RED);
                $this->stderr($e->getMessage() . "\n");
            }
        }

        $this->stdout("$done posters generated, $failed failed.\n");

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Prints whether the server can make posters. Exits non-zero on errors, not on warnings.
     *
     * @return int
     *
     * @author szenario
     * @since 1.0.0
     */
    public function actionStatus(): int
    {
        $hasError = false;

        foreach (FirstLight::getInstance()->getPosters()->getStatus() as $row) {
            [$tag, $color] = match (true) {
                $row['ok'] => ['ok', Console::FG_GREEN],
                $row['level'] === Posters::LEVEL_ERROR => ['error', Console::FG_RED],
                $row['level'] === Posters::LEVEL_WARNING => ['warning', Console::FG_YELLOW],
                default => ['info', Console::FG_CYAN],
            };
            $hasError = $hasError || (!$row['ok'] && $row['level'] === Posters::LEVEL_ERROR);

            $this->stdout(str_pad("[$tag]", 10), $color);
            $this->stdout("{$row['label']}: {$row['detail']}\n");
        }

        return $hasError ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
