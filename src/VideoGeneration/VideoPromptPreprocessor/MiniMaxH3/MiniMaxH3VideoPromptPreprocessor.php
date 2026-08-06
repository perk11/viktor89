<?php

namespace Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\MiniMaxH3;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\VideoGeneration\VideoGenerationPrompt;
use Perk11\Viktor89\VideoGeneration\VideoPromptPreprocessor\VideoPromptPreprocessor;
use Perk11\Viktor89\VideoGeneration\VideoTaskMode;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Builds MiniMax-H3 video prompts following the official
 * "Video Prompt Writing Guide" and "Full-Reference Mode Rewrite Output Format
 * Guide". Everything the model needs is stated in plain English in the prompt
 * itself; no prior knowledge of the guide's task abbreviations is assumed, and
 * anything the code already knows (which frame/reference/audio fields are set)
 * is stated as a fact rather than left for the model to decide.
 *
 * The task structure is chosen by VideoGenerationPrompt::effectiveMode():
 *  - TextToVideo     : no image and no audio -> three core fields, no instruction.
 *  - FirstFrame      : firstFrame set -> first-frame instruction + body that
 *                      develops forward from it.
 *  - LastFrame       : lastFrame set -> last-frame instruction + body that
 *                      converges onto it at the end.
 *  - FirstLastFrame  : firstFrame and lastFrame set -> first-and-last-frame
 *                      instruction + a motion path between them.
 *  - FullReference   : any audio and/or any reference image -> six-section
 *                      full-reference format.
 *
 * Only one image (the most concrete anchor available) is attached to the vision
 * assistant; every other image reaches the video model downstream and is
 * referenced by label only, so the prompt writer never invents unseen visual
 * details for them.
 *
 * References:
 *  https://huggingface.co/MiniMaxAI/MiniMax-H3/blob/main/docs/VIDEO_PROMPT_WRITING_GUIDE_base_en.md
 *  https://huggingface.co/MiniMaxAI/MiniMax-H3/blob/main/docs/VIDEO_PROMPT_WRITING_GUIDE_ref_en.md
 */
