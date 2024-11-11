<?php

namespace Perk11\Viktor89;

class PrintHelpProcessor implements MessageChainProcessor
{
    private const COMMANDS = [
        '/image' => 'Генераций изображения. Текст после команды будет использован в качестве запроса, например <code>/image a cute cat curling in a ball</code>.
Если команда использует в ответ на фото, то фото будет использоваться в качестве исходного изображения (img2img). Если модель не поддерживает img2img, бот поставит реакцию "🤔".
Большинство моделей поддерживают только английский язык. 
Занимает от 1 до 4 минут в зависимости от модели и настроек.',
        '/imagine' => 'Аналог /image, но текст вашего запроса будет отправлен LLM для улучшения и перевода на английский. Поддерживает большинство языков.',
        'Настройки для /image и /imagine:' => '
        /imagemodel Выбор модели для генерации изображения. Отправьте без параметров, чтобы увидеть список доступных моделей.
        /imagesize Размер изображения. Отправьте без параметров чтобы увидеть список доступных размеров. Не все модели одинаково хорошо справляются с разными размерами.
        /steps -Количество шагов для генерации изображения, например <code>/steps 20</code>. Чем больше шагов, тем дольше занимает генерация. Каждой модели нужно своё количество шагов. Чтобы сбросить значение отправьте <code>/steps</code> без параметров.
        /denoisingstrength Используется только для функции img2img. Принимает значения от 0 до 1, 0 оставит исходное фото без изменений, 1 сгенерирует полностью новое изображение. Например <code>/denoisingstrength 0.7</code>.
        /seed Первичное значение для псевдослучайного генератора. Например <code>/seed 123</code>. Позволяет повторно генерировать одно и то же изображение. Полезно для того чтобы экспериментировать с запросами, чтобы модель генерировала более похожие изображения. Отправьте /seed без параметров, чтобы сбросить на случайное значение.',
        '/upscale' => 'Улучшить качество фото с использование нейросети. Используйте в ответ на фото. Занимает от 3 до 5 минут.',
        '/downscale' => 'Ухудшить качество фото. Используйте в ответ на фото.',
        '/video' => 'Генерирация видео. Текст после команды будет использован в качестве запроса. Например: <code>/video A spaceship landing on Venus</code>. Если команда использует в ответ на фото, то фото будет использоваться в качестве первого кадра для видео. Поддерживается только английский язык. Занимает от 8 до 20 минут.',
        '/vid' => 'Аналог /video, но текст вашего запроса будет отправлен LLM для улучшения и перевода на английский. Первый кадр будет сгенерирован с помощью flux для улучшения качества. Поддерживает большинство языков.',
        'Настройки для /video и /vid:' => '
        /steps  Количество шагов для генерации видео. Чем больше шагов, тем дольше занимает генерация. Каждой модели нужно своё количество шагов. Чтобы сбросить значение отправьте <code>/steps</code> без параметров
        /videomodel Выбор модели для генерации видео. Отправьте без параметров, чтобы увидеть список доступных моделей.
        /img2videomodel Выбор модели для генерации видео из изображения. Отправьте без параметров, чтобы увидеть список доступных моделей.',
        '/say' => 'Текст после команды будет использован для генерации речи. Например: <code>/say Я Виктор89!</code>',
        '/transcribe' => 'Распознавание речи. Ответьте на голосовое сообщение, кружок, видео, или аудио чтобы распознать его.',
        '/clownon' => '<span class="tg-spoiler">Включить секретный режим клоуна.</span>',
        '/clownify' => 'Использовать в ответ на фото с человеческим лицом.',
        '/preferences' => 'Показать ваши настройки.',
        '/assistant' => 'Начать общение с LLM (а-ля ChatGPT). Чтобы продолжить общение, отвечайте на сообщения бота.',
        'Настройки для /assistant:' => '
        /assistantmodel Выбор модели LLM. Отправьте без параметров, чтобы увидеть список доступных моделей.
        /systemprompt Указание для LLM о том что он должен делать. Например: <code>/systemprompt You are a heartless and mean robot</code>. Значение по умолчанию: <code>Always respond in Russian</code>. Отправьте /systemprompt без параметров, чтобы сбросить на значение по умолчанию.
        /responsestart Начинать ответ LLM с указаного текста. Например: <code>/responsestart Да </code>. Позволяет направить ответ LLM в заданное русло. Отправьте /responsestart без параметров, чтобы сбросить на значение по умолчанию.
        /seed Первичное значение для псевдослучайного генератора. Например: <code>/seed 123</code>. Отправьте /seed без параметров, чтобы сбросить на случайное значение.',
    ];

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $message = InternalMessage::asResponseTo($messageChain->last());
        $message->messageText = "Привет, я Виктор89.\n
Пожалуйста не используйте меня для генерации чего-либо незаконного, а так же не пытайтесь меня перегрузить.\n
<b><u>Доступные команды</u></b>\n";
        $message->parseMode = 'HTML';
        foreach (self::COMMANDS as $command => $description) {
            if (mb_strlen($message->messageText) + mb_strlen($description) >= 4000) {
                $message->send();
                $message = InternalMessage::asResponseTo($messageChain->last(), '');
                $message->parseMode = 'HTML';
            }
            $message->messageText .= "<b>" . htmlentities($command) . "</b> $description\n\n";
        }

        $message->messageText .= htmlspecialchars('Все команды использующие нейросеть выполняются по очереди, а значит бот может не сразу приступить к исполнению вашего задания. Если бот поставил реакцию "👀", значит запрос получен.');
        $message->send();
        return new ProcessingResult(null, true);
    }
}
