<?php
/*********************************************
* [video] shortcode
**********************************************
* Plays a video file hosted on this site in a
* responsive, captionable frame. Layout comes
* from the "Video" block in css/base.css, so it
* works in every theme; themes can restyle the
* .swf-video classes.
*
* Simplest use:
*   [video src="videos/trailer.mp4"]
*
* Everything:
*   [video src="videos/trailer.webm, videos/trailer.mp4"
*          poster="images/trailer.jpg"
*          ratio="16:9"
*          width="900"
*          caption="Launch trailer"
*          title="SplinterFaction launch trailer"
*          captions="videos/trailer.en.vtt"
*          start="12"
*          class="hud"]
*
* The src can also go between tags, like [rss]:
*   [video]videos/trailer.mp4[/video]
*
* Attributes
*   src          File path or URL. Comma-separate several
*                encodings (best first) and the browser
*                plays the first it supports.
*   poster       Image shown before playback.
*   ratio        Frame shape: 16:9 (default), 4:3, 21:9,
*                9:16 ... or "auto" to size to the file.
*   fit          contain (default, letterboxes) or cover
*                (fills the frame, crops the edges).
*   width        Maximum width: 640 (= px), 60%, 40rem.
*                The frame centres when narrower.
*   caption      Text shown under the video.
*   title        Accessible name for screen readers.
*                Defaults to the caption.
*   captions     WebVTT subtitle file (.vtt).
*   captionslang / captionslabel   Defaults: en / English.
*   start        Start position in seconds.
*   controls     true (default) / false
*   autoplay     true / false (default). Forces muted,
*                since browsers block autoplay with sound.
*                Paused for visitors who prefer reduced motion.
*   loop         true / false (default)
*   muted        true / false (default)
*   preload      metadata (default), auto, none
*   nodownload   true hides the browser's download button.
*   class / id   Added to the outer <figure>.
*
* Booleans accept true/false, yes/no, on/off, 1/0.
**********************************************/

// Tracks whether the reduced-motion helper has been printed on this page.
$GLOBALS['swf_video_script_printed'] = false;