class MiniMaxH3VideoPromptPreprocessor implements VideoPromptPreprocessor
{
    public function __construct(
        private readonly ContextCompletingAssistantInterface $assistant,
        private readonly bool $assistantSupportsImages,
        private readonly AltTextProvider $altTextProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function preprocess(VideoGenerationPrompt $input, ProgressUpdateCallback $progressUpdateCallback): string
    {
        $mode = $input->effectiveMode();
        $progressUpdateCallback(
            static::class,
            'Rewriting the prompt with the MiniMax-H3 guide (' . $this->modeLabel($mode) . ')...',
        );

        $primaryImage = $input->primaryImage();
        // When the rewrite assistant cannot see images (e.g. a text-only model
        // such as GLM-5.2), describe the primary frame with the dedicated
        // vision assistant and feed that description as text instead of
        // attaching a photo the API would reject.
        $imageDescription = null;
        if ($primaryImage !== null && !$this->assistantSupportsImages) {
            $progressUpdateCallback(static::class, 'Describing the first-frame image (the model cannot see images directly)');
            $imageDescription = $this->altTextProvider->generateAltTextForImageString($primaryImage, $progressUpdateCallback);
        }

        $context = new AssistantContext();
        $context->systemPrompt = $this->buildSystemPrompt($input, $mode);

        $userMessage = new AssistantContextMessage();
        $userMessage->isUser = true;
        $userMessage->text = $this->buildUserMessage($input, $mode, $imageDescription);
        if ($primaryImage !== null && $imageDescription === null) {
            // The most concrete anchor is attached so the vision assistant can
            // ground the opening frame on what is actually visible; every other
            // image is handed to the video model downstream and referenced by
            // label only.
            $userMessage->photo = $primaryImage;
        }
        $context->messages[] = $userMessage;

        $rewritten = trim($this->assistant->getCompletionBasedOnContext($context, progressUpdateCallback: $progressUpdateCallback)->content);
        if ($rewritten === '') {
            throw new \RuntimeException('MiniMax-H3 prompt rewrite returned an empty result');
        }

        $this->logger->log(LogLevel::INFO, 'MiniMax-H3 rewritten prompt: ' . $rewritten);

        return $rewritten;
    }

    private function modeLabel(VideoTaskMode $mode): string
    {
        return match ($mode) {
            VideoTaskMode::TextToVideo    => 'text-to-video',
            VideoTaskMode::FirstFrame     => 'first-frame video',
            VideoTaskMode::LastFrame      => 'last-frame video',
            VideoTaskMode::FirstLastFrame => 'first-and-last-frame video',
            VideoTaskMode::FullReference  => 'full-reference mode',
        };
    }

    /**
     * Each supplied image as a labelled <Picture N> descriptor, in order:
     * first frame, last frame, reference images.
     *
     * @return list<array{label: string, subject: string, retention: string}>
     */
    private function pictureDescriptors(VideoGenerationPrompt $input): array
    {
        $descs = [];
        $i = 0;
        if ($input->firstFrame !== null) {
            $i++;
            $descs[] = [
                'label' => "<Picture {$i}>",
                'subject' => "<Picture {$i}> is the supplied first frame, used as a concrete frame anchor for [Shot 1] at 0.00 seconds.",
                'retention' => "<Picture {$i}> ([Shot 1] first frame): fully_preserved - the supplied first frame is retained as the opening frame.",
            ];
        }
        if ($input->lastFrame !== null) {
            $i++;
            $descs[] = [
                'label' => "<Picture {$i}>",
                'subject' => "<Picture {$i}> is the supplied last frame, used as a concrete frame anchor for the final shot.",
                'retention' => "<Picture {$i}> (final shot last frame): fully_preserved - the supplied last frame is retained as the ending frame.",
            ];
        }
        foreach ($input->referenceImages as $refImage) {
            $i++;
            $descs[] = [
                'label' => "<Picture {$i}>",
                'subject' => "<Picture {$i}> is an additional supplied reference frame, used as a shot-planning anchor.",
                'retention' => "<Picture {$i}> (reference frame): fully_preserved - retained as a reference anchor for the video model.",
            ];
        }

        return $descs;
    }

    /**
     * Each supplied audio as a labelled <Audio N> descriptor, in order: the
     * synchronized audio track (copied 1:1), then the reference audio signals.
     *
     * @return list<array{label: string, marker: string, subject: string, retention: string}>
     */
    private function audioDescriptors(VideoGenerationPrompt $input): array
    {
        $descs = [];
        $i = 0;
        if ($input->audioTrack !== null) {
            $i++;
            $descs[] = [
                'label' => "<Audio {$i}>",
                'marker' => 'fully_copy',
                'subject' => "<Audio {$i}> is the supplied synchronized audio track, reused 1:1 as the target video's complete final audio track.",
                'retention' => "<Audio {$i}>: fully_copy - <Audio {$i}> is reused 1:1 as the target video's complete final audio track.",
            ];
        }
        foreach ($input->referenceAudios as $refAudio) {
            $i++;
            $descs[] = [
                'label' => "<Audio {$i}>",
                'marker' => 'reference',
                'subject' => "<Audio {$i}> is a supplied reference audio signal; only its timbre, rhythm, music style, dialogue content, or sound texture is referenced, not copied.",
                'retention' => "<Audio {$i}>: reference - only its timbre/style/rhythm is referenced, without copying the original signal.",
            ];
        }

        return $descs;
    }

    private function buildUserMessage(VideoGenerationPrompt $input, VideoTaskMode $mode, ?string $imageDescription = null): string
    {
        $parts = [];
        $parts[] = 'Write the complete MiniMax-H3 video prompt for the following idea.';
        $parts[] = "User idea: \"{$input->userPrompt}\"";
        $parts[] = "Target video duration: at most {$input->durationSeconds} seconds.";

        switch ($mode) {
            case VideoTaskMode::TextToVideo:
                $parts[] = 'No reference image is supplied; build the whole video from the idea.';
                break;
            case VideoTaskMode::FirstFrame:
                $parts[] = 'The attached image is the ACTUAL FIRST FRAME of the target video at 0.00 seconds, in [Shot 1]. Reference it as <Picture 1>, derive the visual style from it, and keep character identity, clothing, colors, key objects and spatial relationships consistent with it. Develop the action forward from that opening frame.';
                break;
            case VideoTaskMode::LastFrame:
                $parts[] = 'The attached image is the ACTUAL LAST (final) FRAME of the target video. Reference it as <Picture 1>. It is reached at the very end of the video in the final shot — it does NOT belong to [Shot 1] by default. Infer a plausible earlier state from the idea and the image, then describe how the video gradually converges to land exactly on <Picture 1>.';
                break;
            case VideoTaskMode::FirstLastFrame:
                $parts[] = 'This is a first-and-last-frame task: <Picture 1> (attached) is the opening frame at 0.00 seconds in [Shot 1]; <Picture 2> is the ending frame, reached in the final shot. You can see only <Picture 1>, so reference <Picture 2> by label only and do not invent specific details for it beyond the idea. Describe the continuous motion path that connects the two frames; prefer a single shot.';
                break;
            case VideoTaskMode::FullReference:
                $pics = $this->pictureDescriptors($input);
                if (!empty($pics)) {
                    $parts[] = count($pics) . ' image(s) supplied, labelled '
                        . implode(', ', array_column($pics, 'label'))
                        . ' in order (first frame, then last frame, then extra reference frames). You can see only <Picture 1> (attached); reference the others by label only and never invent specific visual details for them that the idea does not state.';
                }
                $auds = $this->audioDescriptors($input);
                if (!empty($auds)) {
                    $parts[] = count($auds) . ' audio track(s) supplied, labelled '
                        . implode(', ', array_column($auds, 'label'))
                        . '. You cannot listen to any of them; their exact roles (copied 1:1 vs reference-only) and how to cite them are specified in the system instructions.';
                }
                break;
        }

        if ($imageDescription !== null) {
            $parts[] = 'NOTE: You cannot see images directly. A detailed description of <Picture 1> (the primary frame anchor) is given below. Use it as the concrete visual ground truth for the opening frame: derive the style from it and keep every visible detail (character identity, clothing, colors, key objects, spatial relationships) consistent with it.';
            $parts[] = "First-frame description: {$imageDescription}";
        }

        $parts[] = 'Output ONLY the final MiniMax-H3 prompt and nothing else.';

        return implode("\n", $parts);
    }

    private function buildSystemPrompt(VideoGenerationPrompt $input, VideoTaskMode $mode): string
    {
        $duration = $input->durationSeconds;

        $prompt = [];
        $prompt[] = "You are a video-prompt engineer for the MiniMax-H3 audio-video generation model. You turn a short user idea into a single, complete MiniMax-H3 video prompt.";
        $prompt[] = "The target video is at most {$duration} seconds long. Every shot-cut timestamp must fall strictly within those {$duration} seconds.";
        $prompt[] = "Write the body in English. Preserve the original language only for dialogue, lyrics and text that is actually visible on screen.";
        $prompt[] = "Be concrete and cinematic: name subject appearance, clothing, colors, props, lighting, environment, actions, camera motion and sound. Avoid vague mood words. Every detail must correspond to something actually visible or audible in the target video.";
        $prompt[] = "Output ONLY the final prompt. No commentary, no markdown code fences, no preamble, nothing except the prompt fields defined below.";

        $prompt[] = $this->sharedCoreRules($input->hasAnyImage());

        switch ($mode) {
            case VideoTaskMode::TextToVideo:
                $prompt[] = $this->t2vaRules();
                $prompt[] = $this->outputFormatT2va();
                break;
            case VideoTaskMode::FirstFrame:
                $prompt[] = $this->i2vaRules();
                $prompt[] = $this->outputFormatI2va();
                break;
            case VideoTaskMode::LastFrame:
                $prompt[] = $this->l2vaRules();
                $prompt[] = $this->outputFormatL2va();
                break;
            case VideoTaskMode::FirstLastFrame:
                $prompt[] = $this->fl2vaRules();
                $prompt[] = $this->outputFormatFl2va();
                break;
            case VideoTaskMode::FullReference:
                $prompt[] = $this->fullReferenceRules($input);
                $prompt[] = $this->outputFormatFullReference($input);
                break;
        }

        return implode("\n\n", $prompt);
    }

    /**
     * Rules shared by every task type: the main body, the two sound sections,
     * style, shots, cuts, camera motion, speakers and dialogue, and on-screen
     * text. Mirrors section 4 of the base guide. Code-known facts (here,
     * whether a reference image is supplied) are stated, not branched on.
     */
    private function sharedCoreRules(bool $hasReferenceImage): string
    {
        $styleSource = $hasReferenceImage
            ? '- Derive the overall style from the supplied reference image and keep it consistent across all shots.'
            : "- Choose the overall style from the user's text and keep it consistent across all shots.";

        return <<<RULES
SHARED RULES — apply to the main body field and to the overall_soundscape / non_diegetic_music sections:

Main body field:
- Describes visuals, actions, shots, speakers, dialogue, singing, and diegetic audio along the timeline. Every detail must correspond to something actually visible or audible.
- overall_soundscape: ambient sound, physical action sounds, and non-verbal human sounds across the whole video.
- non_diegetic_music: background music only the audience hears (the characters cannot).

Style and opening composition:
- At the start of [Shot 1] state the overall style and the initial composition. Common styles: Cinematic, live-action, 2D-animated, 3D CG, claymation, watercolor, vintage film.
{$styleSource}

Shots and cuts:
- Do NOT timestamp [Shot 1]. Later shots use "[Shot N] At MM:SS.mmm, ...". Cut times must be strictly increasing, in MM:SS.mmm form, and strictly within the video duration.
- Introduce a cut with "the camera cuts to" / "the shot cuts to" / "the shot transitions to" / "the shot changes to" / "the shot switches to". Use cross-dissolve, fade, or wipe only when the user explicitly asks for it.
- Cut only to add new information (subject, space, state, viewpoint, or time). If only the distance or a slight angle changes, use camera motion instead of a cut.

Camera motion (motion type + amplitude + speed):
- Write camera motion as natural English inside the shot, not as labels stacked at the end of a sentence. Add amplitude and speed only when they are meaningful; medium amplitude and normal speed are usually omitted.
- Motion types: Zoom In, Zoom Out, Push In, Pull Out, Pan Left, Pan Right, Truck Left, Truck Right, Tilt Up, Tilt Down, Pedestal Up, Pedestal Down, Arc Shot, Tracking Shot, Static Shot, Shake Slightly, Shake Strongly, POV, Roll Clockwise, Roll Counterclockwise.
- Amplitude: "with small amplitude" / "with large amplitude". Speed: "at slow speed" / "at fast speed".
- Examples: "The camera pushes in with small amplitude at slow speed toward the folded letter in her hands." / "The camera pans right with large amplitude at fast speed, revealing the open doorway." / "The camera holds a static shot as the runner exits the frame."

Speakers, dialogue, and singing:
- Anyone who speaks, sings, or produces an off-screen human voice gets a stable ID such as (S1), (S2). Several already-numbered speakers together use a compound ID such as (S1,S2). A speaker keeps the same ID across shots; characters who never vocalize get no ID. Assign (Sx) once, in the order of the first vocal event, and reuse that same ID at every later vocal event.
- On a speaker's first appearance establish a stable identity (character type, age, gender, on/off-screen, pitch, timbre, speaking rate, accent) and keep that identity consistent in later shots.
- Put the identifying phrase, ID, action, and delivery OUTSIDE the <d> tag; inside <d> put only the language tag and the verbatim spoken content, e.g. says: <d>[English] I get off at the next station.</d>. Preserve every original word and punctuation mark verbatim; never translate, summarize, or rewrite the spoken content.
- For voiceover use the exact phrase "says in an off-screen voiceover" and, immediately after the <d> block, state that the on-screen character's lips remain closed.
- When one line of dialogue or lyrics crosses a cut, mark the join points in both parts with <scenetrans> and state that the audio continues across the cut, using one of: "continues seamlessly across the cut", "continues uninterrupted into the next shot", "carries over from the previous shot", or "remains audible across the transition". Use <cutoff> when speech is truncated by the end of the video.
- Inside <d>, standardize punctuation to basic written marks (, . ? !); remove repeated tildes, emoji, bullets, or decorative/repeated punctuation. End complete statements, questions, and exclamations with . ? ! respectively, before </d>.

On-screen text:
- Banners, signs, labels, subtitles, and neon text that is actually visible on screen go in English double quotation marks, verbatim and untranslated, e.g. A red neon sign reading "营业中" glows above the doorway.

overall_soundscape:
- 1–4 English sentences in one continuous paragraph summarizing ambient sound, physical action sounds, and non-verbal human sounds across the whole video (wind, rain, traffic, footsteps, fabric, impacts, breathing, laughter, panting...). Do not repeat dialogue, singing, or diegetic music already written in the timeline. Use "N/A" only when the user explicitly asks for total silence throughout.

non_diegetic_music:
- 1–3 English sentences on background music only the audience hears (the characters cannot). Focus on instrumentation, tempo, rhythm, and dynamic changes; do not use abstract mood words or explain the emotional function of the score. Singing, instruments, radio, TV, or phone music that characters can hear is diegetic and belongs in the timeline. Use "N/A" when there is no non-diegetic music.
RULES;
    }

    private function t2vaRules(): string
    {
        return <<<RULES
TASK: Text-to-video (no reference image is supplied).
- Build the complete audiovisual timeline directly from the user's text. You may add scene, character, action, and sound details that stay consistent with the user's intent.
- There is no image-alignment instruction line; begin the output directly with the three core fields.
RULES;
    }

    private function i2vaRules(): string
    {
        return <<<RULES
TASK: First-frame-to-video. One image is supplied as the actual FIRST frame.
- <Picture 1> is the actual first frame of the target video at 0.00 seconds and belongs to [Shot 1]. Character identity, clothing, colors, key objects, and spatial relationships must stay consistent with it throughout the whole video.
- First establish the style, subjects, composition, and scene anchors visible in <Picture 1>, then describe the next action. Recommended structure: first-frame anchor -> action onset -> continuous development -> result or reaction.
- Treat <Picture 1> as the live opening frame and move forward from it; do not re-describe it as a static still image.
RULES;
    }

    private function l2vaRules(): string
    {
        return <<<RULES
TASK: Last-frame-to-video. One image is supplied as the actual LAST frame.
- <Picture 1> is the FINAL frame of the target video and belongs to the LAST shot ([Shot N]); it does NOT inherently belong to [Shot 1].
- Infer a plausible EARLIER state from the user's idea and <Picture 1>, then describe how the characters, objects, camera, and scene gradually approach and finally land exactly on <Picture 1> in the final shot. Recommended structure: plausible preceding state -> explicit action and transition path -> gradual convergence in the final shot -> last-frame landing.
- Keep character identity, clothing, colors, key objects consistent with <Picture 1>.
RULES;
    }

    private function fl2vaRules(): string
    {
        return <<<RULES
TASK: First-and-last-frame-to-video. Two keyframe images are supplied: <Picture 1> is the opening frame at 0.00 seconds ([Shot 1]); <Picture 2> is the ending frame, reached in the final shot ([Shot N]).
- Do NOT just describe the two frames as static images. Describe the MOTION PATH that connects them: how the subject moves, how poses change, how objects are manipulated, how the composition evolves, and how the scene or lighting transitions.
- Prefer a SINGLE SHOT so the video can interpolate continuously from the first frame to the last frame. Use multiple shots ONLY when the user explicitly asks for them. The last frame must be reached by the final [Shot N] at the end of the video.
- Recommended structure: first-frame state -> observable intermediate changes -> progressively narrowing differences -> last-frame state.
- Keep character identity, clothing, colors, key objects consistent across both frames.
RULES;
    }

    /**
     * Square-bracketed task-type prefix for the full-reference summary, fixed
     * from the supplied inputs: images -> keyframe completion, the audio track
     * -> audio reuse, reference audios -> audio reference; joined with " + ".
     */
    private function summaryTaskType(VideoGenerationPrompt $input): string
    {
        $types = [];
        if ($input->hasAnyImage()) {
            $types[] = 'keyframe completion';
        }
        if ($input->audioTrack !== null) {
            $types[] = 'audio reuse';
        }
        if (!empty($input->referenceAudios)) {
            $types[] = 'audio reference';
        }

        return $types === [] ? 'reference generation' : implode(' + ', $types);
    }

    private function fullReferenceRules(VideoGenerationPrompt $input): string
    {
        $hasImage = $input->hasAnyImage();
        $hasAudio = $input->hasAnyAudio();
        $hasCopiedAudio = $input->audioTrack !== null;

        $material = [];
        if ($hasImage) {
            $material[] = 'reference image(s)';
        }
        if ($input->audioTrack !== null) {
            $material[] = 'a reusable audio track';
        }
        if (!empty($input->referenceAudios)) {
            $material[] = 'reference audio signal(s)';
        }
        $reason = $material === [] ? 'reference material' : implode(', ', $material);

        $pictureRules = '';
        if ($hasImage) {
            $pictureRules = "- <Picture N>: a reference image used as a concrete target frame or shot-planning anchor (a first frame, last frame, keyframe, or composition anchor). When several are supplied they are numbered <Picture 1>, <Picture 2>, ... in this order: the first frame, then the last frame, then any extra reference frames.\n- If a <Picture N> is used only to define a character, scene, costume, or style (not as a concrete frame), do not give it a standalone line; cite it inside the matching <Subject N> definition instead. When an image acts as a storyboard or shot-planning reference, state which shots it maps to and what planning information it provides.\n";
        }

        $audioRules = '';
        if ($hasAudio) {
            $audioRules = "- <Audio N>: an audio signal that is copied (fully_copy) or referenced (reference). The supplied synchronized audio track is copied 1:1 as the complete final audio track; any reference audio is referenced only for timbre, rhythm, music style, dialogue content, or sound texture and is not copied. When several are supplied they are numbered <Audio 1>, <Audio 2>, ... in this order: the synchronized track, then the reference audio signals.\n- When an <Audio N> explicitly corresponds to a target speaker, bind them in subject_definitions by reusing that speaker's GLOBAL id, e.g. \"<Audio 1> is the voice-timbre reference for <Subject 1> (S1).\" The (Sx) id comes from the target video's global speaker order and is never renumbered inside an audio definition.\n";
        }

        $taskType = $this->summaryTaskType($input);
        $taskTypeMeaning = '';
        if ($hasImage) {
            $taskTypeMeaning .= "  - keyframe completion: a supplied image serves as a concrete frame anchor.\n";
        }
        if ($input->audioTrack !== null) {
            $taskTypeMeaning .= "  - audio reuse: the supplied synchronized audio track is reused 1:1.\n";
        }
        if (!empty($input->referenceAudios)) {
            $taskTypeMeaning .= "  - audio reference: a supplied reference audio is referenced (timbre/style/rhythm), not copied.\n";
        }

        $audioBodyRules = $hasAudio
            ? "\n- Cite each supplied <Audio N> wherever its role applies and state its marker (fully_copy for the synchronized track, reference for reference audio)."
            : '';

        $audioSpeakerSourceRule = $hasAudio
            ? "\n- If a verbal cue lives only inside a reused or referenced <Audio N> with no independent vocal source, cite <Audio N> as the audible source and do NOT invent an extra (Sx). If a concrete person, character, narrator, or other independent vocal source physically produces a voice, assign and reuse (Sx) for that source (and, if it matches <Audio N>, bind them in subject_definitions)."
            : '';

        $audioSectionSplit = $hasCopiedAudio
            ? "\n- For the copied synchronized track, state its copy relationship in the section matching its audible layer: ambience and sound effects in overall_soundscape, audience-only score in non_diegetic_music. If it provides both kinds of content, describe each layer in its own section. Do not repeat dialogue or lyrics in these two sections."
            : '';

        return <<<RULES
TASK: Full-reference mode (six-section format). It is used because reference material is supplied ({$reason}), rather than only a first frame and/or last frame.
The output has SIX sections in this exact order: subject_definitions, summary, retention_analysis, detailed_description, overall_soundscape, non_diegetic_music.
Once a reference label is assigned it keeps the same meaning across every section. The labels that can appear here are <Subject N>, <Picture N>, and <Audio N> (the guide also defines <Video N> for reference videos, but it is never used because no reference video is supplied). In subject_definitions, define each referenced asset that must be tracked separately on its own line, explaining what its label denotes, its reference role, and the main features to follow.
- <Subject N>: reusable visible content (people, animals, objects, scenes, clothing, props, styles, actions, expressions, poses...). One subject may be defined by several assets and one asset may provide several subjects. Omit unless a subject needs to be tracked separately.
{$pictureRules}{$audioRules}
summary: one short English paragraph summarizing the target video and its main reference relationships, using the labels already defined. It MUST start with exactly this task-type prefix: [{$taskType}].
Meaning of the prefix term(s):
{$taskTypeMeaning}Do not introduce new reference labels in summary.

retention_analysis: one line per reference label, preserving the meaning from subject_definitions. Each line states where the content appears, then a fixed relationship marker, then a short explanation.
  - Visible content (<Subject N>, <Picture N>) markers: fully_preserved, partially_preserved, attribute_transfer, weak_reference. Example: "<Subject 1> (appears in [Shot 1], [Shot 3]): fully_preserved - ...". Picture example: "<Picture 2> ([Shot 1] first frame): fully_preserved - ...".
  - Audio (<Audio N>) markers: fully_copy, partially_copy, reference, weak_reference. Example: "<Audio 1>: fully_copy - <Audio 1> is reused 1:1 as the target video's complete final audio track.".
  Choose each marker only within the role already defined for that label; do not treat newly added actions, backgrounds, or plot events in the target video as losses of reference fidelity.

detailed_description (the main body):
- The basic shot/camera/dialogue format follows the SHARED RULES above. The differences in full-reference mode: the main field is named detailed_description (not integrated_multimodal_description); establish the style in one or two English sentences BEFORE [Shot 1]; insert <Subject N>, <Picture N>, <Audio N> at their first appearance and wherever their roles apply; and cite <Audio N> in the shot or audio phase where it is active.
- At the first clear appearance of an important <Subject N>, describe its referenced characteristics, position in the frame, and current action within what is actually visible; keep using the same label in later shots without redefining it. For concrete frame anchors use natural phrasing such as "the shot begins from <Picture N>", "the shot's keyframe corresponds to <Picture N>", or "the shot ends on <Picture N>".
- When a referenced <Subject N> physically speaks, write "<Subject N> (Sx)"; if it speaks off-screen, keep that form and mark it off-screen. Assign (Sx) once, in the order of the first actual vocal event, and reuse the same id at every later vocal event. Never write (Sx) in retention_analysis.
- Make detailed_description as detailed and explicit as possible. For EACH shot clearly establish: current composition, subject appearance and position, environment and lighting, actions and state changes, camera movement, current sound, and the points where referenced content actually appears or takes effect. Do not reduce it to a plot summary or a list of reference relationships.
- This section is normally 350–500 English words. Dialogue-dense content prioritizes fitting the complete spoken timeline over mechanically reaching the word count. A single shot does not justify a shorter description — distribute detail across shots according to their information load.{$audioBodyRules}{$audioSpeakerSourceRule}{$audioSectionSplit}
RULES;
    }

    private function outputFormatT2va(): string
    {
        return <<<FORMAT
OUTPUT FORMAT (text-to-video) — no instruction line; begin directly with exactly these three labelled fields and nothing else:
integrated_multimodal_description: [Shot 1] ...

overall_soundscape: ...

non_diegetic_music: ...

EXAMPLE (from the official guide):
integrated_multimodal_description: [Shot 1] Live-action, cinematic, a medium-wide shot frames a baker opening the shutters of a small street bakery before sunrise. The camera pushes in with small amplitude at slow speed as the middle-aged baker with a calm, slightly raspy voice (S1) places a fresh loaf on the wooden counter and says: <d>[English] First batch of the morning.</d> [Shot 2] At 00:05.000, the camera cuts to a close-up of steam rising from the sliced bread while the baker's final words carry over from the previous shot.
overall_soundscape: Wooden shutters scrape open over a quiet street as trays clink softly inside the bakery. The doorbell rings once, followed by light footsteps and the crisp sound of bread being sliced.
non_diegetic_music: A soft acoustic-guitar pattern at a moderate tempo, joined by sparse upright-bass notes and a gentle fade at the end.
FORMAT;
    }

    private function outputFormatI2va(): string
    {
        return <<<FORMAT
OUTPUT FORMAT (first-frame video) — the FIRST line is the image-alignment instruction (use exactly this wording, with <Picture 1>), then one blank line, then the three core fields:
For the target video, at 0.00 seconds into the target video, <Picture 1> (from [Shot 1]) is fully referenced.

integrated_multimodal_description: [Shot 1] ...

overall_soundscape: ...

non_diegetic_music: ...

EXAMPLE (from the official guide — the instruction line goes above the body):
For the target video, at 0.00 seconds into the target video, <Picture 1> (from [Shot 1]) is fully referenced.

integrated_multimodal_description: [Shot 1] Live-action, cinematic, the young woman shown in <Picture 1> remains beside the rain-covered train window, preserving her appearance, clothing, seat position, and the carriage layout. The camera trucks right with small amplitude at slow speed as she lifts her gaze from the folded letter toward the passing city lights. Her reflection moves across the glass while the quiet, breathy young woman (S1) says: <d>[English] I get off at the next station.</d> She folds the letter along its existing crease.
overall_soundscape: The train wheels produce a steady metallic rhythm beneath a low ventilation hum. Rain ticks against the window while paper rustles softly in her hands.
non_diegetic_music: Sustained cello notes at a slow tempo with widely spaced piano tones, gradually decreasing in volume.
FORMAT;
    }

    private function outputFormatL2va(): string
    {
        return <<<FORMAT
OUTPUT FORMAT (last-frame video) — the FIRST line is the image-alignment instruction (use exactly this wording, with <Picture 1>), then one blank line, then the three core fields. N is the number of the final shot; S.SS is the video's effective duration in seconds, formatted to exactly two decimals and at most the duration cap:
How the reference pictures align with the target video — <Picture 1> (from [Shot N]) aligns with the S.SS-second mark of the target video.

integrated_multimodal_description: [Shot 1] ...

overall_soundscape: ...

non_diegetic_music: ...

EXAMPLE (from the official guide, a 6-second single shot — the instruction line goes above the body):
How the reference pictures align with the target video — <Picture 1> (from [Shot 1]) aligns with the 6.00-second mark of the target video.

integrated_multimodal_description: [Shot 1] Live-action, cinematic, a close shot begins with an intact drinking glass near the edge of a dark wooden table, while the same hand and sleeve visible in <Picture 1> approach from the right. The camera pushes in with small amplitude at slow speed as the fingertips strike the rim. The glass tips, falls, and hits the floor with a sharp impact; cracks spread through it as fragments slide outward. Toward the end, the moving pieces lose momentum and settle into the exact broken arrangement, hand position, camera angle, lighting, and final composition established by <Picture 1>.
overall_soundscape: Fingertips tap the glass before it scrapes across the tabletop, falls, and breaks with a sharp crash. Small fragments scatter and gradually stop sliding across the floor.
non_diegetic_music: A low electronic pulse at a slow tempo, ending immediately after the glass breaks.
FORMAT;
    }

    private function outputFormatFl2va(): string
    {
        return <<<FORMAT
OUTPUT FORMAT (first-and-last-frame video) — the FIRST line is the image-alignment instruction (use exactly this wording), then one blank line, then the three core fields. N is the number of the final shot; S.SS is the video's effective duration in seconds, formatted to exactly two decimals and at most the duration cap:
How the reference pictures align with the target video — Picture 1 (from Shot 1) aligns with the 0.00-second mark of the target video; Picture 2 (from Shot N) aligns with the S.SS-second mark of the target video.

integrated_multimodal_description: [Shot 1] ...

overall_soundscape: ...

non_diegetic_music: ...

EXAMPLE (from the official guide, an 8-second single shot — the instruction line goes above the body):
How the reference pictures align with the target video — Picture 1 (from Shot 1) aligns with the 0.00-second mark of the target video; Picture 2 (from Shot 1) aligns with the 8.00-second mark of the target video.

integrated_multimodal_description: [Shot 1] Live-action, cinematic, a rain-soaked cyclist begins in the position and framing established by Picture 1, holding a closed black umbrella beside a silver bicycle. The camera pulls out with small amplitude at slow speed as she releases the bicycle handle, raises the umbrella above her shoulder, and presses the runner upward until the canopy opens. Water rolls from the expanding fabric while she steps beneath it, rotates the handle into the final angle, and settles into the pose, spacing, and composition established by Picture 2 at the end of the shot.
overall_soundscape: Rain falls steadily on the pavement, followed by the metallic click of the umbrella runner and the soft snap of the canopy opening. Water drips from the bicycle frame as distant traffic passes.
non_diegetic_music: N/A
FORMAT;
    }

    private function outputFormatFullReference(VideoGenerationPrompt $input): string
    {
        $pics = $this->pictureDescriptors($input);
        $auds = $this->audioDescriptors($input);

        $subjectLines = '';
        foreach ($pics as $d) {
            $subjectLines .= $d['subject'] . "\n";
        }
        foreach ($auds as $d) {
            $subjectLines .= $d['subject'] . "\n";
        }

        $retentionLines = '';
        foreach ($pics as $d) {
            $retentionLines .= $d['retention'] . "\n";
        }
        foreach ($auds as $d) {
            $retentionLines .= $d['retention'] . "\n";
        }

        $taskType = $this->summaryTaskType($input);

        $audioSummaryBits = [];
        $copied = array_filter($auds, static fn(array $d): bool => $d['marker'] === 'fully_copy');
        $referenced = array_filter($auds, static fn(array $d): bool => $d['marker'] === 'reference');
        if ($copied !== []) {
            $audioSummaryBits[] = implode(', ', array_column($copied, 'label')) . ' ' . (count($copied) > 1 ? 'are' : 'is') . ' reused 1:1';
        }
        if ($referenced !== []) {
            $audioSummaryBits[] = implode(', ', array_column($referenced, 'label')) . ' ' . (count($referenced) > 1 ? 'are' : 'is') . ' referenced';
        }
        $audioSummary = $audioSummaryBits === [] ? '' : ' and that ' . implode(' and ', $audioSummaryBits);

        $example = $input->hasAnyAudio()
            ? $this->fullReferenceAudioExample()
            : $this->fullReferenceImageExample();

        return <<<FORMAT
OUTPUT FORMAT (full-reference mode) — emit exactly these six labelled sections and nothing else:
subject_definitions:
{$subjectLines}
summary:
[{$taskType}] <one short English paragraph summarizing the target video{$audioSummary}>

retention_analysis:
{$retentionLines}
detailed_description:
<one or two English style sentences>
[Shot 1] ...

overall_soundscape: ...

non_diegetic_music: ...

{$example}
FORMAT;
    }

    private function fullReferenceAudioExample(): string
    {
        return <<<FULLREF_EXAMPLE
EXAMPLE (full-reference, audio reuse — adapt the labels, markers, and counts to what is actually supplied):
subject_definitions:
<Audio 1> is a synchronized audio track reused 1:1 as the complete final audio track of the target video.

summary:
[audio reuse] The target video shows a barista steaming milk behind a café counter while <Audio 1> is reused 1:1 as the complete final soundtrack, carrying both the room ambience and the audience-only piano motif.

retention_analysis:
<Audio 1>: fully_copy - <Audio 1> is reused 1:1 as the target video's complete final audio track.

detailed_description:
The target video is in a warm, slightly grainy cinematic style with soft morning light.
[Shot 1] A medium shot establishes a small café counter, an espresso machine, and a ceramic cup. A young woman with a dark apron and tied-back hair grips the steam wand. The camera pushes in with small amplitude at slow speed as she lowers the pitcher and the milk hisses; the hiss and the room tone come from <Audio 1>. She glances toward the window as the reused ambience of <Audio 1> fills the room.
[Shot 2] At 00:04.000, the shot cuts to a close-up of the cup filling with crema. The ambient layer of <Audio 1> continues uninterrupted into the next shot, and a low piano motif from the same track becomes more prominent.
overall_soundscape: The reused ambience from <Audio 1> carries café room tone, the espresso machine, and distant ceramic clatter throughout the video.
non_diegetic_music: The audience-only score layer of <Audio 1> — a sparse piano motif at a slow tempo — is retained.
FULLREF_EXAMPLE;
    }

    private function fullReferenceImageExample(): string
    {
        return <<<FULLREF_EXAMPLE
EXAMPLE (full-reference, keyframe completion, multiple reference images, no audio — adapt the labels and counts to what is actually supplied):
subject_definitions:
<Picture 1> is the supplied first frame, used as a concrete frame anchor for [Shot 1] at 0.00 seconds.
<Picture 2> is an additional supplied reference frame, used as a shot-planning anchor.

summary:
[keyframe completion] The target video opens on the composition shown in <Picture 1> and migrates toward the composition of <Picture 2>, using both as concrete frame anchors while new motion is generated between them.

retention_analysis:
<Picture 1> ([Shot 1] first frame): fully_preserved - the supplied first frame is retained as the opening frame.
<Picture 2> (reference frame): fully_preserved - retained as a reference anchor for the video model.

detailed_description:
The target video is in a clean, cinematic style with soft natural light.
[Shot 1] The shot begins from <Picture 1>, establishing the subject, posture, and setting exactly as shown there. The camera pushes in with small amplitude at slow speed as the subject starts to move, keeping identity, clothing, colors, and spatial layout consistent with <Picture 1>.
[Shot 2] At 00:04.000, the shot cuts toward the composition of <Picture 2>, treating it as a keyframe anchor: the framing, subject pose, and background migrate toward what <Picture 2> shows. The camera holds a static shot as the final pose aligns with <Picture 2>.
overall_soundscape: Soft footsteps and the rustle of clothing accompany the subject's movement, with a low room tone underneath.
non_diegetic_music: N/A
FULLREF_EXAMPLE;
    }
}
