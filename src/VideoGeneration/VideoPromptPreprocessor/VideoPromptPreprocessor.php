<?php

namespace Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\VideoGeneration\VideoGenerationPrompt;

/**
 * Rewrites a short user idea (optionally together with one or more
 * first-frame / keyframe images and/or one or more synchronized audio tracks)
 * into a fully-formed, model-specific video prompt. Implementations encapsulate
 * one target model's prompt-writing guide (e.g. MiniMax-H3).
 *
 * Which preprocessor runs is chosen by the `preprocessor` field on the selected
 * video model's config entry (see VideoPromptPreprocessorFactory), so adding
 * support for a new model is a new implementation + a new config key.
 *
 * The interface deliberately accepts an arbitrary number of images and audio
 * tracks so it can drive text-to-video, image-to-video and
 * audio/image/text-to-video pipelines — including multi-image / multi-audio
 * full-reference mode — from the same code path. The chosen structure and rules
 * depend on how many of each are supplied; see VideoGenerationPrompt for the
 * hasSingleImage() / hasMultipleImages() / ... helpers.
 */
interface VideoPromptPreprocessor
{
    public function preprocess(VideoGenerationPrompt $input, ProgressUpdateCallback $progressUpdateCallback): string;
}
