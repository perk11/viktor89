# based on https://github.com/bryanswkim/Chain-of-Zoom/blob/main/inference_coz.py
import argparse
import base64
import io
import json
import random
import tempfile
import threading
import traceback

from flask import Flask, request, jsonify

app = Flask(__name__)

parser = argparse.ArgumentParser(description="Inference server for Chain-of-Zoom")
parser.add_argument('--source_dir', type=str,
                    help='path to Chain-of-Zoom repo where inference_coz.py ran at least once', required=True)
parser.add_argument('--port', type=int, help='port to listen on', required=True)
args = parser.parse_args()

# Load the model
from io import BytesIO
import sys

sys.path.append(args.source_dir)
import os
import torch
from torchvision import transforms
from PIL import Image

from ram.models.ram_lora import ram
from ram import inference_ram as inference
from utils.wavelet_color_fix import adain_color_fix, wavelet_color_fix
from osediff_sd3 import OSEDiff_SD3_TEST_efficient, SD3Euler

tensor_transforms = transforms.Compose([
    transforms.ToTensor(),
])
ram_transforms = transforms.Compose([
    transforms.Resize((384, 384)),
    transforms.Normalize(mean=[0.485, 0.456, 0.406], std=[0.229, 0.224, 0.225])
])
from types import SimpleNamespace

coz_args_default = SimpleNamespace(
    rec_type="recursive_multiscale",
    prompt_type="vlm",
    lora_path=args.source_dir + "/ckpt/SR_LoRA/model_20001.pkl",
    vae_path=args.source_dir + "/ckpt/SR_VAE/vae_encoder_20001.pt",
    pretrained_model_name_or_path="stabilityai/stable-diffusion-3-medium-diffusers",
    ram_ft_path=args.source_dir + "/ckpt/DAPE/DAPE.pth",
    ram_path=args.source_dir + "/ckpt/RAM/ram_swin_large_14m.pth",
    mixed_precision="fp16",
    lora_rank=4,
    upscale=4,
    prompt='',
    align_method='nofix',
)

vlm_model = None
model = None
model_test = None

def load_model(coz_args):
    global weight_dtype, vlm_model, model, model_test
    weight_dtype = torch.float32
    if coz_args.mixed_precision == "fp16":
        weight_dtype = torch.float16

    # initialize SR model
    model = None
    # For efficient memory, text encoders are moved to CPU/GPU on demand in get_validation_prompt
    # Only load transformer and VAE initially if they are always on GPU
    model = SD3Euler()
    model.transformer.to('cuda', dtype=torch.float32)
    model.vae.to('cuda', dtype=torch.float32)
    for p in [model.text_enc_1, model.text_enc_2, model.text_enc_3, model.transformer, model.vae]:
        p.requires_grad_(False)
    model_test = OSEDiff_SD3_TEST_efficient(coz_args, model)

    global vlm_processor
    global process_vision_info
    vlm_processor = None
    from transformers import Qwen2_5_VLForConditionalGeneration, AutoProcessor
    from qwen_vl_utils import process_vision_info

    vlm_model_name = "Qwen/Qwen2.5-VL-3B-Instruct"
    print(f"Loading base VLM model: {vlm_model_name}")
    vlm_model = Qwen2_5_VLForConditionalGeneration.from_pretrained(
        vlm_model_name,
        torch_dtype="auto",
        device_map="auto"
    )
    vlm_processor = AutoProcessor.from_pretrained(vlm_model_name)
    print('Base VLM LOADING COMPLETE')


load_model(coz_args_default)


def resize_and_center_crop(img: Image.Image, size: int) -> Image.Image:
    w, h = img.size
    scale = size / min(w, h)
    new_w, new_h = int(w * scale), int(h * scale)
    img = img.resize((new_w, new_h), Image.LANCZOS)
    left = (new_w - size) // 2
    top = (new_h - size) // 2
    return img.crop((left, top, left + size, top + size))


