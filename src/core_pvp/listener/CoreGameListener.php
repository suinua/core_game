<?php


namespace core_game\listener;

use core_game\block\Nexus;
use core_game\scoreboard\CoreGameScoreboard;
use core_game\service\CoreGameService;
use core_game\TypeList;
use game_chef\api\GameChef;
use game_chef\models\GameStatus;
use game_chef\models\Score;
use game_chef\pmmp\bossbar\Bossbar;
use game_chef\pmmp\events\AddScoreEvent;
use game_chef\pmmp\events\FinishedGameEvent;
use game_chef\pmmp\events\PlayerJoinGameEvent;
use game_chef\pmmp\events\PlayerKilledPlayerEvent;
use game_chef\pmmp\events\PlayerQuitGameEvent;
use game_chef\pmmp\events\StartedGameEvent;
use game_chef\pmmp\events\UpdatedGameTimerEvent;
use game_chef\services\MapService;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class CoreGameListener implements Listener
{
    private TaskScheduler $scheduler;

    public function __construct(TaskScheduler $scheduler) {
        $this->scheduler = $scheduler;
    }

    public function onJoin(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        CoreGameService::backToLobby($player);
    }

    public function onQuitGame(PlayerQuitGameEvent $event) {
        $player = $event->getPlayer();
        $gameType = $event->getGameType();
        if (!$gameType->equals(TypeList::game())) return;

        CoreGameService::backToLobby($player);
    }

    public function onUpdatedGameTimer(UpdatedGameTimerEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();
        if (!$gameType->equals(TypeList::game())) return;

        //ボスバーの更新
        foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
            $player = Server::getInstance()->getPlayer($playerData->getName());
            $bossbar = Bossbar::findByType($player, TypeList::bossbar());

            //ボスバーの無い試合 or バグ
            //ほぼ１００％前者なので処理を終わらせる
            if ($bossbar === null) return;

            if ($event->getTimeLimit() === null) {
                $bossbar->updateTitle("経過時間:({$event->getElapsedTime()})");
            } else {
                $bossbar->updateTitle("{$event->getElapsedTime()}/{$event->getTimeLimit()}");
                $bossbar->updatePercentage(1 - ($event->getElapsedTime() / $event->getTimeLimit()));
            }
        }
    }

    public function onStartedGame(StartedGameEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();
        if (!$gameType->equals(TypeList::game())) return;

        $game = GameChef::findTeamGameById($gameId);
        GameChef::setTeamGamePlayersSpawnPoint($gameId);

        foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
            $player = Server::getInstance()->getPlayer($playerData->getName());
            CoreGameService::sendToGame($player, $game);
        }
    }

    public function onFinishedGame(FinishedGameEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();
        if (!$gameType->equals(TypeList::game())) return;

        $winTeam = null;
        $availableTeamCount = 0;
        $game = GameChef::findGameById($gameId);
        foreach ($game->getTeams() as $team) {
            if ($team->getScore()->getValue() < Nexus::MAX_HEALTH) {
                $availableTeamCount++;
                $winTeam = $team;
            }
        }

        //2チーム以上残っていたら試合は終了しない
        if ($availableTeamCount >= 2) return;

        foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
            $player = Server::getInstance()->getPlayer($playerData->getName());
            if ($player === null) continue;

            $player->sendMessage($winTeam->getTeamColorFormat() . $winTeam->getName() . TextFormat::RESET . "の勝利！！！");
            $player->sendMessage("10秒後にロビーに戻ります");
        }

        $this->scheduler->scheduleDelayedTask(new ClosureTask(function (int $tick) use ($gameId) : void {
            //10秒間で退出する可能性があるから、foreachをもう一度書く
            //上で１プレイヤーずつタスクを書くこともできるが流れがわかりやすいのでこうしている
            foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
                $player = Server::getInstance()->getPlayer($playerData->getName());
                if ($player === null) continue;

                CoreGameService::backToLobby($player);
            }

            GameChef::discardGame($gameId);
        }), 20 * 10);
    }

    public function onPlayerJoinedGame(PlayerJoinGameEvent $event) {
        $player = $event->getPlayer();
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();
        $teamId = $event->getTeamId();
        if (!$gameType->equals(TypeList::game())) return;

        $game = GameChef::findGameById($gameId);
        $team = $game->getTeamById($teamId);

        //メッセージ
        foreach (GameChef::getPlayerDataList($gameId) as $gamePlayerData) {
            $gamePlayer = Server::getInstance()->getPlayer($gamePlayerData->getName());
            if ($gamePlayer === null) continue;
            $gamePlayer->sendMessage("{$player->getName()}が" . $team->getTeamColorFormat() . $team->getName() . TextFormat::RESET . "に参加しました");
        }

        $player->sendMessage($team->getTeamColorFormat() . $team->getName() . TextFormat::RESET . "に参加しました");

        //途中参加
        $game = GameChef::findTeamGameById($gameId);
        if ($game->getStatus()->equals(GameStatus::Started())) {
            CoreGameService::sendToGame($player, $game);
        }
    }

    public function onPlayerKilledPlayer(PlayerKilledPlayerEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();
        $attacker = $event->getAttacker();
        $killedPlayer = $event->getKilledPlayer();

        if (!$gameType->equals(TypeList::game())) return;
        if ($event->isFriendlyFire()) return;//試合の設定上ありえないけど

        $game = GameChef::findTeamGameById($gameId);

        $attackerData = GameChef::findPlayerData($attacker->getName());
        $attackerTeam = $game->findTeamById($attackerData->getBelongTeamId());

        $killedPlayerData = GameChef::findPlayerData($killedPlayer->getName());
        $killedPlayerTeam = $game->findTeamById($killedPlayerData->getBelongTeamId());

        //メッセージを送信
        $message = $attackerTeam->getTeamColorFormat() . "[{$attacker->getName()}]" . TextFormat::RESET .
            " killed" .
            $killedPlayerTeam->getTeamColorFormat() . " [{$killedPlayer->getName()}]";
        foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
            $gamePlayer = Server::getInstance()->getPlayer($playerData->getName());
            $gamePlayer->sendMessage($message);
        }
    }

    public function onAddedScore(AddScoreEvent $event) {
        $gameId = $event->getGameId();
        $gameType = $event->getGameType();
        if (!$gameType->equals(TypeList::game())) return;

        $game = GameChef::findTeamGameById($gameId);
        foreach (GameChef::getPlayerDataList($gameId) as $playerData) {
            $player = Server::getInstance()->getPlayer($playerData->getName());
            CoreGameScoreboard::update($player, $game);
        }
    }

    public function onPlayerDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();
        if (!GameChef::isRelatedWith($player, TypeList::game())) return;

        $playerData = GameChef::findPlayerData($player->getName());
        $game = GameChef::findGameById($playerData->getBelongGameId());
        $team = $game->getTeamById($playerData->getBelongTeamId());
        if ($team->getScore()->getValue() === Nexus::MAX_HEALTH) {
            GameChef::quitGame($player);
        } else {
            //スポーン地点を再設定
            GameChef::setTeamGamePlayerSpawnPoint($event->getPlayer());
        }
    }

    public function onPlayerReSpawn(PlayerRespawnEvent $event) {
        $player = $event->getPlayer();
        if (!GameChef::isRelatedWith($player, TypeList::game())) return;

        CoreGameService::initPlayerStatus($player);
    }

    public function onBreakNexus(BlockBreakEvent $event) {
        $block = $event->getBlock();
        if ($block->getId() !== Nexus::ID) return;

        $player = $event->getPlayer();
        $level = $player->getLevel();

        //試合中のマップじゃなかったら
        if (!MapService::isInstantWorld($level->getName())) return;

        //プレイヤーが試合に参加していなかったら
        $playerData = GameChef::findPlayerData($player->getName());
        if ($playerData->getBelongGameId() === null) {
            $event->setCancelled();
            return;
        }

        $game = GameChef::findGameById($playerData->getBelongGameId());

        //core game じゃなかったら
        if (!$game->getType()->equals(TypeList::game())) return;

        $targetTeam = null;
        foreach ($game->getTeams() as $team) {
            $teamNexusVector = $team->getCustomVectorData(Nexus::POSITION_DATA_KEY);
            if ($block->asVector3()->equals($teamNexusVector)) {
                $targetTeam = $team;
            }
        }

        if ($targetTeam === null) throw new \UnexpectedValueException("そのネクサスを持つチームが存在しませんでした");

        //自軍のネクサスだったら
        if ($targetTeam->getId()->equals($playerData->getBelongTeamId())) {
            $event->setCancelled();
            $player->sendTip("自軍のネクサスを破壊することはできません");
            return;
        }

        //すでに死んだチームなら(ネクサスを置き換えるからありえないけど)
        if ($targetTeam->getScore()->isBiggerThan(new Score(Nexus::MAX_HEALTH))) {
            $event->setCancelled();
            return;
        }

        CoreGameService::breakNexus($game, $targetTeam, $player, $block->asVector3());
    }
}