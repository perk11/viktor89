<?php

namespace Perk11\Viktor89\VideoGeneration;

/**
 * The MiniMax-H3 task structure a VideoGenerationPrompt resolves to. It is not
 * chosen by the caller; VideoGenerationPrompt::effectiveMode() derives it
 * deterministically from which frame / reference / audio fields are set.
 */
enum VideoTaskMode: string
{
    case TextToVideo = 't2va';
    case FirstFrame = 'i2va';
    case LastFrame = 'l2va';
    case FirstLastFrame = 'fl2va';
    case FullReference = 'full-reference';
}
