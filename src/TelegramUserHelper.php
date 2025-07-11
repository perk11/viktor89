<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\User;

class TelegramUserHelper
{
    public static function fullNameWithIdAndUserName(User $user): string
    {
        $name = trim($user->getFirstName() . ' ' . $user->getLastName()) . ' (';
        if ($user->getUsername() !== null && $user->getUsername() !== '') {
            $name .= '@' . $user->getUsername() . ', ';
        }
        $name .= 'id' . $user->getId() . ')';

        return $name;
    }
}
