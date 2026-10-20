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
use craft\fields\Assets as AssetsField;
use craft\services\Assets;
use craft\services\Elements;
use craft\services\ElementSources;
use craft\services\Plugins;
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
