<?php

namespace szenario\craftfirstlight\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use szenario\craftfirstlight\FirstLight;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Backs the Regenerate poster item in a video's action menu.
 *
 * @author szenario
 * @since 1.0.0
 */
class PostersController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Queues a forced poster job for one video.
     *
     * @return Response|null
     * @throws BadRequestHttpException if the request isn't a POST or lacks an element ID
     * @throws ForbiddenHttpException if the user can't save the video
     *
     * @author szenario
     * @since 1.0.0
     */
    public function actionRegenerate(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $video = Asset::find()
            ->id($this->request->getRequiredBodyParam('elementId'))
            ->status(null)
            ->one();
        $posters = FirstLight::getInstance()->getPosters();

        if (!$video || !$posters->canRegenerate($video, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('User not authorized to regenerate this poster.');
        }

        $posters->queue($video, force: true);

        return $this->asSuccess(Craft::t('firstlight', 'Poster queued.'));
    }
}
