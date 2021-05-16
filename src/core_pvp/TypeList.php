<?php


namespace core_game;


use game_chef\models\GameType;
use game_chef\pmmp\bossbar\BossbarType;

class TypeList
{
    static function bossbar(): BossbarType {
        return new BossbarType("CorePVP");
    }

    static function game(): GameType {
        return new GameType("CorePVP");
    }
}