def get_validation_prompt(coz_args, image, prompt_image_path, dape_model=None, vlm_model=None, device='cuda'):
    # prepare low-res tensor for SR input
    lq = tensor_transforms(image).unsqueeze(0).to(device)
    # select prompt source
    if coz_args.prompt_type == "null":
        prompt_text = coz_args.prompt or ""
    elif coz_args.prompt_type == "dape":
        lq_ram = ram_transforms(lq).to(dtype=weight_dtype)
        captions = inference(lq_ram, dape_model)
        prompt_text = f"{captions[0]}, {coz_args.prompt}," if coz_args.prompt else captions[0]
    elif coz_args.prompt_type in ("vlm"):
        message_text = None

        if coz_args.rec_type == "recursive":
            message_text = "What is in this image? Give me a set of words."
            print(f'MESSAGE TEXT: {message_text}')
            messages = [
                {"role": "system", "content": f"{message_text}"},
                {
                    "role": "user",
                    "content": [
                        {"type": "image", "image": prompt_image_path}
                    ]
                }
            ]
            text = vlm_processor.apply_chat_template(messages, tokenize=False, add_generation_prompt=True)
            image_inputs, video_inputs = process_vision_info(messages)
            inputs = vlm_processor(
                text=[text],
                images=image_inputs,
                videos=video_inputs,
                padding=True,
                return_tensors="pt",
            )

        elif coz_args.rec_type == "recursive_multiscale":
            start_image_path = prompt_image_path[0]
            input_image_path = prompt_image_path[1]
            message_text = "The second image is a zoom-in of the first image. Based on this knowledge, what is in the second image? Give me a set of words."
            print(
                f'START IMAGE PATH: {start_image_path}\nINPUT IMAGE PATH: {input_image_path}\nMESSAGE TEXT: {message_text}')
            messages = [
                {"role": "system", "content": f"{message_text}"},
                {
                    "role": "user",
                    "content": [
                        {"type": "image", "image": start_image_path},
                        {"type": "image", "image": input_image_path}
                    ]
                }
            ]
            print(f'MESSAGES\n{messages}')

            text = vlm_processor.apply_chat_template(messages, tokenize=False, add_generation_prompt=True)
            image_inputs, video_inputs = process_vision_info(messages)
            inputs = vlm_processor(
                text=[text],
                images=image_inputs,
                videos=video_inputs,
                padding=True,
                return_tensors="pt",
            )

        else:
            raise ValueError(f"VLM prompt generation not implemented for rec_type: {coz_args.rec_type}")

        inputs = inputs.to("cuda")

        original_sr_devices = {}
        if 'model' in globals() and hasattr(model, 'text_enc_1'):  # Check if SR model is defined
            print("Moving SR model components to CPU for VLM inference.")
            original_sr_devices['text_enc_1'] = model.text_enc_1.device
            original_sr_devices['text_enc_2'] = model.text_enc_2.device
            original_sr_devices['text_enc_3'] = model.text_enc_3.device
            original_sr_devices['transformer'] = model.transformer.device
            original_sr_devices['vae'] = model.vae.device

            model.text_enc_1.to('cpu')
            model.text_enc_2.to('cpu')
            model.text_enc_3.to('cpu')
            model.transformer.to('cpu')

        generated_ids = vlm_model.generate(**inputs, max_new_tokens=128)
        generated_ids_trimmed = [
            out_ids[len(in_ids):] for in_ids, out_ids in zip(inputs.input_ids, generated_ids)
        ]
        output_text = vlm_processor.batch_decode(
            generated_ids_trimmed, skip_special_tokens=True, clean_up_tokenization_spaces=False
        )

        prompt_text = f"{output_text[0]}, {coz_args.prompt}," if coz_args.prompt else output_text[0]

        print("Restoring SR model components to original devices.")
        model.text_enc_1.to(original_sr_devices['text_enc_1'])
        model.text_enc_2.to(original_sr_devices['text_enc_2'])
        model.text_enc_3.to(original_sr_devices['text_enc_3'])
        model.transformer.to(original_sr_devices['transformer'])
    else:
        raise ValueError(f"Unknown prompt_type: {coz_args.prompt_type}")
    return prompt_text, lq


