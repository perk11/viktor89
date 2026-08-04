<?php

namespace Perk11\Viktor89\VideoGeneration;

/**
 * Everything a VideoPromptPreprocessor needs to rewrite a short user idea into
 * a fully-formed, model-specific video prompt: the idea itself, any concrete
 * frame anchors (first / last frame), extra reference frames, and any audio
 * signals (a synchronized track copied 1:1 and/or reference-only audio).
 *
 * The task structure (T2VA / I2VA / L2VA / FL2VA / full-reference) is NOT
 * chosen by the caller — effectiveMode() derives it deterministically from
 * which fields are set, matching the MiniMax-H3 guide's task taxonomy.
 */
class VideoGenerationPrompt
{
    /**
     * @param string      $userPrompt       The user's short idea.
     * @param string|null $firstFrame       Raw bytes of the actual opening frame (keyframe anchor for [Shot 1]).
     * @param string|null $lastFrame        Raw bytes of the actual final frame (keyframe anchor for the last shot).
     * @param list<string> $referenceImages Extra reference frames (shot-planning anchors beyond first/last).
     * @param string|null $audioTrack       Raw bytes of a synchronized audio track reused 1:1.
     * @param list<string> $referenceAudios Reference-only audio signals (style/timbre referenced, not copied).
     * @param int         $durationSeconds  Hard cap on the generated video length.
     */
    public function __construct(
        public readonly string $userPrompt,
        public readonly ?string $firstFrame = null,
        public readonly ?string $lastFrame = null,
        public readonly array $referenceImages = [],
        public readonly ?string $audioTrack = null,
        public readonly array $referenceAudios = [],
        public readonly int $durationSeconds = 15,
    ) {
    }

    /**
     * The single most concrete visual anchor to attach for the vision model:
     * the first frame, else the last frame, else the first reference frame.
     * Null when no image is supplied at all.
     */
    public function primaryImage(): ?string
    {
        return $this->firstFrame
            ?? $this->lastFrame
            ?? ($this->referenceImages[0] ?? null);
    }

    public function hasAnyImage(): bool
    {
        return $this->firstFrame !== null
            || $this->lastFrame !== null
            || $this->referenceImages !== [];
    }

    public function hasAnyAudio(): bool
    {
        return $this->audioTrack !== null || $this->referenceAudios !== [];
    }

    /**
     * Derives the MiniMax-H3 task structure from which fields are set:
     *  - any audio, or extra reference frames -> full-reference mode
     *  - both first and last frame            -> first-and-last-frame (FL2VA)
     *  - only a first frame                   -> first-frame (I2VA)
     *  - only a last frame                    -> last-frame (L2VA)
     *  - nothing                              -> text-to-video (T2VA)
     */
    public function effectiveMode(): VideoTaskMode
    {
        if ($this->hasAnyAudio() || $this->referenceImages !== []) {
            return VideoTaskMode::FullReference;
        }
        if ($this->firstFrame !== null && $this->lastFrame !== null) {
            return VideoTaskMode::FirstLastFrame;
        }
        if ($this->firstFrame !== null) {
            return VideoTaskMode::FirstFrame;
        }
        if ($this->lastFrame !== null) {
            return VideoTaskMode::LastFrame;
        }

        return VideoTaskMode::TextToVideo;
    }
}
