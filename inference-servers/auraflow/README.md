This model requires 18046 MiB of VRAM

```
pip install flask transformers accelerate protobuf sentencepiece
pip install git+https://github.com/huggingface/diffusers.git
python3 main.py
```

Add model to automatic1111-model-config.json:

```json
{
  "AuraFlow": {
    "width": 1024,
    "height": 1024,
    "steps": 50,
    "customUrl": "http://localhost:18090"
  }
}
```
