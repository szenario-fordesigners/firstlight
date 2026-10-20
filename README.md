<br />
<div align="center"><strong>firstlight</strong></div>
<br />
<div align="center"><img src="src/icon.svg" width="120" alt="firstlight icon" /></div>

<br />
<div align="center"><strong>Automatic poster images for video assets in Craft CMS.</strong></div>

<br />
<div align="center">
  <sub>Made possible by</sub>
  <sub><br />
  <a href="https://www.szenario-design.com/" target="_blank">
    <img src="images/szenario-logo.svg" style="width:140px;" alt="szenario-design.com logo" /></a>
  </sub><br /><br />
  <sub>The team behind the magic</sub><br />
  <sub><a href="https://twitter.com/smonist">Simon Wesp</a></sub>
</div>

<br />

## Features ✨

- Automatic poster when a video is uploaded, generated in the background by a queue job
- Posters are normal image assets, so Craft transforms output AVIF, WebP and any size you need
- Smart frame choice that skips black fade-ins, or the first frame to match autoplaying videos
- HDR tone mapping, so iPhone clips don't come out washed out
- Editors can swap the poster for any library image, or bring the generated one back with **Regenerate poster**
- Status panel and CLI check that tell you whether the server can actually make posters
- CLI commands for filling in posters for existing videos

## Requirements 📋

- Craft CMS `5.3.0+`
- PHP `8.2+`
- `ffmpeg` and `ffprobe` on the server
- Imagick or GD built with AVIF and WebP support
- PHP's `proc_open` enabled
- Reliable Craft queue runner for production (daemon-based)

> firstlight uses Craft's queue system to run ffmpeg. For production, avoid relying on browser-triggered queue execution: PHP's time limit can kill long jobs.  
> Craft queue docs: https://craftcms.com/docs/5.x/system/queue.html#queue-runners

## Installation 📦

Install from the Craft Plugin Store or via Composer.

### Plugin Store

[Install firstlight from the Craft Plugin Store](https://plugins.craftcms.com/firstlight)

### Composer

```bash
# from your Craft project root
composer require szenario/craft-firstlight && php craft plugin/install firstlight
```

Installing creates a hidden **firstlight posters** volume at `@webroot/firstlight-posters` and an Assets field called **Poster**. Uninstalling leaves the field, the volume and all posters in place.

## Quickstart 🚀

1. Add the **Poster** field to the field layout of each volume that holds videos (`Settings -> Assets -> your volume -> Field Layout`). firstlight never edits field layouts itself.
2. Check that the server can make posters:

```bash
php craft firstlight/posters/status
```

3. Fill in posters for videos uploaded before the plugin was installed:

```bash
php craft firstlight/posters/generate
```

4. Make sure your queue runner is active, so new uploads get their posters:

```bash
php craft queue/listen --verbose
```

5. Use the poster in your templates:

```twig
{% set poster = video.poster.eagerly().one() %}
<video
  controls
  preload="none"
  src="{{ video.url }}"
  {% if poster %}poster="{{ poster.getUrl({ width: 1280, format: 'webp' }) }}"{% endif %}>
</video>
```

## Configuration 🛠️

firstlight settings are available in the Craft control panel, under a status panel that shows what's missing and how to fix it:

- **ffmpeg path**: path to the ffmpeg binary, `ffmpeg` by default. Can be an environment variable like `$FFMPEG_PATH`. `ffprobe` must sit in the same directory
- **Poster field**: the Assets field that holds each video's poster
- **Poster frame**: `representative` (default) picks a typical frame and skips fade-ins. `first` uses the first frame, so the poster matches what an autoplaying video shows when it starts. Changing it only affects new posters; run `php craft firstlight/posters/generate --force` to update existing ones

All settings can be overridden in `config/firstlight.php`.

## Templates

The poster is a related asset, so templates use stock Craft. `.eagerly()` loads the posters for a whole list of videos in one query, so the same code works on single and listing pages.

The `poster` attribute takes a single URL, so the browser can't pick a format. WebP works in every current browser, which makes it the safe default. Want AVIF on top? Lay a `<picture>` over the player and hide it on play. That needs a few lines of JavaScript, and saves only a few KB per poster.

## Console Commands

firstlight includes CLI commands for batch operations and deploy checks:

```bash
# show available commands
php craft help firstlight/posters

# generate posters for videos that have none yet
php craft firstlight/posters/generate

# regenerate every video's poster (replaces posters editors picked)
php craft firstlight/posters/generate --force

# check whether the server can make posters
php craft firstlight/posters/status
```

`firstlight/posters/status` runs the same checks as the status panel and exits non-zero on errors (not on warnings), so a deploy script can catch a server without ffmpeg.

## Server Setup

Installing ffmpeg:

```bash
# Debian or Ubuntu
apt-get install ffmpeg

# macOS
brew install ffmpeg
```

On DDEV, add `webimage_extra_packages: [ffmpeg]` to `.ddev/config.yaml` and run `ddev restart`.

## Support

- Issues: https://github.com/szenario-fordesigners/firstlight/issues
- Source: https://github.com/szenario-fordesigners/firstlight
- Developer: https://www.szenario-design.com/
