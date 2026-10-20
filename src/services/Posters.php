<?php

namespace szenario\craftfirstlight\services;

use Craft;
use craft\base\Component;
use craft\base\Field;
use craft\db\QueryAbortedException;
use craft\elements\Asset;
use craft\elements\User;
use craft\errors\FsException;
use craft\fields\Assets as AssetsField;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\models\Volume;
use Generator;
use Symfony\Component\Process\Process;
use szenario\craftfirstlight\FirstLight;
use szenario\craftfirstlight\jobs\GeneratePoster;
use szenario\craftfirstlight\models\Settings;
use Throwable;
use yii\base\Exception;

/**
 * Extracts poster frames from video assets and keeps them in the sidecar volume.
 *
 * @author szenario
 * @since 1.0.0
 */
class Posters extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * Status check levels.
     */
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';

    /**
     * Time limits, in seconds.
     */
    public const FFMPEG_TIMEOUT = 600;
    public const JOB_TTR = 900;

    /**
     * Longest side of the stored master, in pixels (applied to the short side).
     */
    public const MAX_SHORT_SIDE = 1440;

    /**
     * Where the frame search starts, in seconds. Skips most black fade-ins.
     */
    public const SEEK_SECONDS = 1;

    /**
     * Handles used when creating the sidecar volume and poster field on install.
     */
    public const SIDECAR_HANDLE = 'firstlightPosters';
    public const FIELD_HANDLE = 'poster';

    /**
     * Project config path holding the sidecar volume's UID. Kept out of the plugin settings,
     * since it isn't user-editable and Craft's settings form would drop it on save.
     */
    public const CONFIG_SIDECAR_VOLUME = 'firstlight.sidecarVolume';

    // Public Methods
    // =========================================================================

    /**
     * Returns the sidecar volume's UID, as stored in project config on install.
     *
     * @return string|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getSidecarVolumeUid(): ?string
    {
        return Craft::$app->getProjectConfig()->get(self::CONFIG_SIDECAR_VOLUME) ?: null;
    }

    /**
     * Returns the sidecar volume, if it exists.
     *
     * @return Volume|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getSidecarVolume(): ?Volume
    {
        $uid = $this->getSidecarVolumeUid();

        return $uid ? Craft::$app->getVolumes()->getVolumeByUid($uid) : null;
    }

    /**
     * Returns the poster field, if it exists.
     *
     * @return AssetsField|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getPosterField(): ?AssetsField
    {
        $uid = FirstLight::getInstance()->getSettings()->posterFieldUid;
        $field = $uid ? Craft::$app->getFields()->getFieldByUid($uid) : null;

        return $field instanceof AssetsField ? $field : null;
    }

    /**
     * Returns whether the asset is a video firstlight makes posters for.
     *
     * @param Asset $asset
     * @return bool
     *
     * @author szenario
     * @since 1.0.0
     */
    public function isVideo(Asset $asset): bool
    {
        if ($asset->kind !== Asset::KIND_VIDEO) {
            return false;
        }

        return $asset->getVolume()->uid !== $this->getSidecarVolumeUid();
    }

    /**
     * Returns the poster field's handle in the video's field layout, or null if the layout lacks the field.
     *
     * @param Asset $video
     * @return string|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getPosterHandle(Asset $video): ?string
    {
        $field = $this->getPosterField();
        if (!$field) {
            return null;
        }

        // Layouts can override the handle, so ask the layout, not the global field.
        return $video->getFieldLayout()?->getFieldById($field->id)?->handle;
    }

    /**
     * Returns the poster linked in the video's poster field (generated or picked by an editor).
     *
     * @param Asset $video
     * @return Asset|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getLinkedPoster(Asset $video): ?Asset
    {
        $handle = $this->getPosterHandle($video);
        if (!$handle) {
            return null;
        }

        /** @var Asset|null */
        return $video->getFieldValue($handle)->one();
    }

    /**
     * Returns the generated poster for a video, found by its `{videoUid}.png` filename in the sidecar volume.
     *
     * @param Asset $video
     * @param bool|null $trashed `false` for live posters, `true` for trashed ones, `null` for either
     * @return Asset|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getGeneratedPoster(Asset $video, ?bool $trashed = false): ?Asset
    {
        $volume = $this->getSidecarVolume();
        if (!$volume) {
            return null;
        }

        return Asset::find()
            ->volumeId($volume->id)
            ->filename($this->_posterFilename($video))
            ->status(null)
            ->trashed($trashed)
            ->one();
    }

    /**
     * Returns whether a video should get a poster on save: it has the poster field, the field is empty,
     * and no generated poster exists yet (an existing one next to an empty field means the editor unlinked it).
     *
     * @param Asset $video
     * @return bool
     *
     * @author szenario
     * @since 1.0.0
     */
    public function needsPoster(Asset $video): bool
    {
        if (!$this->isVideo($video) || !$this->getSidecarVolume()) {
            return false;
        }

        $handle = $this->getPosterHandle($video);
        if (!$handle) {
            return false;
        }

        if ($video->getFieldValue($handle)->exists()) {
            return false;
        }

        return $this->getGeneratedPoster($video, null) === null;
    }

    /**
     * Returns whether the user may regenerate the video's poster.
     *
     * @param Asset $asset
     * @param User|null $user
     * @return bool
     *
     * @author szenario
     * @since 1.0.0
     */
    public function canRegenerate(Asset $asset, ?User $user = null): bool
    {
        return $this->isVideo($asset) && Craft::$app->getElements()->canSave($asset, $user);
    }

    /**
     * Queues a poster job for a video.
     *
     * @param Asset $video
     * @param bool $force Regenerate even if the video already has a poster
     * @param bool $link Link the poster in the video's poster field
     *
     * @author szenario
     * @since 1.0.0
     */
    public function queue(Asset $video, bool $force = false, bool $link = true): void
    {
        Queue::push(new GeneratePoster([
            'videoId' => $video->id,
            'force' => $force,
            'link' => $link,
        ]), ttr: self::JOB_TTR);
    }

    /**
     * Extracts a poster frame from the video and saves it as `{videoUid}.png` in the sidecar volume,
     * replacing the file in place if the poster exists already.
     *
     * @param Asset $video
     * @param bool $link Whether to link the poster in the video's poster field
     * @return Asset The poster
     * @throws Exception if the sidecar volume is missing, ffmpeg fails, or the poster can't be saved
     * @throws Throwable from saving elements
     *
     * @author szenario
     * @since 1.0.0
     */
    public function extract(Asset $video, bool $link = true): Asset
    {
        $volume = $this->getSidecarVolume();
        if (!$volume) {
            throw new Exception('The firstlight posters volume is missing. Reinstall firstlight to recreate it.');
        }

        $tempVideo = $video->getCopyOfFile();
        $tempPng = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'firstlight-' . $video->uid . '-' . uniqid() . '.png';

        try {
            $this->_extractFrame($tempVideo, $tempPng);
            $poster = $this->_savePoster($video, $volume, $tempPng);
        } finally {
            FileHelper::unlink($tempVideo);
            FileHelper::unlink($tempPng);
        }

        if ($link) {
            $handle = $this->getPosterHandle($video);
            if ($handle) {
                // The field isn't empty after this, so the save hook won't queue another job.
                $video->setFieldValue($handle, [$poster->id]);
                if (!Craft::$app->getElements()->saveElement($video, false)) {
                    throw new Exception("Couldn't link the poster to video $video->id.");
                }
            }
        }

        return $poster;
    }

    /**
     * Trashes or deletes the video's generated poster along with the video, copying its delete flags.
     *
     * @param Asset $video
     * @throws Throwable
     *
     * @author szenario
     * @since 1.0.0
     */
    public function deleteWith(Asset $video): void
    {
        // A hard delete can come after a soft one, when the poster is already in the trash.
        $poster = $this->getGeneratedPoster($video, $video->hardDelete ? null : false);
        if (!$poster) {
            return;
        }

        $poster->keepFileOnDelete = $video->keepFileOnDelete;
        Craft::$app->getElements()->deleteElement($poster, $video->hardDelete);
    }

    /**
     * Restores the video's trashed generated poster.
     *
     * @param Asset $video
     * @throws Throwable
     *
     * @author szenario
     * @since 1.0.0
     */
    public function restoreWith(Asset $video): void
    {
        $poster = $this->getGeneratedPoster($video, true);
        if ($poster) {
            Craft::$app->getElements()->restoreElement($poster);
        }
    }

    /**
     * Iterates over all video assets firstlight handles, in batches.
     *
     * @return Generator<int, Asset>
     *
     * @author szenario
     * @since 1.0.0
     */
    public function eachVideo(): Generator
    {
        $query = Asset::find()->kind(Asset::KIND_VIDEO)->status(null);
        $volume = $this->getSidecarVolume();
        if ($volume) {
            $query->volumeId(['not', $volume->id]);
        }

        try {
            foreach ($query->each() as $video) {
                yield $video;
            }
        } catch (QueryAbortedException) {
            // No volume besides the sidecar, so no videos.
        }
    }

    /**
     * Counts videos with the poster field, an empty field and no generated poster.
     *
     * @return int
     *
     * @author szenario
     * @since 1.0.0
     */
    public function countMissing(): int
    {
        $count = 0;
        foreach ($this->eachVideo() as $video) {
            /** @var Asset $video */
            // ponytail: one query per video, fine for the settings page; batch it if libraries reach tens of thousands
            if ($this->needsPoster($video)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Returns whether this server can run processes at all.
     *
     * @return bool
     *
     * @author szenario
     * @since 1.0.0
     */
    public function canRunProcesses(): bool
    {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

        return function_exists('proc_open') && !in_array('proc_open', $disabled, true);
    }

    /**
     * Returns the status rows shown on the settings page and by `firstlight/posters/status`.
     *
     * Each row has `label`, `level` (error, warning or info), `ok` and `detail`.
     *
     * @return array<int, array{label: string, level: string, ok: bool, detail: string}>
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getStatus(): array
    {
        $settings = FirstLight::getInstance()->getSettings();
        $rows = [];

        $canRun = $this->canRunProcesses();
        $rows[] = $this->_row('Can run processes', self::LEVEL_ERROR, $canRun, $canRun
            ? 'proc_open is available.'
            : 'proc_open is missing or listed in disable_functions. Enable it in php.ini.');

        $ffmpegVersion = $canRun ? $this->_version($settings->getFfmpegPath()) : null;
        $ffprobeVersion = $canRun ? $this->_version($settings->getFfprobePath()) : null;
        $ffmpegOk = $ffmpegVersion !== null && $ffprobeVersion !== null;
        $rows[] = $this->_row('ffmpeg runs', self::LEVEL_ERROR, $ffmpegOk, $ffmpegOk
            ? $ffmpegVersion
            : sprintf('Could not run %s. Install ffmpeg (with ffprobe) on the server, or fix the ffmpeg path below.', $ffmpegVersion === null ? $settings->getFfmpegPath() : $settings->getFfprobePath()));

        $volume = $this->getSidecarVolume();
        $volumeOk = $volume !== null && $volume->getFs()->hasUrls;
        $rows[] = $this->_row('Sidecar volume', self::LEVEL_ERROR, $volumeOk, match (true) {
            $volume === null => 'The firstlight posters volume is missing. Reinstall firstlight to recreate it.',
            !$volumeOk => "The filesystem of \"$volume->name\" has no public URLs. Turn them on in its settings.",
            default => "Posters are saved to \"$volume->name\".",
        });

        $field = $this->getPosterField();
        $missingIn = $field ? $this->_volumesMissingField($field) : [];
        $rows[] = $this->_row('Poster field', self::LEVEL_ERROR, $field !== null && !$missingIn, match (true) {
            $field === null => 'No poster field. Pick an Assets field below.',
            (bool)$missingIn => sprintf('Add the "%s" field to the field layout of: %s.', $field->name, implode(', ', $missingIn)),
            default => "Using the \"$field->name\" field.",
        });

        $images = Craft::$app->getImages();
        $avif = $images->getSupportsAvif();
        $rows[] = $this->_row('AVIF transforms', self::LEVEL_WARNING, $avif, $avif
            ? 'Craft can output AVIF.'
            : 'Imagick or GD is built without AVIF. Rebuild it with AVIF support.');
        $webp = $images->getSupportsWebP();
        $rows[] = $this->_row('WebP transforms', self::LEVEL_WARNING, $webp, $webp
            ? 'Craft can output WebP.'
            : 'Imagick or GD is built without WebP. Rebuild it with WebP support.');

        if ($ffmpegOk) {
            $toneMap = $this->_hasToneMapping();
            $rows[] = $this->_row('HDR tone mapping', self::LEVEL_WARNING, $toneMap, $toneMap
                ? 'ffmpeg has zscale and tonemap.'
                : 'ffmpeg is built without zimg (zscale), so HDR posters come out dull. Install an ffmpeg build with zimg.');
        }

        $queueOk = !Craft::$app->getConfig()->getGeneral()->runQueueAutomatically;
        $rows[] = $this->_row('Queue runner', self::LEVEL_WARNING, $queueOk, $queueOk
            ? 'Jobs run outside web requests.'
            : 'runQueueAutomatically is on, so jobs run in web requests where PHP time limits can kill ffmpeg. Run craft queue/listen as a daemon and turn it off.');

        $missing = $field && $volume ? $this->countMissing() : 0;
        $rows[] = $this->_row('Videos without a poster', self::LEVEL_INFO, $missing === 0, $missing === 0
            ? 'Every video with the poster field has a poster.'
            : "$missing videos have no poster. Run: craft firstlight/posters/generate");

        return $rows;
    }

    /**
     * Creates the sidecar volume and the poster field, reusing existing ones,
     * and stores their UIDs in project config and the plugin settings.
     *
     * @throws Exception if either can't be saved
     * @throws Throwable from saving project config
     *
     * @author szenario
     * @since 1.0.0
     */
    public function install(): void
    {
        $volume = $this->_createSidecarVolume();
        $field = $this->_createPosterField($volume);

        // Uninstall leaves this key, so a reinstall finds the same volume even if its handle changed.
        Craft::$app->getProjectConfig()->set(self::CONFIG_SIDECAR_VOLUME, $volume->uid, 'Set the firstlight sidecar volume');
        Craft::$app->getPlugins()->savePluginSettings(FirstLight::getInstance(), [
            'posterFieldUid' => $field->uid,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the generated poster's filename for a video.
     *
     * @param Asset $video
     * @return string
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _posterFilename(Asset $video): string
    {
        return "$video->uid.png";
    }

    /**
     * Runs ffmpeg to write one frame of the video to a PNG.
     *
     * @param string $videoPath
     * @param string $pngPath
     * @throws Exception if no frame comes out
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _extractFrame(string $videoPath, string $pngPath): void
    {
        $max = self::MAX_SHORT_SIDE;
        $size = "w='if(gte(iw,ih),-2,min(iw,$max))':h='if(gte(iw,ih),min(ih,$max),-2)'";
        $filter = "scale=$size";

        if ($this->_isHdr($videoPath)) {
            if ($this->_hasToneMapping()) {
                // Downscale in the first step, so the float conversion runs on the small frame (about half the memory at 4K).
                $filter = "zscale=$size:t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format=yuv420p";
            } else {
                Craft::warning("ffmpeg lacks zscale, so the HDR poster for $videoPath is not tone-mapped.", __METHOD__);
            }
        }

        $seek = FirstLight::getInstance()->getSettings()->posterFrame === Settings::POSTER_FRAME_FIRST
            ? '0'
            : $this->_pickFrameTime($videoPath);

        $process = $this->_ffmpeg([
            '-v', 'error',
            '-ss', $seek,
            '-i', $videoPath,
            '-vf', $filter,
            '-frames:v', '1',
            '-pix_fmt', 'rgb24',
            '-y', $pngPath,
        ]);

        if (!$this->_hasOutput($pngPath)) {
            throw new Exception(sprintf(
                "ffmpeg couldn't extract a frame (exit code %s): %s Install ffmpeg on the server, or fix the ffmpeg path in the firstlight settings.",
                $process->getExitCode(),
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ));
        }
    }

    /**
     * Picks the most representative of the 50 frames after SEEK_SECONDS and returns its time in seconds,
     * or `0` (the first frame) if the video is shorter than that.
     *
     * The frames are compared as small copies, since the thumbnail filter holds all 50 in memory.
     *
     * @param string $videoPath
     * @return string
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _pickFrameTime(string $videoPath): string
    {
        // showinfo logs the chosen frame's timestamp, counted from the seek point.
        $process = $this->_ffmpeg([
            '-v', 'info',
            '-ss', (string)self::SEEK_SECONDS,
            '-i', $videoPath,
            '-vf', 'scale=-2:240,thumbnail=50,showinfo',
            '-frames:v', '1',
            '-an',
            '-f', 'null', '-',
        ]);

        if (!preg_match('/pts_time:([\d.]+)/', $process->getErrorOutput(), $match)) {
            return '0';
        }

        return (string)(self::SEEK_SECONDS + (float)$match[1]);
    }

    /**
     * Runs ffmpeg with the given arguments.
     *
     * @param string[] $args
     * @return Process
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _ffmpeg(array $args): Process
    {
        $process = new Process([
            FirstLight::getInstance()->getSettings()->getFfmpegPath(),
            '-hide_banner',
            '-nostats',
            // ponytail: 2 decoder threads keep a 4K poster around 0.2 GB (HDR 0.5 GB); raise it if posters get slow on big servers
            '-threads', '2',
            '-filter_threads', '1',
            ...$args,
        ], timeout: self::FFMPEG_TIMEOUT);
        $process->run();

        return $process;
    }

    /**
     * Returns whether ffmpeg wrote a non-empty file.
     *
     * @param string $path
     * @return bool
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _hasOutput(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Returns whether the video's first stream uses an HDR transfer (HDR10 or HLG).
     *
     * @param string $videoPath
     * @return bool
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _isHdr(string $videoPath): bool
    {
        $process = new Process([
            FirstLight::getInstance()->getSettings()->getFfprobePath(),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=color_transfer',
            '-of', 'json',
            $videoPath,
        ], timeout: 60);
        $process->run();

        if (!$process->isSuccessful()) {
            Craft::warning("ffprobe failed, treating $videoPath as SDR: " . $process->getErrorOutput(), __METHOD__);
            return false;
        }

        $transfer = Json::decodeIfJson($process->getOutput())['streams'][0]['color_transfer'] ?? null;

        return in_array($transfer, ['smpte2084', 'arib-std-b67'], true);
    }

    /**
     * Returns whether ffmpeg has the zscale and tonemap filters.
     *
     * @return bool
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _hasToneMapping(): bool
    {
        $process = new Process([FirstLight::getInstance()->getSettings()->getFfmpegPath(), '-hide_banner', '-filters'], timeout: 5);
        $process->run();
        $out = $process->getOutput();

        return preg_match('/\szscale\s/', $out) && preg_match('/\stonemap\s/', $out);
    }

    /**
     * Returns the first line of `<binary> -version`, or null if it doesn't run.
     *
     * @param string $binary
     * @return string|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _version(string $binary): ?string
    {
        try {
            $process = new Process([$binary, '-version'], timeout: 5);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        return strtok($process->getOutput(), "\n") ?: null;
    }

    /**
     * Returns the names of volumes that hold videos but whose field layout lacks the poster field.
     *
     * @param AssetsField $field
     * @return string[]
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _volumesMissingField(AssetsField $field): array
    {
        $sidecarUid = $this->getSidecarVolumeUid();
        $names = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($volume->uid === $sidecarUid || $volume->getFieldLayout()->getFieldById($field->id)) {
                continue;
            }

            if (Asset::find()->volumeId($volume->id)->kind(Asset::KIND_VIDEO)->status(null)->exists()) {
                $names[] = $volume->name;
            }
        }

        return $names;
    }

    /**
     * Saves the PNG as the video's generated poster, replacing the file if the poster exists.
     *
     * @param Asset $video
     * @param Volume $volume
     * @param string $pngPath
     * @return Asset
     * @throws Exception if the poster can't be saved
     * @throws Throwable from saving the element
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _savePoster(Asset $video, Volume $volume, string $pngPath): Asset
    {
        $filename = $this->_posterFilename($video);
        $existing = $this->getGeneratedPoster($video);

        if ($existing) {
            // Keeps the asset ID, alt text and focal point.
            Craft::$app->getAssets()->replaceAssetFile($existing, $pngPath, $filename, 'image/png');
            return $existing;
        }

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        $poster = new Asset([
            'tempFilePath' => $pngPath,
            'filename' => $filename,
            'newFolderId' => $folder->id,
            'volumeId' => $volume->id,
            'title' => $video->title,
        ]);
        $poster->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($poster)) {
            throw new Exception("Couldn't save the poster for video $video->id: " . implode(' ', $poster->getFirstErrors()));
        }

        return $poster;
    }

    /**
     * Returns the sidecar volume from a previous install, or the one with the default handle,
     * creating it and its local filesystem if neither exists.
     *
     * @return Volume
     * @throws Exception if the filesystem or volume can't be saved
     * @throws FsException
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _createSidecarVolume(): Volume
    {
        $volumes = Craft::$app->getVolumes();
        $volume = $this->getSidecarVolume() ?? $volumes->getVolumeByHandle(self::SIDECAR_HANDLE);
        if ($volume) {
            return $volume;
        }

        $fsService = Craft::$app->getFs();
        if (!$fsService->getFilesystemByHandle(self::SIDECAR_HANDLE)) {
            $fs = $fsService->createFilesystem([
                'type' => Local::class,
                'name' => 'firstlight posters',
                'handle' => self::SIDECAR_HANDLE,
                'hasUrls' => true,
                'url' => '@web/firstlight-posters',
                'path' => '@webroot/firstlight-posters',
            ]);
            if (!$fsService->saveFilesystem($fs)) {
                throw new Exception("Couldn't create the firstlight filesystem: " . implode(' ', $fs->getFirstErrors()));
            }
        }

        $volume = new Volume([
            'name' => 'firstlight posters',
            'handle' => self::SIDECAR_HANDLE,
            'fsHandle' => self::SIDECAR_HANDLE,
        ]);
        if (!$volumes->saveVolume($volume)) {
            throw new Exception("Couldn't create the firstlight volume: " . implode(' ', $volume->getFirstErrors()));
        }

        return $volume;
    }

    /**
     * Returns the poster field with the default handle, creating it if needed.
     *
     * @param Volume $sidecar
     * @return AssetsField
     * @throws Exception if the handle is taken by another field type, or the field can't be saved
     * @throws Throwable from saving the field
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _createPosterField(Volume $sidecar): AssetsField
    {
        $fields = Craft::$app->getFields();
        $existing = $fields->getFieldByHandle(self::FIELD_HANDLE);
        if ($existing instanceof AssetsField) {
            return $existing;
        }
        if ($existing) {
            throw new Exception(sprintf('A non-Assets field already uses the handle "%s". Pick a poster field in the firstlight settings.', self::FIELD_HANDLE));
        }

        /** @var AssetsField $field */
        $field = $fields->createField([
            'type' => AssetsField::class,
            'name' => 'Poster',
            'handle' => self::FIELD_HANDLE,
            'instructions' => 'Generated from the video by firstlight. Pick another image to override it.',
            'translationMethod' => Field::TRANSLATION_METHOD_NONE,
            'restrictFiles' => true,
            'allowedKinds' => ['image'],
            'maxRelations' => 1,
            // The sidecar volume is for generated posters only, so no uploads. Craft still
            // resolves the upload location to render the field, so it points at the sidecar.
            'allowUploads' => false,
            'defaultUploadLocationSource' => "volume:$sidecar->uid",
        ]);
        if (!$fields->saveField($field)) {
            throw new Exception("Couldn't create the poster field: " . implode(' ', $field->getFirstErrors()));
        }

        return $field;
    }

    /**
     * Builds a status row.
     *
     * @param string $label
     * @param string $level
     * @param bool $ok
     * @param string $detail
     * @return array{label: string, level: string, ok: bool, detail: string}
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _row(string $label, string $level, bool $ok, string $detail): array
    {
        return [
            'label' => Craft::t('firstlight', $label),
            'level' => $level,
            'ok' => $ok,
            'detail' => $detail,
        ];
    }
}
