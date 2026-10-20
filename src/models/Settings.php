<?php

namespace szenario\craftfirstlight\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * firstlight settings.
 *
 * @author szenario
 * @since 1.0.0
 */
class Settings extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * Poster frame modes.
     */
    public const POSTER_FRAME_REPRESENTATIVE = 'representative';
    public const POSTER_FRAME_FIRST = 'first';

    // Public Properties
    // =========================================================================

    /**
     * @var string Which frame becomes the poster: the most representative one, or the first.
     */
    public string $posterFrame = self::POSTER_FRAME_REPRESENTATIVE;

    /**
     * @var string Path to the ffmpeg binary. Accepts an env var like `$FFMPEG_PATH`.
     * ffprobe is expected next to it.
     */
    public string $ffmpegPath = 'ffmpeg';

    /**
     * @var string|null UID of the Assets field that holds each video's poster.
     */
    public ?string $posterFieldUid = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the ffmpeg path with env vars and aliases resolved.
     *
     * @return string
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getFfmpegPath(): string
    {
        return App::parseEnv($this->ffmpegPath);
    }

    /**
     * Returns the ffprobe path, derived from the ffmpeg path.
     *
     * @return string
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getFfprobePath(): string
    {
        // ponytail: ffprobe always ships beside ffmpeg, so no separate setting
        $ffmpeg = $this->getFfmpegPath();
        $dir = dirname($ffmpeg);

        return $dir === '.' && !str_starts_with($ffmpeg, '.') ? 'ffprobe' : $dir . '/ffprobe';
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['ffmpegPath', 'posterFrame'], 'required'];
        $rules[] = [['posterFrame'], 'in', 'range' => [self::POSTER_FRAME_REPRESENTATIVE, self::POSTER_FRAME_FIRST]];

        return $rules;
    }
}
