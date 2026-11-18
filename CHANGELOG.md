# Release Notes for firstlight

## 1.2.0 - 2026-11-18

### Added
- Video assets now get their width and height saved, read with ffprobe in the same queue job that makes the poster. Rotated phone videos get their display dimensions. Videos without the Poster field are measured too.
- `firstlight/posters/generate` now also fills in missing dimensions for existing videos.

## 1.1.0 - 2026-11-11

### Added
- Video thumbnails in the control panel now show a play badge, so videos can be told apart from images at a glance.

## 1.0.0 - 2026-10-20
- Initial release
