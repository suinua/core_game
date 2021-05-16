<?php

namespace core_game\service;

use core_game\block\Nexus;
use core_game\scoreboard\CoreGameScoreboard;
use core_game\TypeList;
use game_chef\api\GameChef;
use game_chef\api\TeamGameBuilder;
use game_chef\models\Score;
use game_chef\models\Team;
use game_chef\models\TeamGame;
use game_chef\pmmp\bossbar\Bossbar;
use game_chef\pmmp\hotbar_menu\HotbarMenu;
use game_chef\pmmp\hotbar_menu\HotbarMenuItem;
use pocketmine\block\Bedrock;
use pocketmine\block\Block;
use pocketmine\entity\Attribute;
use pocketmine\item\ItemIds;
use pocketmine\level\particle\ExplodeParticle;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class CoreGameService
{
    private static TaskScheduler $scheduler;

    public static function setScheduler(TaskScheduler $scheduler): void {
        self::$scheduler = $scheduler;
    }

    public static function buildGame(string $mapName): void {
        $builder = new TeamGameBuilder();
        try {
            $builder->setNumberOfTeams(2);
            $builder->setGameType(TypeList::game());
            $builder->setTimeLimit(600);
            $builder->setVictoryScore(new Score(Nexus::MAX_HEALTH));
            $builder->setCanJumpIn(true);
            $builder->selectMapByName($mapName);

            $builder->setFriendlyFire(false);
            $builder->setMaxPlayersDifference(2);
            $builder->setCanMoveTeam(true);

            $game = $builder->build();
            GameChef::registerGame($game);
        } catch (\Exception $e) {
            Server::getInstance()->getLogger()->error($e->getMessage());
        }

    }

    public static function createGame(): void {
        $mapNames = GameChef::getTeamGameMapNamesByType(TypeList::game());
        if (count($mapNames) === 0) {
            throw new \LogicException(TypeList::game() . "に対応したマップを作成してください");
        }

        $mapName = $mapNames[rand(0, count($mapNames) - 1)];
        self::buildGame($mapName);
    }

    public static function sendToGame(Player $player, TeamGame $game): void {

        $levelName = $game->getMap()->getLevelName();
        $level = Server::getInstance()->getLevelByName($levelName);

        $player->teleport($level->getSpawnLocation());
        $player->teleport(Position::fromObject($player->getSpawn(), $level));

        //ボスバー
        $bossbar = new Bossbar($player, TypeList::bossbar(), "", 1.0);
        $bossbar->send();

        //スコアボード
        CoreGameScoreboard::send($player, $game);

        self::initPlayerStatus($player);

        $playerData = GameChef::findPlayerData($player->getName());
        $team = $game->getTeamById($playerData->getBelongTeamId());
        $player->sendMessage("あなたは" . $team->getTeamColorFormat() . $team->getName() . TextFormat::RESET . "です");
    }

    public static function backToLobby(Player $player): void {
        $level = Server::getInstance()->getDefaultLevel();
        $player->teleport($level->getSpawnLocation());
        $player->getAttributeMap()->getAttribute(Attribute::MOVEMENT_SPEED)->setValue(0.1);
        $player->removeAllEffects();

        $menu = new HotbarMenu($player, [
            new HotbarMenuItem(
                ItemIds::EMERALD,
                0,
                TextFormat::GREEN . "試合に参加",
                function (Player $player) {
                    self::randomJoin($player);
                }
            )
        ]);
        $menu->send();

        //ボスバー削除
        foreach (Bossbar::getBossbars($player) as $bossbar) {
            $bossbar->remove();
        }

        //スコアボード削除
        CoreGameScoreboard::delete($player);
    }

    public static function initPlayerStatus(Player $player): void {
        //インベントリ
        $player->getInventory()->setContents([
            //todo:インベントリ
        ]);
    }

    //参加できる試合を探し、参加するように
    public static function randomJoin(Player $player): void {
        $games = GameChef::getGamesByType(TypeList::game());
        if (count($games) === 0) {
            self::createGame();
        }

        //todo gamechefに前回のゲームIDとチームIDを記録し、それを使い再度参加する場合はそのチームにするように(負けたチームの場合その試合には参加できない)
        $games = GameChef::getGamesByType(TypeList::game());
        $game = $games[0];
        $team = $game->getTeams()[0];
        $result = GameChef::joinTeamGame($player, $game->getId(), $team->getId(), true);
        if (!$result) {
            $player->sendMessage("試合に参加できませんでした");
            return;
        }

        //2人以上なら試合開始
        if (count(GameChef::getPlayerDataList($game->getId())) >= 2) {
            GameChef::startGame($game->getId());
        }
    }

    static function breakNexus(TeamGame $teamGame, Team $targetTeam, Player $attacker, Vector3 $nexusPosition): void {
        $attackerData = GameChef::findPlayerData($attacker->getName());
        $attackerTeam = $teamGame->getTeamById($attackerData->getBelongTeamId());

        //メッセージと音
        $playerDataList = GameChef::getPlayerDataList($teamGame->getId());
        foreach ($playerDataList as $playerData) {
            $player = Server::getInstance()->getPlayer($playerData->getName());

            if ($player->distance($nexusPosition) <= 10) {
                //音:半径20m以下
                SoundService::play($player, $nexusPosition, "random.anvil_land", 50, 1);

            } else if ($playerData->getBelongTeamId()->equals($targetTeam->getId())) {
                //20m以上で破壊されてるコアのチームなら
                SoundService::play($player, $player->getPosition(), "note.pling", 50, 2);
            }

            //TIP
            if ($playerData->getBelongTeamId()->equals($targetTeam->getId())) {
                $player->sendTip($attackerTeam->getTeamColorFormat() . $attacker->getName() . TextFormat::RESET . "にコアを攻撃されています\nHP:" . (Nexus::MAX_HEALTH - $targetTeam->getScore()->getValue()));
            } else {
                $player->sendTip(
                    $attackerTeam->getTeamColorFormat() . $attacker->getName() . TextFormat::RESET . "が" . $targetTeam->getTeamColorFormat() . $targetTeam->getName() . TextFormat::RESET . "を攻撃中"
                );
            }
        }

        $isFinish = $targetTeam->getScore()->getValue() === (Nexus::MAX_HEALTH - 1);
        if ($isFinish) {
            $level = $attacker->getLevel();
            $level->setBlock($nexusPosition, new Bedrock());

            $i = 0;
            while ($i > 4) {
                $level->addParticle(new ExplodeParticle($nexusPosition));
                $i++;
            }
        } else {
            $level = $attacker->getLevel();
            self::$scheduler->scheduleDelayedTask(new ClosureTask(function (int $tick) use ($level, $nexusPosition) : void {
                $level->setBlock($nexusPosition, Block::get(Nexus::ID));
            }), 20 * 0.5);
        }

        GameChef::addTeamGameScore($teamGame->getId(), $targetTeam->getId(), new Score(1));
    }
}