def generate_image(coz_args, input_image_path, tmp_dir):
    # load DAPE if needed
    DAPE = None
    if coz_args.prompt_type == "dape":
        DAPE = ram(pretrained=coz_args.ram_path,
                   pretrained_condition=coz_args.ram_ft_path,
                   image_size=384,
                   vit='swin_l')
        DAPE.eval().to("cuda")
        DAPE = DAPE.to(dtype=weight_dtype)

    prev_sr_output_pil = Image.open(input_image_path).convert('RGB')
    rscale = coz_args.upscale
    w, h = prev_sr_output_pil.size
    new_w, new_h = w // rscale, h // rscale
    cropped_region = prev_sr_output_pil.crop(((w - new_w) // 2, (h - new_h) // 2, (w + new_w) // 2, (h + new_h) // 2))
    current_sr_input_image_pil = cropped_region.resize((w, h), Image.BICUBIC)

    # save the SR input image (which is the "zoomed-in" image for VLM)
    zoomed_image_path = f'{tmp_dir}/zoomed_input.png'
    current_sr_input_image_pil.save(zoomed_image_path)
    prompt_image_path = [input_image_path, zoomed_image_path]
    # generate prompts
    validation_prompt, lq = get_validation_prompt(coz_args, current_sr_input_image_pil, prompt_image_path, DAPE,
                                                  vlm_model)
    print(f'TAG: {validation_prompt}')

    # super-resolution
    with torch.no_grad():
        lq = lq * 2 - 1

        if model is not None:
            print("Ensuring SR model components are on CUDA for SR inference.")
            if not isinstance(model_test, OSEDiff_SD3_TEST_efficient):
                model.text_enc_1.to('cuda:0')
                model.text_enc_2.to('cuda:0')
                model.text_enc_3.to('cuda:0')
            # transformer and VAE should already be on CUDA per initialization
            model.transformer.to('cuda', dtype=torch.float32)
            model.vae.to('cuda', dtype=torch.float32)

        output_image = model_test(lq, prompt=validation_prompt)
        output_image = torch.clamp(output_image[0].cpu(), -1.0, 1.0)
        output_pil = transforms.ToPILImage()(output_image * 0.5 + 0.5)
        if coz_args.align_method == 'adain':
            output_pil = adain_color_fix(target=output_pil, source=current_sr_input_image_pil)
        elif coz_args.align_method == 'wavelet':
            output_pil = wavelet_color_fix(target=output_pil, source=current_sr_input_image_pil)

    return output_pil

sem = threading.Semaphore()

@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    seed = int(data.get('seed', random.randint(1, 2 ** 32 - 1)))
    zoom_level = int(data.get('zoom_level', 2))

    init_images = data.get('init_images', [])

    data["init_images"] = "[omitted " + str(len(init_images)) + "]"
    print(data, flush=True)
    if len(init_images) != 1:
        return jsonify({'error': "A single init image is required"}), 400
    image_data = base64.b64decode(init_images[0])
    image = Image.open(io.BytesIO(image_data))
    print("Resizing image")
    resized_image = resize_and_center_crop(image, 512)
    tmp_file = tempfile.NamedTemporaryFile(prefix="viktor89-chain-of-zoom-", suffix='.png')
    tmp_dir ='/tmp/viktor89-chain-of-zoom'
    if not os.path.isdir(tmp_dir):
        os.mkdir(tmp_dir)

    print("Writing resized image to " + tmp_file.file.name)
    resized_image.save(tmp_file.name)

    args_copy = coz_args_default
    args_copy.seed = seed
    args_copy.upscale = zoom_level
    sem.acquire()
    print(f'Zooming image with zoom level {zoom_level}...')
    try:
        image = generate_image(args_copy, tmp_file.file.name, tmp_dir)
    except Exception as e:
        print(traceback.format_exc(), flush=True)
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()
    buffered = BytesIO()
    image.save(buffered, format="PNG")
    image_base64 = base64.b64encode(buffered.getvalue()).decode('utf-8')
    response = {
        'images': [image_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [f'Seed: {seed}, Model: Chain-of-Zoom']
        })
    }
    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
