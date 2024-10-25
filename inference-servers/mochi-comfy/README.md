1. Install and configure comfy.ui
2. Install custom nodes: ComfyUI-KJNodes and ComfyUI-MochiWrapper
3. `pip install SageAttention` in the comfy env
4. Load sample workflow from ComfyUI-MochiWrapper/examples and download missing models
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
