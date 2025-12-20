import unsloth
from datasets import load_dataset
from transformers import TrainingArguments
from trl import SFTTrainer
from unsloth import FastLanguageModel
from unsloth import is_bfloat16_supported

max_seq_length = 8096  # Supports RoPE Scaling interally, so choose any!
# Get LAION dataset
# url = "https://huggingface.co/datasets/laion/OIG/resolve/main/unified_chip2.jsonl"
# dataset = load_dataset("json", data_files = {"train" : url}, split = "train")
dataset = load_dataset("json", data_files="/home/perk11/LLM/unsloth-train/20251220_siepatch-non-instruct5.jsonl", split="train")

model, tokenizer = FastLanguageModel.from_pretrained(
    # model_name = "unsloth/llama-3-8b-bnb-4bit",
    model_name="unsloth/llama-3-8b",
    # model_name = "unsloth/llama-3-8b-Instruct",
    max_seq_length=max_seq_length,
    dtype=None,
    load_in_4bit=False,
)

# Do model patching and add fast LoRA weights
model = FastLanguageModel.get_peft_model(
    model,
    r=128,
    target_modules=["q_proj", "k_proj", "v_proj", "o_proj",
                    "gate_proj", "up_proj", "down_proj", ],
    lora_alpha=128,
    lora_dropout=0,  # Supports any, but = 0 is optimized
    bias="none",  # Supports any, but = "none" is optimized
    # [NEW] "unsloth" uses 30% less VRAM, fits 2x larger batch sizes!
    use_gradient_checkpointing="unsloth",  # True or "unsloth" for very long context
    # use_gradient_checkpointing=False,
    random_state=3407,
    max_seq_length=max_seq_length,
    use_rslora=False,  # We support rank stabilized LoRA
    loftq_config=None,  # And LoftQ
)

trainer = SFTTrainer(
    model=model,
    train_dataset=dataset,
    dataset_text_field="text",
    max_seq_length=max_seq_length,
    tokenizer=tokenizer,
    args=TrainingArguments(
        per_device_train_batch_size=8,
        gradient_accumulation_steps=2,
        warmup_ratio=0.1,
        num_train_epochs=5,
        # max_steps=50,
        fp16=not is_bfloat16_supported(),
        bf16=is_bfloat16_supported(),
        logging_steps=1,
        output_dir="outputs",
        optim="adamw_8bit",
        seed=3407,
    ),
)
trainer.train(resume_from_checkpoint=True)
model.save_pretrained_gguf("llama3-20251220-siepatch-5epoch", tokenizer, quantization_method="q8_0",maximum_memory_usage = 0.3 )
model.save_pretrained_merged("llama3-20251220-siepatch-5epoch", tokenizer, save_method="merged_16bit", maximum_memory_usage = 0.3)
# model.save_pretrained_merged("model_4bit_siepatch", tokenizer, save_method = "merged_4bit_forced",)
# model.save_pretrained_merged("model_lora", tokenizer, save_method = "lora",)
# Go to https://github.com/unslothai/unsloth/wiki for advanced tips like
# (1) Saving to GGUF / merging to 16bit for vLLM
# (2) Continued training from a saved LoRA adapter
# (3) Adding an evaluation loop / OOMs
# (4) Cutomized chat templates


# python3 /home/perk11/LLM/llama.cpp/convert_lora_to_gguf.py --base-model-id unsloth/llama-3-8b --outtype q8_0 --outfile siepatch202512-step1000-q8_0.gguf outputs/checkpoint-1000
# /home/perk11/LLM/llama.cpp/build/bin/llama-server -m llama3-8b-q8_0.gguf -c 8192 --port 8080 --host 0.0.0.0 --lora /home/perk11/LLM/unsloth-train/siepatch202512-step1000-q8_0.gguf
#curl http://localhost:8080/v1/completions -H "Content-Type: application/json" -d '{"prompt":"<bot>: [Konstatin_Pereiaslov] Привет, ты кто?\n<bot>: [Nanak0n] я идиот, я пообещал что мы с ним скинем, а не скинем\n<bot>: [","max_tokens":512, "temperature":0.7, "stop":"<bot>"}' | jq -r '.choices[].text'