function shortcode_video($attributes, $content) {
	$e = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

	$bool = function ($key, $default) use ($attributes) {
		if (!isset($attributes[$key])) { return $default; }
		return in_array(strtolower(trim($attributes[$key])), ['1', 'true', 'yes', 'on'], true);
	};

	// ---- Sources -------------------------------------------------------
	$srcList = isset($attributes['src']) ? $attributes['src'] : trim(strip_tags($content));
	$srcs = array_values(array_filter(array_map('trim', explode(',', $srcList)), 'strlen'));

	if (empty($srcs)) {
		return '<p class="swf-video-error">Video error: no src given.</p>';
	}

	$mimeTypes = [
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/mp4',
		'webm' => 'video/webm',
		'ogv'  => 'video/ogg',
		'ogg'  => 'video/ogg',
		'mov'  => 'video/quicktime',
	];

	// Media-fragment start time (#t=12) works without any JavaScript.
	$fragment = '';
	if (isset($attributes['start']) && is_numeric($attributes['start']) && $attributes['start'] > 0) {
		$fragment = '#t=' . (0 + $attributes['start']);
	}

	$sourceTags = '';
	foreach ($srcs as $src) {
		$ext  = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
		$type = isset($mimeTypes[$ext]) ? ' type="' . $mimeTypes[$ext] . '"' : '';
		$sourceTags .= '<source src="' . $e($src . $fragment) . '"' . $type . '>';
	}

	// ---- Playback flags ------------------------------------------------
	$controls = $bool('controls', true);
	$autoplay = $bool('autoplay', false);
	$loop     = $bool('loop', false);
	$muted    = $bool('muted', false) || $autoplay;

	$preload = isset($attributes['preload']) ? strtolower($attributes['preload']) : ($autoplay ? 'auto' : 'metadata');
	if (!in_array($preload, ['none', 'metadata', 'auto'], true)) { $preload = 'metadata'; }

	$videoAttrs = ['preload="' . $preload . '"', 'playsinline'];
	if ($controls) { $videoAttrs[] = 'controls'; }
	if ($autoplay) { $videoAttrs[] = 'autoplay'; $videoAttrs[] = 'data-swf-autoplay'; }
	if ($loop)     { $videoAttrs[] = 'loop'; }
	if ($muted)    { $videoAttrs[] = 'muted'; }
	if ($bool('nodownload', false)) { $videoAttrs[] = 'controlslist="nodownload"'; }

	if (!empty($attributes['poster'])) {
		$videoAttrs[] = 'poster="' . $e($attributes['poster']) . '"';
	}

	$caption = isset($attributes['caption']) ? trim($attributes['caption']) : '';
	$label   = isset($attributes['title'])   ? trim($attributes['title'])   : $caption;
	if ($label !== '') {
		$videoAttrs[] = 'aria-label="' . $e($label) . '"';
	}

	// ---- Frame shape ---------------------------------------------------
	$figureClasses = ['swf-video'];
	$frameStyle    = '';

	$ratio = isset($attributes['ratio']) ? strtolower(trim($attributes['ratio'])) : '16:9';
	if ($ratio === 'auto') {
		$figureClasses[] = 'swf-video-auto';
	} elseif (preg_match('/^(\d+(?:\.\d+)?)\s*[:x\/]\s*(\d+(?:\.\d+)?)$/', $ratio, $m) && $m[1] > 0 && $m[2] > 0) {
		$frameStyle = '--swf-video-ratio: ' . $m[1] . ' / ' . $m[2] . ';';
	}

	if (isset($attributes['fit']) && strtolower($attributes['fit']) === 'cover') {
		$figureClasses[] = 'swf-video-cover';
	}

	$figureStyle = '';
	if (isset($attributes['width'])) {
		$w = trim($attributes['width']);
		if (preg_match('/^\d+(?:\.\d+)?$/', $w)) { $w .= 'px'; }
		if (preg_match('/^\d+(?:\.\d+)?(px|%|rem|em|ch|vw)$/', $w)) {
			$figureStyle = '--swf-video-width: ' . $w . ';';
			$figureClasses[] = 'swf-video-sized';
		}
	}

	if (!empty($attributes['class'])) {
		foreach (preg_split('/\s+/', trim($attributes['class'])) as $c) {
			if (preg_match('/^[A-Za-z_][\w-]*$/', $c)) { $figureClasses[] = $c; }
		}
	}

	$idAttr = '';
	if (!empty($attributes['id']) && preg_match('/^[A-Za-z][\w-]*$/', $attributes['id'])) {
		$idAttr = ' id="' . $attributes['id'] . '"';
	}

	// ---- Subtitle track ------------------------------------------------
	$track = '';
	if (!empty($attributes['captions'])) {
		$lang  = !empty($attributes['captionslang'])  ? $attributes['captionslang']  : 'en';
		$tlab  = !empty($attributes['captionslabel']) ? $attributes['captionslabel'] : 'English';
		$track = '<track kind="captions" src="' . $e($attributes['captions']) . '" srclang="' . $e($lang) . '" label="' . $e($tlab) . '" default>';
	}

	// Shown only by browsers that can't play any of the sources.
	$fallback = '<p>Your browser can\'t play this video. <a href="' . $e($srcs[count($srcs) - 1]) . '">Download it instead</a>.</p>';

	// ---- Assemble ------------------------------------------------------
	// Kept on one line with no blank lines so the Markdown parser treats
	// the whole thing as a single HTML block on page-md layouts.
	$html  = '<figure class="' . implode(' ', $figureClasses) . '"' . $idAttr . ($figureStyle ? ' style="' . $figureStyle . '"' : '') . '>';
	$html .= '<div class="swf-video-frame"' . ($frameStyle ? ' style="' . $frameStyle . '"' : '') . '>';
	$html .= '<video ' . implode(' ', $videoAttrs) . '>' . $sourceTags . $track . $fallback . '</video>';
	$html .= '</div>';
	if ($caption !== '') {
		$html .= '<figcaption>' . $e($caption) . '</figcaption>';
	}
	$html .= '</figure>';

	// Autoplay ignores prefers-reduced-motion, so pause those videos (and
	// give them controls back) for visitors who've asked for less motion.
	if ($autoplay && !$GLOBALS['swf_video_script_printed']) {
		$GLOBALS['swf_video_script_printed'] = true;
		$html .= '<script>(function(){function f(){if(!window.matchMedia||!matchMedia("(prefers-reduced-motion: reduce)").matches)return;document.querySelectorAll("video[data-swf-autoplay]").forEach(function(v){v.removeAttribute("autoplay");v.pause();v.controls=true;});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",f);}f();})();</script>';
	}

	return $html;
}
?>
