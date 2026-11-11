<?php

namespace szenario\craftfirstlight;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\DefineMenuItemsEvent;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\events\ReplaceAssetEvent;
use craft\events\TemplateEvent;
use craft\fields\Assets as AssetsField;
use craft\services\Assets;
use craft\services\Elements;
use craft\services\ElementSources;
use craft\services\Plugins;
use craft\web\View;
use szenario\craftfirstlight\elements\actions\RegeneratePoster;
use szenario\craftfirstlight\models\Settings;
use szenario\craftfirstlight\services\Posters;
use Throwable;
use yii\base\Event;

/**
 * firstlight extracts a poster frame from every video asset with ffmpeg.
 *
 * @method static FirstLight getInstance()
 * @method Settings getSettings()
 *
 * @author szenario
 * @copyright szenario
 * @license https://craftcms.github.io/license/ Craft License
 * @since 1.0.0
 */
class FirstLight extends Plugin
{
    // Constants
    // =========================================================================

    /**
     * Play badge over video poster thumbs, keyed off the data-kind attribute Craft puts on asset
     * chips and cards. Only when the thumb is an image: videos without a poster show the file-type
     * icon, which already says "video". Child selectors keep it off chips nested inside a video's
     * card (e.g. the Poster field preview).
     */
    private const PLAY_BADGE_CSS = <<<'CSS'
        .chip[data-kind="video"] > .thumb:has(img),
        .card[data-kind="video"] > .card-main > .thumb:has(img) { position: relative; }
        .chip[data-kind="video"] > .thumb:has(img)::after,
        .card[data-kind="video"] > .card-main > .thumb:has(img)::after {
            content: '';
            position: absolute;
            inset: 0;
            margin: auto;
            width: clamp(12px, 40%, 32px);
            aspect-ratio: 1;
            border-radius: 50%;
            background: rgb(0 0 0 / .6) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='white' stroke='white' stroke-width='2' stroke-linejoin='round' d='M9.5 7.5v9l7.5-4.5z'/%3E%3C/svg%3E") center / 100% no-repeat;
            pointer-events: none;
        }
        CSS;

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'posters' => Posters::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_attachEventHandlers();
    }

    /**
     * Returns the posters service.
     *
     * @return Posters
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getPosters(): Posters
    {
        /** @var Posters */
        return $this->get('posters');
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $fieldOptions = [];
        foreach (Craft::$app->getFields()->getFieldsByType(AssetsField::class) as $field) {
            $fieldOptions[] = ['label' => $field->name, 'value' => $field->uid];
        }

        return Craft::$app->getView()->renderTemplate('firstlight/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'status' => $this->getPosters()->getStatus(),
            'fieldOptions' => $fieldOptions,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the event handlers.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _attachEventHandlers(): void
    {
        // Create the sidecar volume and poster field. This runs after Craft writes the plugin's
        // project config, which would otherwise overwrite the UIDs saved to the settings.
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, function(PluginEvent $event) {
            // On other environments the volume and field arrive through project config.
            if ($event->plugin !== $this || Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
                return;
            }

            try {
                $this->getPosters()->install();
            } catch (Throwable $e) {
                // Install still succeeds; the settings page status panel shows what's missing.
                Craft::warning('firstlight could not create its volume or field: ' . $e->getMessage(), __METHOD__);
                Craft::$app->getErrorHandler()->logException($e);
            }
        });

        // Generate a poster when a video is saved without one
        Event::on(Asset::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Asset $asset */
            $asset = $event->sender;
            if ($asset->propagating) {
                return;
            }

            $posters = $this->getPosters();
            if ($posters->needsPoster($asset)) {
                $posters->queue($asset);
            }
        });

        // Regenerate the generated poster in place when the video file is replaced
        Event::on(Assets::class, Assets::EVENT_AFTER_REPLACE_ASSET, function(ReplaceAssetEvent $event) {
            $posters = $this->getPosters();
            if ($posters->isVideo($event->asset) && $posters->getGeneratedPoster($event->asset)) {
                $posters->queue($event->asset, force: true, link: false);
            }
        });

        // The generated poster follows its video into the trash, out of it, and out for good
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event) {
            if ($event->element instanceof Asset && $this->getPosters()->isVideo($event->element)) {
                $this->getPosters()->deleteWith($event->element);
            }
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, function(ElementEvent $event) {
            if ($event->element instanceof Asset && $this->getPosters()->isVideo($event->element)) {
                $this->getPosters()->restoreWith($event->element);
            }
        });

        // Hide the sidecar volume from asset indexes and pickers
        Event::on(Asset::class, Element::EVENT_REGISTER_SOURCES, function(RegisterElementSourcesEvent $event) {
            $uid = $this->getPosters()->getSidecarVolumeUid();
            if (!$uid || $event->context === ElementSources::CONTEXT_SETTINGS) {
                return;
            }

            $event->sources = array_values(array_filter(
                $event->sources,
                fn(array $source) => ($source['key'] ?? null) !== "volume:$uid",
            ));
        });

        // Regenerate poster: asset index action
        Event::on(Asset::class, Element::EVENT_REGISTER_ACTIONS, function(RegisterElementActionsEvent $event) {
            if ($event->source !== 'volume:' . $this->getPosters()->getSidecarVolumeUid()) {
                $event->actions[] = RegeneratePoster::class;
            }
        });

        // Regenerate poster: edit screen action menu
        Event::on(Asset::class, Element::EVENT_DEFINE_ACTION_MENU_ITEMS, function(DefineMenuItemsEvent $event) {
            /** @var Asset $asset */
            $asset = $event->sender;
            if (!$this->getPosters()->canRegenerate($asset)) {
                return;
            }

            $event->items[] = [
                'icon' => 'arrows-rotate',
                'label' => Craft::t('firstlight', 'Regenerate poster'),
                'action' => 'firstlight/posters/regenerate',
                'params' => ['elementId' => $asset->id],
            ];
        });

        // Badge video poster thumbs with a play icon
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function(TemplateEvent $event) {
            if ($event->templateMode === View::TEMPLATE_MODE_CP) {
                Craft::$app->getView()->registerCss(self::PLAY_BADGE_CSS);
            }
        });

        // Show the poster as the video's CP thumbnail
        Event::on(Assets::class, Assets::EVENT_DEFINE_THUMB_URL, function(DefineAssetThumbUrlEvent $event) {
            $posters = $this->getPosters();
            if (!$posters->isVideo($event->asset)) {
                return;
            }

            $poster = $posters->getLinkedPoster($event->asset);
            if ($poster) {
                $event->url = Craft::$app->getAssets()->getThumbUrl($poster, $event->width, $event->height);
            }
        });
    }
}
