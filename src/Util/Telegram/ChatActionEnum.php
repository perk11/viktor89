<?php

namespace Perk11\Viktor89\Util\Telegram;

enum ChatActionEnum
{
    case typing;
    case upload_photo;
    case record_video;
    case upload_video;
    case record_voice;
    case upload_voice;
    case upload_document;
    case choose_sticker;
    case find_location;
    case record_video_note;
    case upload_video_note;
}
