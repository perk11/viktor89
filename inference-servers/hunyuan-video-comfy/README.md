1. Install and configure comfy.ui
2. Install ComfyUI Manager and set up this workflow https://civitai.com/models/1219744/hunyuan-triple-lora-fast-high-definition-optimized-for-3090-orby-bizarro
3. download missing models
5. Add model to config.json:

```json
{
    "model": "mochi_preview_dit_GGUF_Q8_0",
    "steps": 50,
    "width": 640,
    "height": 480,
    "num_frames": 163,
    "url": "http://localhost:8109"
}
```
