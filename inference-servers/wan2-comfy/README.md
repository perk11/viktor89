1. Install and configure comfy.ui
2. Install ComfyUI Manager and set up this workflow https://learn.thinkdiffusion.com/discover-why-wan-2-1-is-the-best-ai-video-model-right-now/?utm_source=reddit&utm_medium=reddit&utm_campaign=wan2.1#what-makes-wan-unique
3. download missing models
5. Add model to config.json:

```json
    "wan2.1_i2v_720p_14B_fp16": {
    "steps": 20,
    "model": "wan2.1_i2v_720p_14B_fp16",
    "width": 720,
    "height": 720,
    "url": "http://localhost:8128"
},
```
