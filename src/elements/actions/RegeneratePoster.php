<?php

namespace szenario\craftfirstlight\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use szenario\craftfirstlight\FirstLight;

/**
 * Re-extracts the poster of the selected videos and links it in their poster field.
 *
 * @author szenario
 * @since 1.0.0
 */
class RegeneratePoster extends ElementAction
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('firstlight', 'Regenerate poster');
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        type: $type,
        bulk: true,
        validateSelection: (selectedItems) => {
            for (let i = 0; i < selectedItems.length; i++) {
                const element = selectedItems.eq(i).find('.element');
                if (element.data('kind') !== 'video' || !Garnish.hasAttr(element, 'data-savable')) {
                    return false;
                }
            }
            return true;
        },
    });
})();
JS, [static::class]);

        return null;
    }

    /**
     * @inheritdoc
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        $posters = FirstLight::getInstance()->getPosters();
        $user = Craft::$app->getUser()->getIdentity();
        $count = 0;

        foreach ($query->all() as $asset) {
            if ($asset instanceof Asset && $posters->canRegenerate($asset, $user)) {
                $posters->queue($asset, force: true);
                $count++;
            }
        }

        $this->setMessage(Craft::t('firstlight', '{count, plural, =1{# poster} other{# posters}} queued.', ['count' => $count]));

        return true;
    }
}
