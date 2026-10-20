<?php

namespace szenario\craftfirstlight\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use szenario\craftfirstlight\FirstLight;

/**
 * Extracts a video's poster frame.
 *
 * Not retryable: a corrupt or unsupported video fails the same way every time.
 * The next save of the video tries again.
 *
 * @author szenario
 * @since 1.0.0
 */
class GeneratePoster extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The video asset ID.
     */
    public int $videoId;

    /**
     * @var bool Regenerate even if the video already has a poster.
     */
    public bool $force = false;

    /**
     * @var bool Link the poster in the video's poster field.
     */
    public bool $link = true;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $video = Asset::find()->id($this->videoId)->status(null)->one();
        if (!$video) {
            return;
        }

        $posters = FirstLight::getInstance()->getPosters();

        // The editor may have picked a poster since the job was queued.
        if (!$this->force && !$posters->needsPoster($video)) {
            return;
        }

        $posters->extract($video, $this->link);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('firstlight', 'Generating video poster');
    }
}
