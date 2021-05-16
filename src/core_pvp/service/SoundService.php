<?php


namespace core_game\service;


use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\Player;

class SoundService
{
    static function play(Player $player, Vector3 $pos, string $name, int $volume = 50, int $pitch = 3): void {
        $packet = new PlaySoundPacket();
        $packet->x = $pos->x;
        $packet->y = $pos->y;
        $packet->z = $pos->z;
        $packet->volume = $volume;
        $packet->pitch = $pitch;
        $packet->soundName = $name;
        $player->sendDataPacket($packet);
    }
}