"""Unit tests for cover_params.build_cover_params (stdlib unittest, no Flask).

These pin down exactly what the wrapper forwards to ACE-Step's /release_task for
/cover. The recurring "the cover didn't change" bug was caused by cover_noise
never being forwarded; these tests guarantee both cover knobs always reach the
payload with the documented 0.2/0.2 style-transfer defaults.
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from cover_params import build_cover_params  # noqa: E402


def defaults(**overrides):
    base = dict(
        dit_model='acestep-v15-xl-base',
        acestep_format='wav',
        inference_steps=50,
        guidance_scale=7.0,
        default_cover_strength=0.2,
        default_cover_noise=0.2,
    )
    base.update(overrides)
    return base


class BuildCoverParamsTest(unittest.TestCase):
    def test_always_forwards_both_cover_knobs(self):
        # The historical bug: cover_noise_strength was never sent, so the server
        # defaulted to 0.0 (50 steps, pure-noise seed -> no genre change).
        # Both knobs MUST always be present.
        params = build_cover_params('rock', None, None, None, None, **defaults())
        self.assertEqual(params['audio_cover_strength'], 0.2)
        self.assertEqual(params['cover_noise_strength'], 0.2)

    def test_omitted_strength_and_noise_use_wrapper_defaults(self):
        params = build_cover_params('rock', None, None, None, None, **defaults())
        self.assertEqual(params['audio_cover_strength'], 0.2)
        self.assertEqual(params['cover_noise_strength'], 0.2)

    def test_explicit_strength_and_noise_are_forwarded(self):
        params = build_cover_params('rock', None, 0.8, 0.5, None, **defaults())
        self.assertEqual(params['audio_cover_strength'], 0.8)
        self.assertEqual(params['cover_noise_strength'], 0.5)

    def test_strength_is_clamped_to_unit_range(self):
        params = build_cover_params('rock', None, 5.0, -1.0, None, **defaults())
        self.assertEqual(params['audio_cover_strength'], 1.0)
        self.assertEqual(params['cover_noise_strength'], 0.0)

    def test_string_values_are_coerced(self):
        # JSON numbers come through fine, but the bot sometimes sends strings.
        params = build_cover_params('rock', None, '0.4', '0.3', None, **defaults())
        self.assertEqual(params['audio_cover_strength'], 0.4)
        self.assertEqual(params['cover_noise_strength'], 0.3)

    def test_garbage_values_fall_back_to_defaults(self):
        params = build_cover_params('rock', None, 'nope', {}, None, **defaults())
        self.assertEqual(params['audio_cover_strength'], 0.2)
        self.assertEqual(params['cover_noise_strength'], 0.2)

    def test_task_type_prompt_model_steps_cfg_always_set(self):
        params = build_cover_params('synthwave', None, None, None, None, **defaults())
        self.assertEqual(params['task_type'], 'cover')
        self.assertEqual(params['prompt'], 'synthwave')
        self.assertEqual(params['model'], 'acestep-v15-xl-base')
        self.assertEqual(params['inference_steps'], 50)
        self.assertEqual(params['guidance_scale'], 7.0)
        self.assertEqual(params['audio_format'], 'wav')
        self.assertEqual(params['batch_size'], 1)
        # src_audio_path is added by the caller (it does file I/O), never here.
        self.assertNotIn('src_audio_path', params)

    def test_lyrics_optional(self):
        without = build_cover_params('rock', None, None, None, None, **defaults())
        self.assertNotIn('lyrics', without)
        with_lyrics = build_cover_params('rock', '[Verse]\nla la', None, None, None, **defaults())
        self.assertEqual(with_lyrics['lyrics'], '[Verse]\nla la')

    def test_duration_is_seconds_clamped(self):
        params = build_cover_params('rock', None, None, None, 180000, **defaults())
        self.assertEqual(params['audio_duration'], 180)
        too_short = build_cover_params('rock', None, None, None, 1000, **defaults())
        self.assertEqual(too_short['audio_duration'], 10)
        too_long = build_cover_params('rock', None, None, None, 10_000_000, **defaults())
        self.assertEqual(too_long['audio_duration'], 600)

    def test_seed_handling(self):
        random = build_cover_params('rock', None, None, None, None, **defaults())
        self.assertTrue(random['use_random_seed'])
        self.assertNotIn('seed', random)
        fixed = build_cover_params('rock', None, None, None, None, seed=12345, **defaults())
        self.assertFalse(fixed['use_random_seed'])
        self.assertEqual(fixed['seed'], 12345)


if __name__ == '__main__':
    unittest.main()
