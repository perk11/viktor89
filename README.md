Viktor89 is a Telegram bot that is meant to work in a group chat setting and be trained on messages from the group chat.

## Requirements

php 8.3 (earlier versions might work too), php-sqlite3 extension, llama.cpp, Telegram account

## Running
1. Fine-tune your model to output format expected by a bot. Each version of the bot will expect different output format from the model.
For versions 4 and 5 expected format is:

  ```
<bot>: [Author 1] Hi
<bot>: [Author 2] Hello there!
  ```

  Check out scripts for converting Telegram history export and fine-tuning the model using [unsloth](https://github.com/unslothai/unsloth) in [train/](https://github.com/perk11/viktor89/tree/main/train) directory.

2. Create your Telegram bot with @BotFather
3. Copy .env.example to .env and fill it out.
4. Start llama.cpp:  ./server -m llama3-20240530_siepatch-non-instruct4_5epoch_q8_0-unsloth.Q8_0.gguf -fa -c 2048 -n 2048
5. [Download composer](https://getcomposer.org/download/) 
6. Run `php ./composer.phar install`
7. Run `php viktor89.php`
